# Recipes

Each recipe builds on the `WidgetFetcher` from the [quick start](../README.md#use-it). Snippets
omit `declare(strict_types=1);`; the library declares it in every file, and your fetchers should
too.

- [A GraphQL resolver returning a Relay connection](#a-graphql-resolver-returning-a-relay-connection)
- [Batch jobs: checkpoint per page](#batch-jobs-checkpoint-per-page)
- [Resuming a walk](#resuming-a-walk)
- [Page-numbered and offset upstreams](#page-numbered-and-offset-upstreams)
- [Driving the walk yourself](#driving-the-walk-yourself)
- [Rejecting malformed pages](#rejecting-malformed-pages)

## A GraphQL resolver returning a Relay connection

The flow is `$after` → `CursorCodec::decode()` → `Paginator::slice()` →
`ConnectionFormatter::format()`. `slice()` assembles exactly `$first` items across as many
upstream fetches as it takes, and its `hasNextPage` and `endCursor` describe the real end
position, even when that lands in the middle of an upstream page.

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

`format()` returns a plain array in Relay connection shape, with no GraphQL library involved:

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

### Edge cursors

Edge cursors come from an `EdgeCursorStrategy`. The default, `OffsetEdgeCursorStrategy`, encodes
`(page cursor, offset within page)`, which identifies a position rather than an item; read
[cursor stability](design.md#cursor-stability) before persisting one. If the upstream has
item-level cursors, return those instead:

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

`CursorCodec::decode()` passes a cursor it did not mint through unchanged, so upstream item cursors
round-trip as `after` with no further wiring.

## Batch jobs: checkpoint per page

`pages()` yields whole `Page` objects, so each page is a natural transaction, retry and checkpoint
boundary.

```php
use CursorWalk\Paginator;

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
steps over them. If your upstream should never send one, [reject it in the
fetcher](#rejecting-malformed-pages).

## Resuming a walk

Any `endCursor` you have seen is a resume point. Pass it as `$startCursor` to `pages()`, or as
`$pageCursor` to `items()` and `slice()`, and the first `fetchPage()` call receives exactly that
cursor.

That is also how a walk continues past its page budget: `PageBudgetExceededException` carries
`getLastCursor()` so a bounded walk resumes instead of restarting.

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
        break; // reached the end of the upstream
    } catch (PageBudgetExceededException $e) {
        // 500 pages is one unit of work. Bank the position and hand the rest to the next unit.
        $cursor = $e->getLastCursor();
        $checkpoints->save('widget-sync', $cursor);
    }
}
```

A synthetic edge cursor you gave a client decodes into both halves:

```php
use CursorWalk\CursorCodec;

[$pageCursor, $skip] = (new CursorCodec())->decode($after);

foreach ($paginator->items($fetcher, $pageCursor, $skip) as $widget) {
    // starts at the item after the one the cursor pointed at
}
```

`decode()` never throws. A cursor it does not recognise, such as a raw upstream cursor, comes back
unchanged as `[$after, 0]`, so upstream and synthetic cursors are interchangeable at the API
boundary. The empty string decodes to `[null, 0]`, the first page, which is what
`$codec->decode($args['after'] ?? '')` relies on.

## Page-numbered and offset upstreams

`?page=2&per_page=50` and `?offset=100&limit=50` need no special mode: a page number is already a
legal opaque cursor. Extend a base class from `CursorWalk\Offset\` and implement `fetchAt()`. The
base class parses and formats the cursor, derives the terminal condition, and handles the last
page being exactly full.

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

`items()`, `pages()`, `slice()` and `ConnectionFormatter` work over it unchanged. Cursors are plain
integer strings, which `decode()` passes through as foreign cursors. Use `OffsetFetcher` when the
upstream takes a row offset: its cursors do not depend on the page size, where `"3"` from a
`PageNumberFetcher` means nothing without it.

A `PageNumberFetcher`'s page number only increases, so `PaginationLoopException` never fires for
it. A reported `totalPages` or `totalItems` ends a runaway on the page that produced it. When the
envelope reports neither, a short page ends the walk, and only the page budget stops an upstream
that claims a full page forever. Keep it on.

### Terminal conditions

`OffsetPage::hasMoreAfter()` uses the strongest signal the envelope gave it:

| Upstream reports | The walk ends when |
| --- | --- |
| `totalPages` | the page number reaches `totalPages` |
| `totalItems` only | the rows read reach `totalItems` |
| neither | a page is shorter than `$pageSize` |

The last rule costs one extra call when the final page is exactly full: the next fetch comes back
empty. A wasted call, never a missed row.

Because `hasNextPage` is derived rather than read from the envelope, an envelope that claims more
data past its own `totalPages` cannot happen here. There is nothing left to guard.

### Two traps

**Report the total that matches the fetcher's unit:** `totalPages` from a `PageNumberFetcher`,
`totalItems` from an `OffsetFetcher`. Those are exact. The cross-unit pairing assumes every page is
full, so it runs ahead of the rows actually read once an upstream filters pages server-side.

**Never derive a `PageNumberFetcher`'s `$pageSize` from a Relay `first`.** Returned cursors then
resume at the wrong window as soon as a client asks for a different size. Keep the page size fixed
and let `slice()` cut the variable window. The constructor has no default for exactly this reason.

A 0-indexed upstream needs no separate class or flag: request `$position - 1` inside `fetchAt()`
and leave the walk 1-based.

## Driving the walk yourself

Use `Paginator` unless you cannot let the library call `fetchPage()`, as in a Temporal workflow
where each fetch is an activity `yield`, or an event loop where it is a promise. A
`PaginatedFetcher` cannot yield. `Walk` is the same policy (budget, cursor threading, loop
detection, termination) as a stepper you drive. `Paginator::pages()` is built on it, so the two
cannot drift.

```php
use CursorWalk\Page;
use CursorWalk\Walk;

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
driver rather than in the upstream.

Replay is safe because everything `Walk` knows comes from the `Page` values handed to `advance()`,
which a replaying engine reproduces identically. A `Walk` is single-use: one per walk, never shared
between concurrent walks, never registered as a service. `Paginator` is the thing you inject.
There are deliberately no `items()` or `slice()` on `Walk`; if you want those, you want
`Paginator`.

In workflow code, convert a guard exception into whatever your engine treats as non-retryable (for
Temporal, an `ApplicationFailure`). Left alone, it is retried forever.

## Rejecting malformed pages

`Page` refuses one combination at construction: `hasNextPage: true` with a null or empty
`endCursor`, which claims more data while withholding the way to reach it. It throws
`MalformedPageException` inside your fetcher, where the raw payload is still at hand.

Anything stricter is a judgment the fetcher is better placed to make than the engine, so the
library has no strictness flags. It gives you an exception with debug context instead:

```php
use CursorWalk\Exception\MalformedPageException;
use CursorWalk\Page;
use CursorWalk\PaginatedFetcher;

/** @implements PaginatedFetcher<array{id: int}> */
final class StrictWidgetFetcher implements PaginatedFetcher
{
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

        // Fetcher policy: this upstream never sends an empty page mid-stream, so one means truncation.
        if ($items === [] && $hasNextPage) {
            throw MalformedPageException::invalidEnvelope('empty mid-stream page', $cursor, $raw);
        }

        return new Page($items, $data['cursor'] ?? null, $hasNextPage);
    }
}
```

On the consuming side, catch the three exceptions separately, because each calls for a different
response:

```php
use CursorWalk\Exception\MalformedPageException;
use CursorWalk\Exception\PageBudgetExceededException;
use CursorWalk\Exception\PaginationLoopException;

try {
    foreach ($paginator->items($fetcher) as $widget) {
        process($widget);
    }
} catch (MalformedPageException $e) {
    // getCursor() is the cursor being fetched; getRawContext() is the payload you attached.
    $logger->error('malformed page', ['cursor' => $e->getCursor(), 'raw' => $e->getRawContext()]);
} catch (PaginationLoopException $e) {
    // A cursor repeated and the walk would never end. A bug upstream or in the fetcher: do not retry.
    $logger->critical('pagination loop', ['message' => $e->getMessage()]);
} catch (PageBudgetExceededException $e) {
    // Policy. Resume from $e->getLastCursor(), as in "Resuming a walk" above.
}
```
