<?php

declare(strict_types=1);

namespace CursorWalk\Offset;

/**
 * What a positional upstream reported, returned from fetchAt(). Report the total
 * that matches your fetcher's unit (totalPages for PageNumberFetcher, totalItems
 * for OffsetFetcher): the other one assumes every page is full.
 *
 * @template-covariant T
 */
final class OffsetPage
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly ?int $totalItems = null,
        public readonly ?int $totalPages = null,
    ) {
    }

    /**
     * Whether rows remain beyond this page. Precedence: totalPages, then totalItems,
     * then "a page shorter than `$pageSize` is the last one", which costs one empty
     * extra fetch when the final page is exactly full.
     *
     * @param int $pageNumber   1-based, within the whole result set
     * @param int $itemsThrough absolute rows up to and including this page
     */
    public function hasMoreAfter(int $pageNumber, int $itemsThrough, int $pageSize): bool
    {
        if ($this->totalPages !== null) {
            return $pageNumber < $this->totalPages;
        }

        if ($this->totalItems !== null) {
            return $itemsThrough < $this->totalItems;
        }

        // `>=`, not `===`: an over-delivering upstream keeps walking under the
        // engine's guards instead of being silently truncated.
        return \count($this->items) >= $pageSize;
    }
}
