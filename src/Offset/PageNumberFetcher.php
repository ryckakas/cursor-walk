<?php

declare(strict_types=1);

namespace CursorWalk\Offset;

use CursorWalk\Page;

/**
 * Base class for an upstream paginated by page number (`?page=2&per_page=50`).
 * The page size is part of every cursor, so keep it fixed and never derive it
 * from a Relay `first`; let Paginator::slice() cut the window instead.
 *
 * @template-covariant T
 *
 * @extends PositionalFetcher<T>
 */
abstract class PageNumberFetcher extends PositionalFetcher
{
    private const FIRST_PAGE = 1;

    /**
     * The one method you implement: fetch the rows of one page.
     *
     * @param int $position 1-based page number; request `$position - 1` from a 0-indexed upstream
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
        $pageNumber = $this->positionFrom($cursor, self::FIRST_PAGE);
        $result = $this->fetchAt($pageNumber, $this->pageSize);

        $itemsThrough = ($pageNumber - 1) * $this->pageSize + \count($result->items);
        $hasNextPage = $result->hasMoreAfter($pageNumber, $itemsThrough, $this->pageSize);

        return new Page(
            $result->items,
            $hasNextPage ? (string) ($pageNumber + 1) : null,
            $hasNextPage,
            $result->totalItems,
        );
    }
}
