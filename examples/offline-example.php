<?php

declare(strict_types=1);

/**
 * cursor-walk: offline example / CI smoke test.
 *
 * Zero network calls. Everything here runs against an in-memory fetcher, so
 * the output is fully deterministic and this script is safe to run in CI
 * (see .github/workflows/ci.yml) as a smoke test on top of the unit suite.
 *
 * It walks through the library end to end:
 *   1. Paginator::items()   — lazy item iteration
 *   2. early break          — proving laziness (no over-fetching)
 *   3. Paginator::pages()   — per-page checkpointing
 *   4. Paginator::slice()   + Relay\ConnectionFormatter — the GraphQL resolver flow
 *   5. CursorCodec          — decoding a client-facing edge cursor and resuming
 *   6. Paginator(maxPages)  — the page-budget safety guard, and resuming past it
 *
 * Run it with: php examples/offline-example.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CursorWalk\CursorCodec;
use CursorWalk\Exception\PageBudgetExceededException;
use CursorWalk\Paginator;
use CursorWalk\Relay\ConnectionFormatter;
// ArrayFetcher and CountingFetcher are test fixtures, not part of the public
// library API. They live under tests/Support and are autoloaded only because
// composer's "autoload-dev" (CursorWalk\Tests\ -> tests/) is installed along
// with the rest of the dev dependencies. They double as the canonical example
// of, respectively, "the two things every fetcher must do" and "how to spy on
// fetchPage() calls to prove laziness" — see their docblocks.
use CursorWalk\Tests\Support\ArrayFetcher;
use CursorWalk\Tests\Support\CountingFetcher;

if (!class_exists(ArrayFetcher::class) || !class_exists(CountingFetcher::class)) {
    fwrite(
        STDERR,
        "This example needs the test fixtures from composer's \"autoload-dev\".\n"
        . "Run \"composer install\" (with dev dependencies included, i.e. without\n"
        . "--no-dev) from the project root, then try again.\n",
    );
    exit(1);
}

/**
 * Fails loudly instead of using assert(), which is disabled by default under
 * production php.ini settings (zend.assertions = -1) and would silently no-op.
 */
function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "ASSERTION FAILED: {$message}\n");
        exit(1);
    }
}

/** Shortens an opaque cursor for readable printing. Display-only; never decode a truncated cursor. */
function shortCursor(?string $cursor): string
{
    if ($cursor === null) {
        return '(null)';
    }

    return strlen($cursor) > 16 ? substr($cursor, 0, 16) . '...' : $cursor;
}

// A small, deterministic dataset. Arrays (rather than scalars) make the
// nodeMapper demo in section 4 realistic: this is the shape a database row or
// an API resource usually has.
$dataset = [
    ['id' => 0, 'name' => 'apple'],
    ['id' => 1, 'name' => 'banana'],
    ['id' => 2, 'name' => 'cherry'],
    ['id' => 3, 'name' => 'date'],
    ['id' => 4, 'name' => 'elderberry'],
    ['id' => 5, 'name' => 'fig'],
    ['id' => 6, 'name' => 'grape'],
    ['id' => 7, 'name' => 'honeydew'],
    ['id' => 8, 'name' => 'kiwi'],
    ['id' => 9, 'name' => 'lemon'],
];
$pageSize = 3; // 10 items / 3 per page = 4 pages: sizes 3, 3, 3, 1.
$expectedPageCount = (int) ceil(count($dataset) / $pageSize);

$paginator = new Paginator();

printf("cursor-walk offline example — %d items, page size %d\n", count($dataset), $pageSize);

// == 1. items(): lazy iteration ==================================================
printf("\n== 1. items(): lazy iteration ==\n");

$counting = new CountingFetcher(new ArrayFetcher($dataset, $pageSize));
$seenNames = [];

foreach ($paginator->items($counting) as $item) {
    $seenNames[] = $item['name'];
}

printf("items: %s\n", implode(', ', $seenNames));
printf("fetched %d upstream page(s) for %d item(s)\n", $counting->callCount(), count($seenNames));

check(count($seenNames) === count($dataset), 'items() should yield every item in the dataset exactly once');
check($counting->callCount() === $expectedPageCount, 'items() should make exactly one fetchPage() call per page');

// == 2. early break: proving laziness ============================================
printf("\n== 2. early break: proving laziness ==\n");

$countingForBreak = new CountingFetcher(new ArrayFetcher($dataset, $pageSize));
$taken = 0;

foreach ($paginator->items($countingForBreak) as $item) {
    $taken++;
    if ($taken >= 2) {
        break;
    }
}

printf(
    "took %d item(s), stopped early — %d upstream page(s) fetched (not %d)\n",
    $taken,
    $countingForBreak->callCount(),
    $expectedPageCount,
);

check($countingForBreak->callCount() === 1, 'breaking after 2 items (within the first page) must not fetch a second page');

// == 3. pages(): per-page checkpointing ==========================================
printf("\n== 3. pages(): per-page checkpointing ==\n");

$pageCount = 0;
$checkpointAfterFirstPage = null;

