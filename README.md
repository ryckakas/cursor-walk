# cursor-walk

![cursor-walk](.github/images/cover-hero.jpg)

**The fetching half of cursor pagination for PHP.**

You implement one method, `fetchPage(?string $cursor): Page`, that turns one upstream response into
one page. cursor-walk owns the loop around it: threading the cursor, fetching each page only when
iteration reaches it, resuming from a checkpoint, stopping runaway upstreams, and cutting exact-N
slices for a Relay `first`. It works against a REST API, a GraphQL API, a DynamoDB scan, or
anything else that says "here is a page, here is the cursor for the next one".

[![CI](https://github.com/ryckakas/cursor-walk/actions/workflows/ci.yml/badge.svg)](https://github.com/ryckakas/cursor-walk/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/ryckakas/cursor-walk.svg)](https://packagist.org/packages/ryckakas/cursor-walk)
[![PHP Version](https://img.shields.io/packagist/php-v/ryckakas/cursor-walk.svg)](https://packagist.org/packages/ryckakas/cursor-walk)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg)](https://phpstan.org/)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

## Why this one

- **Lazy by construction.** One upstream call per page, made when iteration crosses into it. A
  client asking for 25 items from 100-item pages costs one call, not the whole dataset in memory.
- **Guarded by default.** A repeated cursor throws `PaginationLoopException`. An upstream that
  hands out fresh cursors forever hits a 10,000-page budget and throws
  `PageBudgetExceededException`, which carries the cursor to resume from.
- **Resumable.** Every `endCursor` is a checkpoint, so a batch job that dies halfway picks up at
  the page it was on.
- **Exact slices.** `slice()` assembles exactly `first` items across as many fetches as it takes,
  and its `endCursor` marks the real end position, even in the middle of an upstream page.
- **Relay output with no GraphQL dependency.** `ConnectionFormatter` returns a plain `edges` /
  `pageInfo` array that a resolver can return as is.
- **Any cursor.** Opaque tokens, page numbers and row offsets all work, because the engine never
  looks inside a cursor.
- **Transport stays yours.** The library makes no requests. Your HTTP client, retries, auth and
  test doubles are untouched, and `require` is `php` alone.

## Install

```bash
composer require ryckakas/cursor-walk
```

PHP 8.2 or newer. No runtime dependencies.

## Use it

Implement `PaginatedFetcher`. It is the only integration point.

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
    public function __construct(
        private readonly ClientInterface $http,             // any PSR-18 client
        private readonly RequestFactoryInterface $requests,
    ) {
    }

    public function fetchPage(?string $cursor): Page
    {
        $url = 'https://api.example.com/widgets?limit=100'
            . ($cursor === null ? '' : '&after=' . urlencode($cursor));

        $body = (string) $this->http->sendRequest($this->requests->createRequest('GET', $url))->getBody();
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

foreach ($paginator->items(new WidgetFetcher($client, $requestFactory)) as $widget) {
    // Page 2 is fetched when the loop reaches item 101, not before.
}
```

`Paginator` is stateless: inject one and reuse it. It has three methods.

| Method | Returns | Use it for |
| --- | --- | --- |
| `items($fetcher, ?string $pageCursor = null, int $skip = 0)` | `Generator<int, T>` | Streaming every item. |
| `pages($fetcher, ?string $startCursor = null)` | `Generator<int, Page<T>>` | Batch jobs: one transaction, retry and checkpoint per page. |
| `slice($fetcher, int $first, ?string $pageCursor = null, int $skip = 0)` | `Page<T>` | Exactly N items, such as a Relay `first`. |

Snippets here omit `declare(strict_types=1);`. The library declares it in every file, and your
fetchers should too.

<details>
<summary><b>The page budget</b></summary>

`new Paginator()` stops after 10,000 pages. Loop detection catches a cursor that repeats, but not
an upstream that returns a fresh cursor with `hasNextPage: true` forever. The budget does. Size it
to the caller, not the dataset:

```php
$interactive = new Paginator(maxPages: 50);      // an API request: fail fast
$batch       = new Paginator(maxPages: 100_000); // a nightly sync: still bounded
$unbounded   = new Paginator(maxPages: null);    // no limit, and the risk is yours
```

Running out of budget is policy: you set a limit and reached it. A repeated cursor is a bug. They
are separate exception classes so you can resume the first and alert on the second.

`maxPages: null` has one more cost. Loop detection keeps every cursor it has seen, about 100 bytes
per page, so an unbounded multi-million-page backfill grows without limit. Run it as bounded chunks
resumed from `pages()` checkpoints instead.

</details>

## Recipes

### A GraphQL resolver returning a Relay connection

The flow is `$after` → `CursorCodec::decode()` → `Paginator::slice()` →
`ConnectionFormatter::format()`.

<details>
<summary><b>The resolver</b></summary>

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
        // A resolver is not a batch job. At 100 items a page, 50 pages is far more than any `first`.
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
        [$pageCursor, $skip] = $this->codec->decode($args['after'] ?? '');

        $page = $this->paginator->slice($this->fetcher, $first, $pageCursor, $skip);

        // The third argument anchors each edge cursor to where this slice began. Without it,
        // edge cursors only round-trip correctly on the very first page.
        return $this->formatter->format(
            $page,
            static fn (array $widget): array => ['id' => (string) $widget['id'], 'name' => $widget['name']],
            $this->codec->encode($pageCursor, $skip),
        );
    }
}
```

</details>

<details>
<summary><b>The output</b></summary>

```php
[
    'edges' => [
        ['node' => ['id' => '1', 'name' => 'Sprocket'], 'cursor' => 'eyJ2IjoxLCJj...'],
        // ...
    ],
    'pageInfo' => [
        'endCursor' => 'eyJ2IjoxLCJj...',
        'hasNextPage' => true,
        'startCursor' => 'eyJ2IjoxLCJj...',
        'hasPreviousPage' => false,
    ],
    'totalCount' => 4213, // only when the Page carried one
];
```

`hasPreviousPage` is `true` when the `$pageStartCursor` you passed is anywhere other than the
origin: a page anchor, or a non-zero offset into the first page. The Relay spec allows reporting
that whenever the server knows it cheaply. It is an honest answer about where the window sits, not
backward pagination. Omit `$pageStartCursor` and it is `false`.

</details>

Edge cursors come from an `EdgeCursorStrategy`. The default, `OffsetEdgeCursorStrategy`, encodes
`(page cursor, offset within page)`; read [cursor stability](#cursor-stability) before persisting
one. If the upstream has item-level cursors, return those instead:

```php
use CursorWalk\Page;
use CursorWalk\Relay\ConnectionFormatter;
use CursorWalk\Relay\EdgeCursorStrategy;

final class UpstreamItemCursorStrategy implements EdgeCursorStrategy
{
    public function cursorForEdge(Page $page, int $index): string
    {
        return $page->items[$index]['cursor'];
    }
}

$formatter = new ConnectionFormatter(new UpstreamItemCursorStrategy());
```

### Batch jobs: checkpoint per page

```php
$paginator = new Paginator(maxPages: 100_000);

foreach ($paginator->pages($fetcher, $checkpoints->load('widget-sync')) as $page) {
    $db->transaction(static function () use ($db, $page): void {
        foreach ($page->items as $widget) {
            $db->upsert('widgets', $widget);
        }
    });

    // Save only after the page is durable. A crash here replays the page: at-least-once, never skipped.
    $checkpoints->save('widget-sync', $page->endCursor);
}

$checkpoints->clear('widget-sync');
```

`pages()` yields empty mid-stream pages (`items: []`, `hasNextPage: true`) as they come. DynamoDB
filtered scans and any upstream that filters after slicing produce them legitimately, and `items()`
steps over them. If your upstream should never send one, reject it in the fetcher (see
[malformed pages](#malformed-pages-and-the-three-exceptions)).

### Resuming a walk

Any `endCursor` you have seen is a resume point. Pass it as `$startCursor` to `pages()`, or as
`$pageCursor` to `items()` and `slice()`, and the first `fetchPage()` call receives exactly that
cursor. `PageBudgetExceededException::getLastCursor()` exists for the same purpose.

<details>
<summary><b>A loop that resumes across page budgets</b></summary>

```php
use CursorWalk\Exception\PageBudgetExceededException;

$paginator = new Paginator(maxPages: 500);
$cursor = $checkpoints->load('widget-sync');

while (true) {
    try {
        foreach ($paginator->pages($fetcher, $cursor) as $page) {
            process($page);
            $cursor = $page->endCursor;
            $checkpoints->save('widget-sync', $cursor);
        }
        break; // reached the end of the upstream
    } catch (PageBudgetExceededException $e) {
        // 500 pages is one unit of work. Bank the position and hand the rest to the next unit.
        $cursor = $e->getLastCursor();
        $checkpoints->save('widget-sync', $cursor);
    }
}
```

</details>

A synthetic edge cursor you gave a client decodes into both halves:

```php
[$pageCursor, $skip] = (new CursorCodec())->decode($after);

foreach ($paginator->items($fetcher, $pageCursor, $skip) as $widget) {
    // starts at the item after the one the cursor pointed at
}
```

`decode()` never throws. A cursor it does not recognise, such as a raw upstream cursor, comes back
unchanged as `[$after, 0]`, so upstream and synthetic cursors are interchangeable at the API
boundary. The empty string decodes to `[null, 0]`, the first page.

### Page-numbered and offset upstreams

`?page=2&per_page=50` and `?offset=100&limit=50` need no special mode: a page number is already a
legal opaque cursor. Extend a base class from `CursorWalk\Offset\` and implement `fetchAt()`:

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

        // Report what the envelope says; the base class derives hasNextPage and the next cursor.
        return new OffsetPage(
            $body['data'],
            totalItems: $body['meta']['total'] ?? null,
            totalPages: $body['meta']['totalPages'] ?? null,
        );
    }
}
```

Everything else works over it unchanged. Cursors are plain integer strings, which `decode()` passes
through as foreign cursors. Use `OffsetFetcher` when the upstream takes a row offset: its cursors
do not depend on the page size, where `"3"` from a `PageNumberFetcher` means nothing without it.

A `PageNumberFetcher`'s page number only increases, so `PaginationLoopException` never fires for
it. A reported
`totalPages` or `totalItems` ends a runaway on the page that produced it. When the envelope reports
neither, a short page ends the walk, and only the page budget stops an upstream that claims a full
page forever. Keep it on.

<details>
<summary><b>Terminal conditions, and two traps</b></summary>

`OffsetPage::hasMoreAfter()` uses the strongest signal the envelope gave it:

| Upstream reports | The walk ends when |
| --- | --- |
| `totalPages` | the page number reaches `totalPages` |
| `totalItems` only | the rows read reach `totalItems` |
| neither | a page is shorter than `$pageSize` |

The last rule costs one extra call when the final page is exactly full: the next fetch comes back
empty. A wasted call, never a missed row.

**Report the total that matches the fetcher's unit:** `totalPages` from a `PageNumberFetcher`,
`totalItems` from an `OffsetFetcher`. The cross-unit pairing assumes every page is full, so it runs
ahead of the rows actually read once an upstream filters pages server-side.

**Never derive a `PageNumberFetcher`'s `$pageSize` from a Relay `first`.** Returned cursors then
resume at the wrong window as soon as a client asks for a different size. Keep the page size fixed
and let `slice()` cut the variable window. The constructor has no default for exactly this reason.

A 0-indexed upstream needs no flag: request `$position - 1` inside `fetchAt()`.

</details>

### Driving the walk yourself

Use `Paginator` unless you cannot let the library call `fetchPage()`, as in a Temporal workflow
where each fetch is an activity `yield`, or an event loop where it is a promise. `Walk` is the same
policy as a stepper you drive, and `Paginator::pages()` is built on it, so the two cannot drift.

```php
$walk = new Walk($dto->resumeCursor, maxPages: 500);

while ($walk->hasNext()) {
    $result = yield $this->activity->fetchContacts($dto, $walk->nextCursor());
    yield from $this->processPage($result);

    // Rebuild rather than unmarshal: payload converters bypass the constructor that validates a Page.
    $walk->advance(new Page($result->items, $result->endCursor, $result->hasNextPage));
}
```

Call `nextCursor()` then `advance()`, once each, until `hasNext()` is false. Any other order throws
a plain `\LogicException`, outside the library's exception hierarchy, because it is a bug in the
driver. A `Walk` is single-use: one per walk, never shared, never registered as a service. In
workflow code, convert a guard exception into whatever your engine treats as non-retryable (for
Temporal, an `ApplicationFailure`), or it is retried forever.

### Malformed pages and the three exceptions

`Page` refuses one combination: `hasNextPage: true` with a null or empty `endCursor`, which claims
more data while withholding the way to reach it. It throws `MalformedPageException` inside your
fetcher, where the raw payload is still at hand. Anything stricter is the fetcher's call; there are
no strictness flags.

```php
// Fetcher policy: this upstream never sends an empty page mid-stream, so one means truncation.
if ($items === [] && $hasNextPage) {
    throw MalformedPageException::invalidEnvelope('empty mid-stream page', $cursor, $raw);
}
```

| Exception | Meaning | Typical response |
| --- | --- | --- |
| `MalformedPageException` | The upstream response is not a usable page. | Log `getCursor()` and `getRawContext()`; retry or fail the request. |
| `PaginationLoopException` | A cursor repeated, so the walk would never end. | A bug upstream or in the fetcher. Do not retry. |
| `PageBudgetExceededException` | Your `maxPages` was reached. | Resume from `getLastCursor()`, or raise the budget. |

All three extend `CursorWalk\Exception\CursorWalkException`, a `\RuntimeException`.

## Cursor stability

**A synthetic edge cursor names a position, not an item.** Most upstreams give one cursor per page,
but a Relay client sends back one edge's cursor as `after`. `OffsetEdgeCursorStrategy` bridges the
gap with `(page cursor, offset within page)`, and resuming re-fetches that page and skips the
offset. If three rows are inserted before that position in between, the next page repeats three; if
three are deleted, it skips three. That is offset-pagination drift, scoped to a single page.

- **Good fit:** interactive paging, where "next" follows seconds later.
- **Poor fit:** cursors stored for tomorrow (in a database, a link, a queued job) over data that
  changes quickly.
- **Stable:** page cursors from `pages()` and `$page->endCursor` are the upstream's own, unmodified.
  So are item-level cursors returned through your own `EdgeCursorStrategy`.

ID-anchored edge cursors, which survive inserts and deletes, are planned for v2. The codec's `v`
field keeps today's cursors decoding after that change.

## Alongside other packages

cursor-walk stops at plain PHP arrays, so it adds to the PHP GraphQL stack rather than replacing
any of it.

- **[webonyx/graphql-php](https://github.com/webonyx/graphql-php):** return `format()`'s array
  straight from a resolver on a connection type. The default field resolver walks `edges`,
  `pageInfo` and `totalCount` with no adapter.
- **[ivome/graphql-relay-php](https://github.com/ivome/graphql-relay-php):** use it for the
  `Connection`, `Edge` and `PageInfo` types. Its `connectionFromArray()` needs the data in memory,
  so let cursor-walk produce it, or hand `slice(...)->items` to `connectionFromArraySlice()`.
- **[bwaidelich/relay-pagination](https://github.com/bwaidelich/relay-pagination):** the better
  tool when you own the data source (an array, a callback, Doctrine). It serves one connection page
  at a time and supports backward pagination. cursor-walk is for data you do not own, behind
  somebody else's opaque cursors.
- **No GraphQL at all:** `items()` and `pages()` are plain generators for queue workers, exports and
  ETL jobs. `CursorWalk\Relay\` depends on the core, never the reverse.

<details>
<summary><b>What it deliberately does not do</b></summary>

- **Backward pagination.** `before` / `last` needs a reverse cursor or a stable total order, which
  most opaque-cursor upstreams lack. Emulating it means buffering or re-walking from the start,
  which gives up the memory guarantee that is the point of the package.
- **HTTP.** The fetcher owns transport. Keeping it out is what lets `require` stay at `php` alone,
  and a database cursor, an SDK or a file implements the same one-method interface.
- **Caching, fan-out across sources, telemetry hooks.** Fan-out needs merge policies that do not
  generalise. For telemetry, log inside `fetchPage()` or per page while consuming `pages()`.

</details>

## Roadmap

In build order, with no dates: ID-anchored edge cursors, a prefetching driver over `Walk` with
bounded concurrency, then backward pagination for upstreams that support it. Details are under
[Unreleased](CHANGELOG.md#unreleased) in the changelog.

## Development

```bash
composer check      # code style, static analysis, complexity, tests, offline example
composer check:ci   # everything CI runs, adding the coverage and mutation floors
```

`check:ci` needs xdebug or pcov. The complexity gate is
[bonsai-lint](https://bonsai.kauneckas.dev), run through `npx`, so it needs Node. CI also audits
the workflows with [zizmor](https://docs.zizmor.sh).

## License

MIT. See [LICENSE](LICENSE).
