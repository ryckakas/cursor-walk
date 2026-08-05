# cursor-walk

**The fetching half of cursor pagination.** `cursor-walk` walks a paginated upstream — a REST
API, a GraphQL API, a DynamoDB scan, anything with "here is a page, here is a cursor for the
next one" — and hands you a lazy stream of items or pages. You implement one method,
`fetchPage(?string $cursor): Page`; the library owns the loop: cursor threading, laziness (one
upstream call per page, made only when iteration crosses a page boundary), resumable
checkpoints, infinite-loop and page-budget guards, and exact-N slices that honor a Relay
`first` argument. It is framework-agnostic, has zero runtime dependencies, and ships an
optional Relay connection formatter so the output drops straight into a GraphQL resolver.

[![CI](https://github.com/ryckakas/cursor-walk/actions/workflows/ci.yml/badge.svg)](https://github.com/ryckakas/cursor-walk/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/ryckakas/cursor-walk.svg)](https://packagist.org/packages/ryckakas/cursor-walk)
[![PHP Version](https://img.shields.io/packagist/php-v/ryckakas/cursor-walk.svg)](https://packagist.org/packages/ryckakas/cursor-walk)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg)](https://phpstan.org/)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

> Code snippets below omit `declare(strict_types=1);` for brevity. The library itself declares
> it in every file, and so should your fetchers.

---

## The problem

A GraphQL resolver needs to return widgets. The widgets live behind a third-party REST API that
returns 100 at a time with an opaque `next_cursor`. The obvious implementation is a `while`
loop that appends every page into one array and then slices it — which means an unbounded
number of HTTP calls and the entire upstream dataset in memory before the first byte of the
response is written. If the client asked for 25 items, you fetched 40,000.

The loop itself is also more subtle than it looks. It has to decide when to stop, what to do
when the upstream returns a page that claims `has_more: true` but no cursor, what to do when
the same cursor comes back twice (an upstream bug that loops forever), how to resume a batch
job that died halfway, and how to turn a page-level cursor into the per-edge cursors a Relay
client will send back as `after`. Every codebase that talks to a paginated API rewrites this,
slightly differently, usually without the guards.

`cursor-walk` is that loop, written once:

```php
foreach ($paginator->items($fetcher) as $widget) {
    // Page 2 has not been fetched yet. It will be, when you reach item 101.
}
```

---

## Install

```bash
composer require ryckakas/cursor-walk
```

Requires PHP 8.2+. No runtime dependencies.

---

## Quick start

Implement `PaginatedFetcher`. That is the only integration point: translate one upstream
response into one `Page`. Everything else is the library's job.

```php
use CursorWalk\Exception\MalformedPageException;
use CursorWalk\Page;
use CursorWalk\PaginatedFetcher;
use CursorWalk\Paginator;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/** @implements PaginatedFetcher<array{id: int, name: string}> */
final class WidgetFetcher implements PaginatedFetcher
{
    // The fetcher owns transport — the library never makes a request itself.
    // Any PSR-18 client fits here: Guzzle, Symfony HttpClient, or your
    // framework's wrapper around one.
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requestFactory,
    ) {
    }

    public function fetchPage(?string $cursor): Page
    {
        $url = 'https://api.example.com/widgets?limit=100'
            . ($cursor === null ? '' : '&after=' . urlencode($cursor));

        $response = $this->http->sendRequest($this->requestFactory->createRequest('GET', $url));

        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
            throw MalformedPageException::invalidEnvelope('missing "data" key', $cursor, $body);
        }

        return new Page(
            items: array_values($data['data']),
            endCursor: $data['next_cursor'] ?? null,
            hasNextPage: (bool) ($data['has_more'] ?? false),
            totalCount: $data['total'] ?? null,
        );
    }
}

$paginator = new Paginator();
$fetcher = new WidgetFetcher($httpClient, $requestFactory); // your PSR-18 client + PSR-17 factory

foreach ($paginator->items($fetcher) as $widget) {
    echo $widget['name'], PHP_EOL;
}
```

Upstream paginated by page number or row offset instead of an opaque cursor? Extend one of the
base classes in `CursorWalk\Offset\` and implement `fetchAt()` instead — see
[Page-numbered and offset upstreams](#page-numbered-and-offset-upstreams).

### The three methods

`Paginator` is stateless and safe to reuse. It has exactly three entry points:

| Method | Returns | Use it for |
| --- | --- | --- |
| `items($fetcher, ?string $pageCursor = null, int $skip = 0)` | `Generator<int, T>` | Streaming every item. One fetch per page, on demand. |
| `pages($fetcher, ?string $startCursor = null)` | `Generator<int, Page<T>>` | Batch jobs — per-page transaction, retry, and checkpoint boundaries. |
| `slice($fetcher, int $first, ?string $pageCursor = null, int $skip = 0)` | `Page<T>` | Eagerly assembling *exactly* N items — the Relay `first` argument. |

### The page budget

**`new Paginator()` is bounded at 10,000 pages.** When that budget is exhausted the paginator
throws `PageBudgetExceededException`, which carries `getLastCursor()` so you can resume.

The budget exists because repeated-cursor detection cannot catch every runaway loop: an
upstream that hands back a *fresh* cursor with `hasNextPage: true` forever looks perfectly
well-behaved page by page. Without a budget, one bad deployment upstream turns a resolver into
an infinite request.

Pick a budget that matches the caller, not the dataset:

```php
$interactive = new Paginator(maxPages: 50);     // an API request; fail fast
$batch       = new Paginator(maxPages: 100_000); // a nightly sync; still bounded
$unbounded   = new Paginator(maxPages: null);    // opt out entirely — you own the risk
```

Budget exhaustion is *policy* (you set a limit, you hit it). A repeated cursor is a *bug*
(`PaginationLoopException`). They are separate classes so you can resume one and page someone
about the other.

---

## Recipes

### GraphQL resolver returning a Relay connection

The full resolver flow is: `$after` → `CursorCodec::decode()` → `Paginator::slice()` →
`ConnectionFormatter::format()`. `slice()` assembles exactly `$first` items across as many
upstream fetches as it takes, and reports a `hasNextPage` and an `endCursor` that describe the
*actual* end position — even if that lands in the middle of an upstream page.

<details>
<summary>Show the full resolver</summary>

```php
use CursorWalk\CursorCodec;
use CursorWalk\Paginator;
use CursorWalk\Relay\ConnectionFormatter;

final class WidgetsResolver
{
    private Paginator $paginator;
    private CursorCodec $codec;
    private ConnectionFormatter $formatter;

    public function __construct(private readonly WidgetFetcher $fetcher)
    {
        // A resolver is not a batch job. Cap the walk hard: with an upstream page
        // size of 100, 50 pages is 5,000 items — far more than any sane `first`.
        $this->paginator = new Paginator(maxPages: 50);
        $this->codec = new CursorCodec();
        $this->formatter = new ConnectionFormatter();
    }

    /**
     * @param array{first?: int, after?: string} $args
     * @return array<string, mixed>
     */
    public function __invoke(mixed $root, array $args): array
    {
        $first = min($args['first'] ?? 25, 100);

        [$pageCursor, $skip] = isset($args['after'])
            ? $this->codec->decode($args['after'])
            : [null, 0];

        $page = $this->paginator->slice($this->fetcher, $first, $pageCursor, $skip);

        // $pageStartCursor anchors the per-edge cursors to where this slice
        // began. Passing the encoded ($pageCursor, $skip) the request itself
        // decoded from `after` is what makes the next edge cursor round-trip
        // correctly; omitting it only happens to work for the very first page.
        return $this->formatter->format(
            $page,
            static fn (array $widget): array => [
                'id' => (string) $widget['id'],
                'name' => $widget['name'],
            ],
            $this->codec->encode($pageCursor, $skip),
        );
    }
}
```

</details>

`format()` returns a plain array in Relay Connection shape — no GraphQL library required.

<details>
<summary>Show the output shape</summary>

```php
$connection = [
    'edges' => [
        ['node' => ['id' => '1', 'name' => 'Sprocket'], 'cursor' => 'eyJ2IjoxLCJj...'],
        // ...
    ],
    'pageInfo' => [
        'endCursor' => 'eyJ2IjoxLCJj...',
        'hasNextPage' => true,
        'startCursor' => 'eyJ2IjoxLCJj...',
        // true whenever the page was fetched from a position other than the
        // origin — see the note below.
        'hasPreviousPage' => false,
    ],
    'totalCount' => 4213, // sibling key, present only when the Page carried one
];
```

</details>

`hasPreviousPage` is `true` exactly when the `$pageStartCursor` you passed describes a position
other than the origin — a page anchor, or a non-zero offset into the first page. Both are proof
that something precedes the window, and the Relay spec permits reporting `true` whenever the
server can determine that efficiently. It is still not backward pagination: there is no way to
travel backwards, only an honest answer about where this window sits. Omit `$pageStartCursor` and
the page is positioned as though it began the stream, so the flag comes back `false`.

Per-edge cursors come from an injectable `EdgeCursorStrategy`. The default,
`OffsetEdgeCursorStrategy`, encodes `(page cursor, offset within page)` via `CursorCodec` — see
[Cursor stability](#cursor-stability) for what that guarantees and what it does not. If your
upstream exposes item-level cursors, pass your own strategy.

<details>
<summary>Show a custom edge cursor strategy</summary>

```php
use CursorWalk\Page;
use CursorWalk\Relay\ConnectionFormatter;
use CursorWalk\Relay\EdgeCursorStrategy;

final class UpstreamItemCursorStrategy implements EdgeCursorStrategy
{
    public function cursorForEdge(Page $page, int $index): string
    {
        /** @var array{cursor: string} $item */
        $item = $page->items[$index];

        return $item['cursor'];
    }
}

$formatter = new ConnectionFormatter(new UpstreamItemCursorStrategy());
```

</details>

### Per-page checkpointing with `pages()`

`pages()` yields whole `Page` objects, which makes each page a natural transaction, retry, and
checkpoint boundary — the shape batch jobs and workflow engines want.

<details>
<summary>Show a checkpointed batch loop</summary>

```php
use CursorWalk\Paginator;

$paginator = new Paginator(maxPages: 100_000);
$cursor = $checkpoints->load('widget-sync'); // ?string; null on the first run

foreach ($paginator->pages($fetcher, $cursor) as $page) {
    $db->transaction(static function () use ($page): void {
        foreach ($page->items as $widget) {
            $db->upsert('widgets', $widget);
        }
    });

    // Commit the position only after the page is durably processed. If the process
    // dies here, the next run replays this page — at-least-once, never skipped.
    $checkpoints->save('widget-sync', $page->endCursor);
}

$checkpoints->clear('widget-sync');
```

</details>

Note that `pages()` yields empty mid-stream pages as-is (`items: []`, `hasNextPage: true`).
That is a legal, real-world page — DynamoDB filtered `Query`/`Scan` and any upstream that
post-filters a page after slicing it produce them. `items()` skips over them transparently; if
your upstream should never produce one, see
[Handling malformed upstream pages](#handling-malformed-upstream-pages).

### Resuming with `$startCursor`

Every `endCursor` you have seen is a valid resume point. Feed it back as `$startCursor` to
`pages()` (or `$pageCursor` to `items()` / `slice()`) and the walk continues from there — the
first `fetchPage()` call receives exactly that cursor.

This is also how you recover from an exhausted page budget. `PageBudgetExceededException`
carries the last cursor precisely so that a bounded walk can be continued rather than restarted.

<details>
<summary>Show a budget-aware resume loop</summary>

```php
use CursorWalk\Exception\PageBudgetExceededException;
use CursorWalk\Paginator;

$paginator = new Paginator(maxPages: 500);
$cursor = $checkpoints->load('widget-sync');

while (true) {
    try {
        foreach ($paginator->pages($fetcher, $cursor) as $page) {
            process($page);
            $cursor = $page->endCursor;
            $checkpoints->save('widget-sync', $cursor);
        }

        break; // Walked to the end of the upstream.
    } catch (PageBudgetExceededException $e) {
        // Policy, not a bug: 500 pages is one work unit. Bank the position and
        // hand the rest to the next unit (re-queue the job, yield to the scheduler...).
        $cursor = $e->getLastCursor();
        $checkpoints->save('widget-sync', $cursor);
        $logger->info('budget reached, requeueing', ['maxPages' => $e->getMaxPages()]);
    }
}
```

</details>

To resume from a *synthetic edge cursor* — one you handed to a client — decode it first, and
pass both halves:

```php
use CursorWalk\CursorCodec;

[$pageCursor, $skip] = (new CursorCodec())->decode($after);

foreach ($paginator->items($fetcher, $pageCursor, $skip) as $widget) {
    // Resumes at the exact item after the one that cursor pointed at.
}
```

`decode()` never throws. A cursor it does not recognise — an opaque cursor straight from the
upstream, for instance — passes through unchanged as `[$after, 0]`, so raw upstream cursors and
synthetic edge cursors are interchangeable at the API boundary. The one exception is the empty
string, which decodes to `[null, 0]` — the first page — so `$codec->decode($args['after'] ?? '')`
does the right thing when the argument is absent.

### Page-numbered and offset upstreams

`?page=2&per_page=50` and `?offset=100&limit=50` are the most common shapes in B2B REST APIs,
and they need no special engine mode: `fetchPage(?string $cursor)` never says what a cursor
*means*, so a page number is a perfectly legal opaque one. Two base classes in
`CursorWalk\Offset\` supply the boilerplate — cursor parse and format, terminal-condition
derivation, and the off-by-one when the last page happens to be exactly full:

```php
use CursorWalk\Offset\OffsetPage;
use CursorWalk\Offset\PageNumberFetcher;

/** @extends PageNumberFetcher<array<string, mixed>> */
final class ContactsFetcher extends PageNumberFetcher
{
    public function __construct(private readonly HttpClient $http)
    {
        parent::__construct(pageSize: 50);
    }

    protected function fetchAt(int $position, int $pageSize): OffsetPage
    {
        $body = $this->http->getJson('/contacts', ['page' => $position, 'per_page' => $pageSize]);

        // Report what the envelope told you; the base class derives hasNextPage
        // and the next cursor from it.
        return new OffsetPage(
            $body['data'],
            totalItems: $body['meta']['total'] ?? null,
            totalPages: $body['meta']['totalPages'] ?? null,
        );
    }
}
```

That is the whole integration. `items()`, `pages()`, `slice()` and `ConnectionFormatter` all work
over it unchanged, and the cursors are plain integer strings — human-debuggable, and passed
through untouched by `CursorCodec::decode()` as foreign cursors, so edge-cursor round-tripping in
a resolver keeps working.

Use `OffsetFetcher` instead when the upstream accepts raw offsets. Its `$position` is a row
offset rather than a page number, and that makes its cursors **independent of the page size** —
whereas `"3"` from a `PageNumberFetcher` only means anything relative to the size it was produced
with.

> **The loop guard is inert for these upstreams.** Positions increase monotonically, so they
> never repeat, and `PaginationLoopException` can therefore never fire for a `PageNumberFetcher`.
> What replaces it is stronger where it exists: a reported `totalPages` or `totalItems` catches a
> runaway envelope on the very page that produced it, rather than one page later. When the
> envelope reports **neither**, the only remaining terminal condition is "a short page ends the
> walk" — and the only thing standing between you and an upstream that claims a full page forever
> is [the page budget](#the-page-budget). Keep it on, and size it deliberately.

<details>
<summary>Show the terminal conditions, and the page-size trap</summary>

`OffsetPage::hasMoreAfter()` picks the strongest signal the envelope gave it:

| upstream reports | terminal condition |
| --- | --- |
| `totalPages` | `$pageNumber < $totalPages` |
| `totalItems` only | `$itemsThrough < $totalItems` |
| neither | a page shorter than `$pageSize` is the last one |

The third rule costs one wasted round trip when the final page happens to be exactly full: the
next fetch comes back empty and ends the walk. A wasted call, never a missed row.

Because `hasNextPage` is *derived* rather than read from the envelope, the inconsistency a
hand-rolled guard would look for — an envelope claiming more data past its own `totalPages` —
is definitionally impossible here. The redundant degree of freedom is gone rather than guarded.

**Never derive a `PageNumberFetcher`'s `$pageSize` from a per-request Relay `first`.** A resolver
that does silently resumes returned cursors at the wrong window the moment a client asks for a
different size. That is what `slice()` is for: keep the fetcher's chunk size fixed and let
`slice()` cut a variable window out of it. The constructor takes `$pageSize` with no default so
that the choice is always deliberate.

A 0-indexed upstream needs no separate class and no flag — request `$position - 1` inside
`fetchAt()` and leave the walk 1-based.

</details>

### Driving the walk yourself (workflow engines, event loops)

**Use `Paginator` unless you cannot.** The one case it cannot serve is a caller that is unable to
let the library call `fetchPage()` at all: a Temporal workflow, where every fetch must go through
an activity `yield`, or an event loop where it must go through a promise. A `PaginatedFetcher`
cannot yield.

`Walk` is the same policy — budget, cursor threading, loop detection, termination — as a stepper
you drive. `Paginator::pages()` is itself a driver over it, so the two cannot drift.

```php
use CursorWalk\Page;
use CursorWalk\Walk;

$walk = new Walk($dto->resumeCursor, maxPages: 500);

while ($walk->hasNext()) {
    $result = yield $this->activity->fetchContacts($dto, $walk->nextCursor());

    yield from $this->processPage($result);

    // Reconstruct rather than marshal: payload converters instantiate via
    // reflection and bypass Page's constructor, which is the only enforcement
    // point of its legal states. Rebuilding it here re-runs that validation on
    // the side where the walk needs it to hold.
    $walk->advance(new Page($result->items, $result->endCursor, $result->hasNextPage));
}
```

`nextCursor()` then `advance()`, once each, in that order, until `hasNext()` reports false. Any
other order is a driver bug and throws `\LogicException` — deliberately *not* a
`CursorWalkException`, because a driver misusing the stepper is neither an upstream bug nor a
policy limit.

Replay safety follows from where the state comes from: everything `Walk` knows derives from the
`Page` values handed to `advance()`, which a replaying engine reproduces identically. One walk
means one `Walk` instance — it is single-use, must never be shared between concurrent walks, and
must never be registered as a service. `Paginator` remains the thing you inject.

There are deliberately no convenience methods on `Walk`. No `items()`, no `slice()`. If you want
those, you want `Paginator`.

> A guard exception thrown inside workflow code is yours to convert into whatever your engine
> treats as non-retryable (an `ApplicationFailure`, for Temporal). Left alone, it will be retried
> forever.

### Handling malformed upstream pages

`Page` rejects the one genuinely unusable combination at construction time: `hasNextPage: true`
with a null or empty `endCursor` — a page claiming there is more data while withholding the
only means of reaching it. That throws `MalformedPageException` from inside your fetcher, where
you still have the raw payload to attach.

Everything else is a judgment call your fetcher is better placed to make than the engine is.
The library ships no strictness flags; it gives you an exception with debug context and gets
out of the way.

<details>
<summary>Show a fetcher that enforces its own strictness</summary>

```php
use CursorWalk\Exception\MalformedPageException;
use CursorWalk\Page;
use CursorWalk\PaginatedFetcher;

/** @implements PaginatedFetcher<array{id: int}> */
final class StrictWidgetFetcher implements PaginatedFetcher
{
    // $client is your HTTP client — Guzzle, a PSR-18 implementation, whatever you
    // already have. The fetcher owns transport; the library never touches it.
    public function __construct(private readonly object $client)
    {
    }

    public function fetchPage(?string $cursor): Page
    {
        /** @var string $raw */
        $raw = $this->client->get('/widgets', ['after' => $cursor]);
        $data = json_decode($raw, true);

        if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
            throw MalformedPageException::invalidEnvelope('no "items" array', $cursor, $raw);
        }

        $items = array_values($data['items']);
        $hasNextPage = (bool) ($data['has_more'] ?? false);

        // Fetcher policy: THIS upstream never legitimately returns an empty page
        // mid-stream, so one means the response was truncated or the gateway lied.
        // The engine treats empty mid-stream pages as valid; strictness lives here.
        if ($items === [] && $hasNextPage) {
            throw MalformedPageException::invalidEnvelope('empty mid-stream page', $cursor, $raw);
        }

        return new Page($items, $data['cursor'] ?? null, $hasNextPage);
    }
}
```

</details>

On the consuming side, the three failure modes are distinct classes on purpose — they call for
different responses.

<details>
<summary>Show the three catch blocks</summary>

```php
use CursorWalk\Exception\MalformedPageException;
use CursorWalk\Exception\PageBudgetExceededException;
use CursorWalk\Exception\PaginationLoopException;

try {
    foreach ($paginator->items($fetcher) as $widget) {
        process($widget);
    }
} catch (MalformedPageException $e) {
    // The upstream sent something unusable. getRawContext() is the payload snippet
    // you attached; getCursor() is the cursor that was being fetched.
    $logger->error('malformed page', [
        'cursor' => $e->getCursor(),
        'raw' => $e->getRawContext(),
    ]);
} catch (PaginationLoopException $e) {
    // A cursor repeated. This would have looped forever — file a bug upstream
    // (or in your fetcher's cursor extraction). Do not retry.
    $logger->critical('pagination loop', ['message' => $e->getMessage()]);
} catch (PageBudgetExceededException $e) {
    // Policy. Resume from $e->getLastCursor() — see the recipe above.
}
```

</details>

| Exception | Meaning | Typical response |
| --- | --- | --- |
| `MalformedPageException` | The upstream response is not a usable page. | Log with `getCursor()` / `getRawContext()`; retry or fail the request. |
| `PaginationLoopException` | A cursor repeated — the walk would never terminate. | Treat as a bug. Do not retry blindly. |
| `PageBudgetExceededException` | Your `maxPages` limit was reached. | Resume from `getLastCursor()`, or raise the budget. |

All three extend `CursorWalk\Exception\CursorWalkException`, which extends `\RuntimeException`
— catch the base class if you do not need to distinguish them.

---

## Interop

`cursor-walk` deliberately stops at plain PHP arrays, which makes it additive to the existing
PHP GraphQL stack rather than a competitor to it.

**With [`ivome/graphql-relay-php`](https://github.com/ivome/graphql-relay-php).** That library
covers the *schema-shape* half of Relay pagination: building `Connection`, `Edge`, and
`PageInfo` GraphQL types, and `connectionFromArray()` for slicing data you already hold. The
catch is right there in the name — `connectionFromArray` paginates an **in-memory array**, so
using it against a paginated upstream means fetching everything first, which is the problem
this package exists to avoid. Pair them: use `graphql-relay-php` for the type definitions in
your schema, and `cursor-walk` to produce the data. Either format the result yourself with
`ConnectionFormatter` (its output already matches the connection field shape those types
expect), or hand `slice($fetcher, $first, ...)->items` to `connectionFromArraySlice()` when you
prefer their formatter.

**With [`webonyx/graphql-php`](https://github.com/webonyx/graphql-php).** `ConnectionFormatter::format()`
returns an array with `edges`, `pageInfo`, and optional `totalCount` keys — exactly what
webonyx's default field resolver expects to walk. Return it directly from a resolver against a
connection type; no adapter, no wrapper object, no dependency on webonyx in this package's
`composer.json`.

**With anything else.** There is no GraphQL requirement at all. `items()` and `pages()` are
plain generators; a queue worker, a CSV export, or an ETL job uses them the same way. The Relay
formatter lives in its own `CursorWalk\Relay\` sub-namespace and is entirely optional — the
dependency arrow points `Relay\` → core, never the reverse.

---

## Similar packages

**[`bwaidelich/relay-pagination`](https://github.com/bwaidelich/relay-pagination)** solves the
adjacent problem, and where it fits it is the better tool. It serves one Relay `Connection` page
at a time from a data source *you* control — an `ArrayLoader`, a `CallbackLoader`, or its
Doctrine DBAL/ORM loaders — and it supports backward pagination (`last` / `before`) today. If
your resolver is backed by your own database, reach for that one first.

`cursor-walk` picks up where your control ends: the upstream is *already* paginated, with opaque
cursors you did not mint and cannot re-derive — a third-party REST API, a DynamoDB-style scan.

| | `relay-pagination` | `cursor-walk` |
| --- | --- | --- |
| Data source | Yours — array, callback, Doctrine | Somebody else's paginated API |
| Unit of work | Serve one connection page | Walk many upstream pages lazily |
| Backward pagination | Yes | No — forward-only, [on the roadmap](#roadmap) |
| Exact-N `first` | One page in, one page out | `slice()` spans as many fetches as it takes |
| Runaway upstream | — | Loop detection, page budget, resume cursors |
| Malformed page | — | `MalformedPageException` with cursor and raw payload |

Same neighbourhood, opposite halves: `relay-pagination` generalises *serving* your own data as a
connection, `cursor-walk` generalises *fetching* somebody else's. Its `CallbackLoader` does give
you a hook to call a remote API from — but the multi-page walking, the guards, and the resume
logic behind that hook are still yours to write, and that is the part this package is.

---

## Design notes

### Forward-only

Only `after`-style forward pagination is implemented.

<details>
<summary>Why, and what emulating it would cost</summary>

Backward pagination (`before`/`last`) is not a mirror image of forward pagination: it requires
the upstream to expose a reverse cursor or a stable total ordering, and most APIs that hand out
opaque forward cursors expose neither. Emulating it — buffering, or walking forward from the
start to find the window — would burn the memory guarantee that is the whole point of the
package. So the package says so plainly, and there is no `before` / `last`. See the
[roadmap](#roadmap).

**Amended in 0.2.0:** `pageInfo.hasPreviousPage` used to be hardcoded `false`, which was
described here as a forward-only constraint. It was not one. The formatter already receives the
cursor a page was fetched with, and a non-origin start position is proof that elements precede
the window — a fact the engine holds and was throwing away, and one the Relay spec explicitly
allows a server to report. Reporting it is not backward traversal; it adds no way to go
backwards. The forward-only constraint above is unchanged.

</details>

### No built-in HTTP client

The fetcher owns fetching.

<details>
<summary>Why transport stays out of the package</summary>

That single decision keeps the composer `require` block at `php` alone, and it means your
existing retry middleware, auth token refresh, connection pool, timeout policy, tracing, and
test doubles all keep working untouched. Pagination and transport are genuinely separate
concerns; a paginator that also owned HTTP would be worse at both, and would force a client
choice on every consumer. Non-HTTP upstreams — a database cursor, an SDK, a local file —
implement the same one-method interface with no adapter layer.

</details>

### Safety guards: bug versus policy

Two independent guards, deliberately reported as different exception types.

<details>
<summary>Why they are two exception classes and not one</summary>

`PaginationLoopException` fires when a cursor repeats. That is only ever a defect — in the
upstream, or in how the fetcher extracts the cursor — and it would loop until the process is
killed. It is not retryable and not resumable, so it gets an exception class you can alert on.

`PageBudgetExceededException` fires when `maxPages` is exhausted. That is not a defect; it is
the limit *you* set doing its job. It carries `getLastCursor()` and `getMaxPages()` so the
caller can continue the walk in the next unit of work. Conflating the two into one exception
would force every caller to parse a message to decide between "resume" and "page the on-call
engineer".

Repeated-cursor detection alone is not enough, which is why the budget is on by default. An
upstream emitting an ever-fresh cursor with `hasNextPage: true` forever never repeats a cursor
and never terminates. The default of 10,000 pages is high enough that no legitimate interactive
workload reaches it and low enough that a runaway loop ends in seconds instead of taking down
the process. Set it to `null` when you truly want unbounded, and mean it.

`null` has one more cost: repeated-cursor detection keeps every cursor it has seen, so guard
memory grows with the walk — on the order of 100 bytes per page. Under the default budget that
caps out at a few megabytes; on an unbounded multi-million-page backfill it does not. Run walks
of that size as bounded chunks resumed from `pages()` checkpoints instead of one unbounded walk.

</details>

### Cursor stability

**Synthetic edge cursors are positional, and positions move.** This is the most important
tradeoff in the package, so it is documented up front rather than discovered in production.

Most upstreams give you one cursor per *page*, not per *item*. Relay clients, however, send an
individual edge's `cursor` back as `after`. To bridge that gap, `OffsetEdgeCursorStrategy`
synthesises an edge cursor as `(page cursor, offset within that page)`, encoded by
`CursorCodec` as base64-wrapped JSON with a version field. `Paginator::slice()` and
`items()` decode it and resume by re-fetching that page and skipping `$skip` items.

The consequence: **an edge cursor identifies a position, not an item.** It is exact only as
long as the upstream data has not shifted between the two requests. If three widgets are
inserted before the cursor's page in the meantime, the next page repeats three items; if three
are deleted, it skips three. This is the same class of drift as offset pagination, scoped down
to a single page rather than the whole dataset.

What that means in practice:

- **Good fit:** short-lived UI pagination. A user clicking "next" seconds later on a
  slow-moving list is exactly the case this handles well.
- **Poor fit:** long-lived bookmarks — cursors persisted in a database, emailed in a link, or
  stored in a job that runs tomorrow — over a fast-changing dataset.
- **Best fit if available:** if your upstream exposes item-level cursors, use them. Implement
  `EdgeCursorStrategy` to return the upstream's own cursor per edge (see the resolver recipe
  above). `CursorCodec::decode()` passes foreign cursors through untouched as
  `[$cursor, 0]`, so the round trip works without any further wiring, and you get stability the
  synthetic scheme cannot offer.
- **Also stable:** *page*-level cursors from `pages()` and `$page->endCursor` are the
  upstream's own opaque cursors, unmodified. Checkpointing a batch job is exactly as stable as
  the upstream makes it — the positional caveat applies only to synthetic *edge* cursors.

The default is a deliberate choice to make Relay pagination work correctly against
page-cursor-only upstreams, with the failure mode named out loud. v2 narrows it further with
ID-anchored cursors.

---

## Roadmap

Nothing here is promised on a date; it is the order things would be built in.

**v2 — ID-anchored edge cursors.** The planned answer to the cursor-stability tradeoff above.
An optional `ItemIdExtractor` on the edge cursor strategy pulls a stable identifier out of each
item; the cursor then encodes `(pageCursor, lastSeenItemId)` instead of `(pageCursor, offset)`,
and resumption becomes "fetch that page, skip until just after that ID" rather than "skip N
items". That is resilient to inserts and deletes anywhere in the page, and it degrades cleanly:
with no extractor configured, the strategy stays in today's offset mode. The `CursorCodec`
envelope already carries a `v` version field for precisely this migration, so v1-issued cursors
keep decoding after the upgrade.

**Async / concurrent fetching.** A prefetching driver over `Walk`, so `slice()` can fetch the next
upstream page while the current one is being mapped. The policy/driver split that 0.2.0 introduced
is the prerequisite this needed: it is now a second driver rather than a re-architecture. Bounded
concurrency only; laziness stays the default.

**Backward pagination.** `before` / `last`, for upstreams that actually support reverse traversal.
Demoted below async: most opaque-cursor upstreams cannot support it at all, and
[`bwaidelich/relay-pagination`](https://github.com/bwaidelich/relay-pagination) already owns the
case where you control the data. The truthful `hasPreviousPage` in 0.2.0 removed the most visible
symptom without opening this door.

Also considered and deliberately out of scope: a built-in HTTP client, caching, multi-source
fan-out/merge, and telemetry hooks. Fan-out needs relevance tiers, per-source weights, interleave
policy and failure isolation — none of which generalise, so a generic merger would either hardcode
one arbitrary policy or grow a config surface larger than this package. Telemetry already has two
seams: log inside `fetchPage()`, or log per page while consuming `pages()`.

---

## License

MIT. See [LICENSE](LICENSE).