foreach ($paginator->pages(new ArrayFetcher($dataset, $pageSize)) as $page) {
    $pageCount++;
    printf(
        "page %d: %d item(s), hasNextPage=%s, endCursor=%s\n",
        $pageCount,
        $page->count(),
        $page->hasNextPage ? 'true' : 'false',
        shortCursor($page->endCursor),
    );

    if ($pageCount === 1) {
        // A real batch job would persist this in durable storage (a row, a
        // key-value store, a queue message) right here, only after the page's
        // work has been committed. We just hold it in a variable.
        $checkpointAfterFirstPage = $page->endCursor;
    }
}

check($pageCount === $expectedPageCount, 'pages() should yield exactly one Page per upstream page');

printf("-- resuming pages() from the checkpoint after page 1 --\n");
$resumedPageCount = 0;

foreach ($paginator->pages(new ArrayFetcher($dataset, $pageSize), $checkpointAfterFirstPage) as $page) {
    $resumedPageCount++;
    printf(
        "resumed page %d: %d item(s), hasNextPage=%s\n",
        $resumedPageCount,
        $page->count(),
        $page->hasNextPage ? 'true' : 'false',
    );
}

check(
    $resumedPageCount === $expectedPageCount - 1,
    'resuming from the checkpoint after page 1 should yield the remaining pages, no more and no fewer',
);

// == 4. slice() + Relay\ConnectionFormatter: the GraphQL resolver flow ===========
printf("\n== 4. slice() + Relay\\ConnectionFormatter ==\n");

$slicePage = $paginator->slice(new ArrayFetcher($dataset, $pageSize), 4);

check($slicePage->count() === 4, 'slice($fetcher, 4) must return exactly 4 items, even though pages are size 3');
check($slicePage->hasNextPage === true, 'the dataset has 10 items, so a 4-item slice must still report hasNextPage');

$formatter = new ConnectionFormatter();
$connection = $formatter->format(
    $slicePage,
    // The nodeMapper is where you shape a raw row into the public GraphQL
    // node — renaming/casting fields, dropping internal ones, etc.
    static fn (array $row): array => [
        'id' => (string) $row['id'],
        'label' => strtoupper($row['name']),
    ],
);

echo json_encode($connection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

check(
    array_key_exists('edges', $connection) && array_key_exists('pageInfo', $connection),
    'a formatted connection must have "edges" and "pageInfo" keys',
);

// == 5. cursor round trip: resuming from a client-facing edge cursor ============
printf("\n== 5. cursor round trip ==\n");

// Pick a cursor from the MIDDLE of the slice (index 1 of 4), not a page
// boundary — this is the case that matters: a Relay client can send back
// `after` pointing anywhere within a page, not just at its edges.
$midEdge = $connection['edges'][1];
$alreadySeenIds = array_column(array_slice($slicePage->items, 0, 2), 'id'); // items at index 0 and 1

printf(
    "resuming after edge for '%s' (cursor %s)\n",
    $midEdge['node']['label'],
    shortCursor($midEdge['cursor']),
);

$codec = new CursorCodec();
[$pageCursor, $skip] = $codec->decode($midEdge['cursor']);

$resumedIds = [];
foreach ($paginator->items(new ArrayFetcher($dataset, $pageSize), $pageCursor, $skip) as $item) {
    $resumedIds[] = $item['id'];
}

printf("resumed ids: %s\n", implode(', ', $resumedIds));

check(
    array_intersect($resumedIds, $alreadySeenIds) === [],
    'resuming from a mid-page edge cursor must not re-yield items already seen before that edge',
);
check(
    count($resumedIds) === count($dataset) - 2,
    'resuming after the 2nd item must yield exactly the remaining 8 items',
);

// == 6. safety guard: PageBudgetExceededException ================================
printf("\n== 6. safety guard: page budget ==\n");

$boundedPaginator = new Paginator(maxPages: 2);
$budgetFetcher = new ArrayFetcher($dataset, $pageSize);
$budgetExceptionCaught = false;
$pagesBeforeBudget = 0;
$idsBeforeBudget = [];
$lastCursor = null;

try {
    foreach ($boundedPaginator->pages($budgetFetcher) as $page) {
        $pagesBeforeBudget++;
        foreach ($page->items as $item) {
            $idsBeforeBudget[] = $item['id'];
        }
    }
} catch (PageBudgetExceededException $e) {
    $budgetExceptionCaught = true;
    $lastCursor = $e->getLastCursor();
    printf(
        "budget of %d page(s) exceeded after %d page(s); resuming from cursor %s\n",
        $e->getMaxPages(),
        $pagesBeforeBudget,
        shortCursor($lastCursor),
    );
}

check($budgetExceptionCaught, 'a Paginator(maxPages: 2) walking a 4-page dataset must throw PageBudgetExceededException');
check($pagesBeforeBudget === 2, 'the budget should allow exactly maxPages pages through before throwing');

// Resume, with an unbounded paginator, from exactly where the budget stopped.
$idsAfterResume = [];
foreach ($paginator->pages(new ArrayFetcher($dataset, $pageSize), $lastCursor) as $page) {
    foreach ($page->items as $item) {
        $idsAfterResume[] = $item['id'];
    }
}

$allIds = [...$idsBeforeBudget, ...$idsAfterResume];
sort($allIds);

printf("total items recovered across both runs: %d\n", count($allIds));

check(
    $allIds === range(0, count($dataset) - 1),
    'resuming from the budget checkpoint must yield the remaining items exactly once each, with no gaps or duplicates',
);

printf("\nAll checks passed.\n");
exit(0);
