<?php

declare(strict_types=1);

namespace CursorWalk\Tests\Support;

use CursorWalk\Offset\OffsetPage;
use CursorWalk\Offset\PageNumberFetcher;

/**
 * An in-memory page-numbered upstream: `?page=N&per_page=S` over a fixed list.
 *
 * @template T
 *
 * @extends PageNumberFetcher<T>
 */
final class PageNumberApiFetcher extends PageNumberFetcher
{
    /** @var list<int> */
    private array $requested = [];

    /**
     * @param list<T> $rows
     */
    public function __construct(
        private readonly array $rows,
        int $pageSize = 3,
        private readonly TotalsReporting $reporting = TotalsReporting::TotalPages,
    ) {
        parent::__construct($pageSize);
    }

    /**
     * @return OffsetPage<T>
     */
    protected function fetchAt(int $position, int $pageSize): OffsetPage
    {
        $this->requested[] = $position;

        /** @var list<T> $slice */
        $slice = \array_slice($this->rows, ($position - 1) * $pageSize, $pageSize);

        return new OffsetPage(
            $slice,
            totalItems: $this->reporting === TotalsReporting::TotalItems ? \count($this->rows) : null,
            totalPages: $this->reporting === TotalsReporting::TotalPages
                ? (int) ceil(\count($this->rows) / $pageSize)
                : null,
        );
    }

    /**
     * @return list<int>
     */
    public function requested(): array
    {
        return $this->requested;
    }

    public function callCount(): int
    {
        return \count($this->requested);
    }
}
