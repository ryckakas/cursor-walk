<?php

declare(strict_types=1);

namespace CursorWalk;

use CursorWalk\Exception\PageBudgetExceededException;
use CursorWalk\Exception\PaginationLoopException;

/**
 * The walk engine: turns a PaginatedFetcher into lazy items, lazy pages, or an
 * exact bounded slice. Stateless and safe to share, since all walk state lives
 * in the returned generators. Use Walk only when you cannot let it call fetchPage().
 */
final class Paginator
{
    /**
     * The page budget is the backstop against an upstream that emits fresh cursors
     * forever. Lower it hard for interactive requests; for batch jobs, raise it and
     * resume from PageBudgetExceededException::getLastCursor().
     *
     * @param int|null $maxPages maximum upstream fetches per walk; null disables the bound
     */
    public function __construct(
        private readonly ?int $maxPages = 10_000,
        private readonly CursorCodec $codec = new CursorCodec(),
    ) {
    }

    /**
     * Lazily iterate every item across every page, one fetch per page boundary.
     * Keys run from 0 across the whole walk, so iterator_to_array() is safe.
     * A `$skip` larger than the first page carries over into the next ones.
     *
     * @template T
     *
     * @param PaginatedFetcher<T> $fetcher
     * @param int                 $skip    negatives are clamped to 0
     *
     * @return \Generator<int, T>
     *
     * @throws PaginationLoopException
     * @throws PageBudgetExceededException
     */
    public function items(PaginatedFetcher $fetcher, ?string $pageCursor = null, int $skip = 0): \Generator
    {
        $remaining = max(0, $skip);

        foreach ($this->pages($fetcher, $pageCursor) as $page) {
            $items = $page->items;

            if ($remaining > 0) {
                $available = \count($items);
                if ($remaining >= $available) {
                    $remaining -= $available;

                    continue;
                }

                $items = \array_slice($items, $remaining);
                $remaining = 0;
            }

            // Not `yield from $items`: that restarts keys at 0 on every page, so
            // iterator_to_array() would silently keep only the last page.
            foreach ($items as $item) {
                yield $item;
            }
        }
    }

    /**
     * Lazily iterate whole pages: the checkpointing primitive. Each page is a
     * retry boundary and its endCursor a durable resume token. Empty mid-stream
     * pages are yielded as-is.
     *
     * @template T
     *
     * @param PaginatedFetcher<T> $fetcher
     *
     * @return \Generator<int, Page<T>>
     *
     * @throws PaginationLoopException     if a cursor is handed out twice
     * @throws PageBudgetExceededException once maxPages pages are fetched and more remain
     */
    public function pages(PaginatedFetcher $fetcher, ?string $startCursor = null): \Generator
    {
        $walk = new Walk($startCursor, $this->maxPages);

        while ($walk->hasNext()) {
            // The budget throws in nextCursor(), before the fetch. The page is yielded
            // before advance() runs loop detection, so a checkpointing consumer still
            // sees the offending page's valid items.
            $page = $fetcher->fetchPage($walk->nextCursor());

            yield $page;

            $walk->advance($page);
        }
    }

    /**
     * Assemble exactly `$first` items from ($pageCursor, $skip), across
     * as many upstream fetches as it takes. Its endCursor anchors to the
     * last upstream page read, so repeated "load more" stays O(1).
     *
     * @template T
     *
     * @param PaginatedFetcher<T> $fetcher
     *
     * @return Page<T>
     *
     * @throws \InvalidArgumentException  if `$first` or `$skip` is negative
     * @throws PaginationLoopException
     * @throws PageBudgetExceededException
     */
    public function slice(PaginatedFetcher $fetcher, int $first, ?string $pageCursor = null, int $skip = 0): Page
    {
        if ($first < 0) {
            throw new \InvalidArgumentException(\sprintf('$first must be >= 0, got %d.', $first));
        }
        if ($skip < 0) {
            throw new \InvalidArgumentException(\sprintf('$skip must be >= 0, got %d.', $skip));
        }

        /** @var list<T> $collected */
        $collected = [];
        $remainingSkip = $skip;

        // A Page only knows the cursor of the NEXT page, so track the one it was fetched with.
        $fetchedWith = $pageCursor;
        $totalCount = null;

        foreach ($this->pages($fetcher, $pageCursor) as $page) {
            $totalCount = $page->totalCount;

            $dropped = min($remainingSkip, \count($page->items));
            $remainingSkip -= $dropped;
            $taken = \array_slice($page->items, $dropped, $first - \count($collected));
            array_push($collected, ...$taken);

            if ($remainingSkip === 0 && \count($collected) >= $first) {
                // Returning leaves the generator suspended: page k+1 is never fetched.
                return $this->endSliceIn($page, $fetchedWith, $dropped + \count($taken), $collected);
            }

            $fetchedWith = $page->endCursor;
        }

        return new Page($collected, null, false, $totalCount);
    }

    /**
     * @template T
     *
     * @param Page<T> $page
     * @param list<T> $items
     *
     * @return Page<T>
     */
    private function endSliceIn(Page $page, ?string $fetchedWith, int $consumed, array $items): Page
    {
        if ($consumed < \count($page->items)) {
            return new Page($items, $this->codec->encode($fetchedWith, $consumed), true, $page->totalCount);
        }

        return new Page($items, $page->hasNextPage ? $page->endCursor : null, $page->hasNextPage, $page->totalCount);
    }
}
