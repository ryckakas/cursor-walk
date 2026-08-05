<?php

declare(strict_types=1);

namespace CursorWalk\Tests\Support;

use CursorWalk\Offset\OffsetFetcher;
use CursorWalk\Offset\OffsetPage;

/**
 * An in-memory offset-based upstream: `?offset=N&limit=S` over a fixed list.
 *
 * Doubles as the canonical doc example for {@see OffsetFetcher}.
 *
 * @template T
 *
 * @extends OffsetFetcher<T>
 */
final class OffsetApiFetcher extends OffsetFetcher
{
    /** @var list<int> the offsets requested, in call order */
    private array $requested = [];

    /**
     * @param list<T>         $rows      the complete backing data set
     * @param int             $pageSize  rows per request
     * @param TotalsReporting $reporting which terminal signal the envelope exposes
     */
    public function __construct(
        private readonly array $rows,
        int $pageSize = 3,
        private readonly TotalsReporting $reporting = TotalsReporting::TotalItems,
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
        $slice = \array_slice($this->rows, $position, $pageSize);

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
