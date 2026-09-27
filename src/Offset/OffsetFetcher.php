<?php

declare(strict_types=1);

namespace CursorWalk\Offset;

use CursorWalk\Page;

/**
 * Base class for an upstream paginated by row offset (`?offset=100&limit=50`).
 * Prefer it over PageNumberFetcher: its cursors are absolute row offsets, so they
 * survive a page-size change. Loop detection is nearly inert: keep the budget on.
 *
 * @template-covariant T
 *
 * @extends PositionalFetcher<T>
 */
abstract class OffsetFetcher extends PositionalFetcher
{
    private const FIRST_OFFSET = 0;

    /**
     * The one method you implement: fetch one window of rows.
     *
     * @param int $position 0-based row offset to start at
     * @param int $pageSize always the constructor's `$pageSize`
     *
     * @return OffsetPage<T>
     */
    abstract protected function fetchAt(int $position, int $pageSize): OffsetPage;

    /**
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
