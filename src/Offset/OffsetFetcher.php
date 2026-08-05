<?php

declare(strict_types=1);

namespace CursorWalk\Offset;

use CursorWalk\Page;

/**
 * Base class for an upstream paginated by ROW OFFSET — `?offset=100&limit=50`,
 * `?skip=100&take=50`, `LIMIT 50 OFFSET 100`.
 *
 * **Prefer this over {@see PageNumberFetcher} whenever the upstream accepts raw
 * offsets.** Its cursors are absolute row positions, so they stay meaningful even
 * if the page size changes between requests — the coupling documented on
 * `PageNumberFetcher` simply does not exist here.
 *
 * ```php
 * /** @extends OffsetFetcher<array<string, mixed>> *\/
 * final class LedgerFetcher extends OffsetFetcher
 * {
 *     public function __construct(private readonly HttpClient $http)
 *     {
 *         parent::__construct(pageSize: 200);
 *     }
 *
 *     protected function fetchAt(int $position, int $pageSize): OffsetPage
 *     {
 *         $body = $this->http->getJson('/entries', ['offset' => $position, 'limit' => $pageSize]);
 *
 *         return new OffsetPage($body['rows'], totalItems: $body['total'] ?? null);
 *     }
 * }
 * ```
 *
 * The next cursor is the offset just past the last row actually returned, not
 * `$position + $pageSize` — a short mid-stream page therefore resumes at the
 * right row instead of skipping the difference.
 *
 * ## Repeated-cursor detection is nearly inert here — read this
 *
 * Offsets increase as long as the upstream returns rows, so
 * {@see \CursorWalk\Exception\PaginationLoopException} cannot fire the way it
 * does for a genuine cursor upstream. It retains exactly one job: an upstream
 * that returns zero rows while claiming more data leaves the offset unmoved, and
 * the repeated cursor is caught on the next fetch.
 *
 * Otherwise the guard that matters is {@see OffsetPage::hasMoreAfter()} — and
 * when the envelope reports neither `totalItems` nor `totalPages`, the page
 * budget. Keep `Paginator`'s budget on, and size it deliberately.
 *
 * @template-covariant T
 *
 * @extends PositionalFetcher<T>
 */
abstract class OffsetFetcher extends PositionalFetcher
{
    /**
     * The offset a walk starts from.
     */
    private const FIRST_OFFSET = 0;

    /**
     * Fetch one window of rows.
     *
     * @param int $position 0-based row offset to start at
     * @param int $pageSize rows to request, always the constructor's `$pageSize`
     *
     * @return OffsetPage<T>
     */
    abstract protected function fetchAt(int $position, int $pageSize): OffsetPage;

    /**
     * Sealed: the engine contract is satisfied here, in terms of
     * {@see self::fetchAt()}.
     *
     * @return Page<T>
     */
    final public function fetchPage(?string $cursor): Page
    {
        $offset = $this->positionFrom($cursor, self::FIRST_OFFSET);
        $result = $this->fetchAt($offset, $this->pageSize);

        $itemsThrough = $offset + \count($result->items);
        $pageNumber = intdiv($offset, $this->pageSize) + 1;
        $hasNextPage = $result->hasMoreAfter($pageNumber, $itemsThrough, $this->pageSize);

        return new Page(
            $result->items,
            $hasNextPage ? (string) $itemsThrough : null,
            $hasNextPage,
            $result->totalItems,
        );
    }
}
