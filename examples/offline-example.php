<?php

declare(strict_types=1);

/**
 * cursor-walk offline example and CI smoke test.
 * It uses an in-memory fetcher and no network, so its output is deterministic and safe for CI.
 * Run it with: php examples/offline-example.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CursorWalk\CursorCodec;
use CursorWalk\Exception\PageBudgetExceededException;
use CursorWalk\Page;
use CursorWalk\Paginator;
use CursorWalk\Relay\ConnectionFormatter;
// Test fixtures, not public API. Only composer's "autoload-dev" loads them,
// so this example needs a dev install.
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

/** Not assert(): production php.ini sets zend.assertions = -1, which makes it a silent no-op. */
function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "ASSERTION FAILED: {$message}\n");
        exit(1);
    }
}

function shortCursor(?string $cursor): string
{
    if ($cursor === null) {
        return '(null)';
    }

    return strlen($cursor) > 16 ? substr($cursor, 0, 16) . '...' : $cursor;
}

/**
 * @param list<array{id: int, name: string}> $dataset
 */
function demoLazyItems(Paginator $paginator, array $dataset, int $pageSize, int $expectedPageCount): void
{
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
}

/**
 * @param list<array{id: int, name: string}> $dataset
 */
function demoEarlyBreak(Paginator $paginator, array $dataset, int $pageSize, int $expectedPageCount): void
{
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
}

/**
 * @param list<array{id: int, name: string}> $dataset
 */
function demoPageCheckpoints(Paginator $paginator, array $dataset, int $pageSize, int $expectedPageCount): void
{
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
            // A real job persists this durably, and only after the page's work has committed.
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
}

/**
 * @param list<array{id: int, name: string}> $dataset
 *
 * @return array{Page<array{id: int, name: string}>, array<string, mixed>}
 */
function demoRelayConnection(Paginator $paginator, array $dataset, int $pageSize): array
{
    printf("\n== 4. slice() + Relay\\ConnectionFormatter ==\n");

    $slicePage = $paginator->slice(new ArrayFetcher($dataset, $pageSize), 4);

    check($slicePage->count() === 4, 'slice($fetcher, 4) must return exactly 4 items, even though pages are size 3');
    check($slicePage->hasNextPage === true, 'the dataset has 10 items, so a 4-item slice must still report hasNextPage');

    $formatter = new ConnectionFormatter();
    $connection = $formatter->format(
        $slicePage,
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

    return [$slicePage, $connection];
}

/**
 * @param list<array{id: int, name: string}> $dataset
 * @param Page<array{id: int, name: string}> $slicePage
 * @param array<string, mixed>               $connection
 */
function demoCursorRoundTrip(Paginator $paginator, array $dataset, int $pageSize, Page $slicePage, array $connection): void
{
    printf("\n== 5. cursor round trip ==\n");

    // A mid-page edge, not a page boundary: a Relay client's `after` can point anywhere within a page.
    $midEdge = $connection['edges'][1];
    $alreadySeenIds = array_column(array_slice($slicePage->items, 0, 2), 'id');

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
}

/**
 * @param list<array{id: int, name: string}> $dataset
 */
function demoPageBudget(Paginator $paginator, array $dataset, int $pageSize): void
{
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
}

// Rows rather than scalars, so the nodeMapper in section 4 has fields to shape.
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
$pageSize = 3;
$expectedPageCount = (int) ceil(count($dataset) / $pageSize);

$paginator = new Paginator();

printf("cursor-walk offline example — %d items, page size %d\n", count($dataset), $pageSize);

demoLazyItems($paginator, $dataset, $pageSize, $expectedPageCount);
demoEarlyBreak($paginator, $dataset, $pageSize, $expectedPageCount);
demoPageCheckpoints($paginator, $dataset, $pageSize, $expectedPageCount);
[$slicePage, $connection] = demoRelayConnection($paginator, $dataset, $pageSize);
demoCursorRoundTrip($paginator, $dataset, $pageSize, $slicePage, $connection);
demoPageBudget($paginator, $dataset, $pageSize);

printf("\nAll checks passed.\n");
exit(0);
