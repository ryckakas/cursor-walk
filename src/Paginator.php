<?php

declare(strict_types=1);

namespace CursorWalk;

use CursorWalk\Exception\PageBudgetExceededException;
use CursorWalk\Exception\PaginationLoopException;

/**
 * The walk engine: turns a {@see PaginatedFetcher} into lazy item iteration,
 * lazy page iteration, or an exact bounded slice.
 *
 * Stateless and immutable — a single instance is safe to share, reuse and run
 * concurrently. All walk state lives inside the returned generators.
 *
 * Three methods, deliberately:
 *  - {@see self::items()}  — lazy item iteration (the common case);
 *  - {@see self::pages()}  — lazy page iteration (checkpointing, retry boundaries);
 *  - {@see self::slice()}  — exact eager slice (honouring a Relay `first` argument).
 *
 * `items()` and `slice()` both delegate to `pages()`, so the safety guards below
 * apply uniformly to all three.
 */
final class Paginator
{
    /**
     * ## The page budget — read this
     *
     * By default a walk is BOUNDED at 10,000 upstream fetches. Pass `null` to
     * disable the bound.
     *
     * Repeated-cursor detection catches an upstream that hands back a cursor it
     * already used. It cannot catch the other failure mode: an upstream that
     * emits an ever-fresh cursor with `hasNextPage=true` forever. Without a
     * budget, such an upstream turns a GraphQL resolver into an unbounded loop
     * that exhausts the request timeout, the memory limit, or your API quota.
     * The budget is the backstop.
     *
     * Sizing it:
     *  - **Interactive requests (GraphQL resolvers, HTTP handlers):** lower it
     *    hard — a few dozen pages. A user-facing request that needs thousands of
     *    upstream round trips is already broken; prefer {@see self::slice()}.
     *  - **Batch jobs / backfills:** raise it, or pass `null` and rely on
     *    per-page checkpointing via {@see self::pages()}.
     *
     * When the budget runs out the engine throws
     * {@see PageBudgetExceededException}, which carries the cursor of the page
     * it did not fetch — pass that back as `$startCursor` (or `$pageCursor`) to
     * resume with no gaps and no duplicates. Budget exhaustion is policy, not a
     * bug, which is why it is a different exception class from
     * {@see PaginationLoopException}.
     *
     * @param int|null    $maxPages maximum upstream fetches per walk; null disables the bound
     * @param CursorCodec $codec    position codec used by {@see self::slice()} to
     *                              encode mid-page end positions
     */
    public function __construct(
        private readonly ?int $maxPages = 10_000,
        private readonly CursorCodec $codec = new CursorCodec(),
    ) {
    }

    /**
     * Lazily iterate every item across every page.
     *
     * Exactly one `fetchPage()` call per page, made only when iteration crosses
     * a page boundary — so `foreach (... ) { break; }` after the first item
     * costs exactly one fetch. Empty mid-stream pages are traversed invisibly.
     *
     * `$pageCursor` is both the start position and the resume/checkpoint
     * mechanism: `null` walks from the beginning. `$skip` drops items from that
     * start position, and is what a decoded synthetic edge cursor feeds in:
     *
     * ```php
     * [$pageCursor, $skip] = $codec->decode($after);
     * foreach ($paginator->items($fetcher, $pageCursor, $skip) as $item) { ... }
     * ```
     *
     * In practice `$skip` is smaller than the first page, because it always
     * originates as an offset within one page. Should it exceed that page's item
     * count it carries over into the following pages rather than being silently
     * dropped, so that a position remains a position no matter how the upstream
     * chunks it. (This also keeps edge cursors correct for slices that span
     * several upstream pages — see {@see Relay\OffsetEdgeCursorStrategy}.)
     *
     * ## Keys
     *
     * Keys are sequential from 0 across the WHOLE walk — they do not restart at
     * each page boundary — so `iterator_to_array($paginator->items($f))` is safe
     * and returns every item. (The generator yields items one at a time rather
     * than `yield from`-ing each page's array, which would restart the implicit
     * key counter per page and make that call silently drop items.) Keys count
     * yielded items, so with `$skip` the first item still has key 0.
     *
     * @template T
     *
     * @param PaginatedFetcher<T> $fetcher
     * @param string|null         $pageCursor start position; null = from the beginning
     * @param int                 $skip       items to drop from the start position; negatives are clamped to 0
     *
     * @return \Generator<int, T> keys are sequential from 0 across all pages
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

            // Deliberately NOT `yield from $items`: that would restart the
            // implicit key counter at 0 on every page, so a caller using
            // iterator_to_array() without $preserve_keys=false would silently
            // keep only the last page's worth of items. See the "Keys" section
            // in this method's docblock.
            foreach ($items as $item) {
                yield $item;
            }
        }
    }

    /**
     * Lazily iterate whole pages.
     *
     * This is the checkpointing primitive: each yielded page is a natural retry
     * boundary for batch jobs and workflow engines, and `$page->endCursor` is a
     * durable resume token. Empty mid-stream pages are yielded as-is.
     *
     * Guards applied while walking:
     *  - stop as soon as a page reports `hasNextPage === false`;
     *  - throw {@see PaginationLoopException} if a cursor is handed out twice
     *    (including a page pointing back at `$startCursor`);
     *  - throw {@see PageBudgetExceededException} once `$maxPages` pages have
     *    been fetched and the upstream still reports more.
     *
     * All guard state is local to the generator, so the paginator stays
     * stateless and concurrent walks cannot interfere.
     *
     * @template T
     *
     * @param PaginatedFetcher<T> $fetcher
     * @param string|null         $startCursor resume point; null = from the beginning
     *
     * @return \Generator<int, Page<T>>
     *
     * @throws PaginationLoopException
     * @throws PageBudgetExceededException
     */
    public function pages(PaginatedFetcher $fetcher, ?string $startCursor = null): \Generator
    {
        $cursor = $startCursor;
        $fetched = 0;

        /** @var array<string, true> $seen */
        $seen = [];
        if ($startCursor !== null) {
            $seen[$startCursor] = true;
        }

        while (true) {
            if ($this->maxPages !== null && $fetched >= $this->maxPages) {
                throw PageBudgetExceededException::exceeded($this->maxPages, $cursor);
            }

            $page = $fetcher->fetchPage($cursor);
            ++$fetched;

            yield $page;

            if (!$page->hasNextPage) {
                return;
            }

            $next = $page->endCursor;

            // Unreachable through Page: its constructor rejects hasNextPage=true
            // with a null/empty endCursor, so by this line $next is always a
            // usable cursor. The check stays as a belt-and-braces stop — without
            // it, any future path that produced such a page (a subclass-free
            // reflection hack, an unserialize(), a relaxation of that
            // validation) would spin forever re-fetching the same cursor.
            // Terminating is the safe degradation. Excluded from coverage
            // because exercising it would mean constructing an illegal Page.
            // @codeCoverageIgnoreStart
            if ($next === null || $next === '') {
                return;
            }
            // @codeCoverageIgnoreEnd

            if (isset($seen[$next])) {
                throw PaginationLoopException::repeatedCursor($next);
            }
            $seen[$next] = true;

            $cursor = $next;
        }
    }

    /**
     * Assemble exactly `$first` items starting at (`$pageCursor`, `$skip`),
     * spanning as many upstream fetches as needed, and return them as a proper
     * {@see Page}.
     *
     * This is the method a GraphQL resolver should use to honour Relay's `first`
     * argument:
     *
     * ```php
     * [$pageCursor, $skip] = $codec->decode($after ?? '');
     * $page = $paginator->slice($fetcher, $first, $pageCursor, $skip);
     * return $formatter->format($page, null, $codec->encode($pageCursor, $skip));
     * ```
     *
     * Want a plain bounded array instead? `slice($fetcher, $limit)->items`.
     *
     * ### The returned page
     *
     * - **items** — exactly `$first` items, or fewer when the stream runs out.
     * - **hasNextPage** — true when the last fetched upstream page still holds
     *   items beyond the slice end, OR reports `hasNextPage=true` itself.
     * - **endCursor** — the ACTUAL end position, ready to be sent straight back
     *   in as `$pageCursor`/`$skip` after a {@see CursorCodec::decode()}:
     *   - ended mid-upstream-page → `CursorCodec::encode($cursorThatFetchedThatPage, $consumed)`;
     *   - ended exactly on a page boundary → that page's raw upstream
     *     `endCursor`, which is the cheapest possible anchor;
     *   - nothing left → `null`, mirroring `hasNextPage=false`.
     *
     *   Anchoring to the LAST upstream page touched (rather than to the slice's
     *   own start) is what keeps repeated "load more" round trips O(1) instead
     *   of O(n²): each response's `endCursor` starts the next walk from the
     *   nearest upstream page, never from the origin.
     *
     * - **totalCount** — carried over from the last page fetched, when present.
     *
     * ### Fetch economy
     *
     * The walk stops the moment the answer is provable. If page *k* fills the
     * slice and still has a spare item — or claims `hasNextPage=true` — page
     * *k+1* is never requested. Breaking out of the underlying `pages()`
     * generator leaves it suspended, so no speculative fetch happens.
     *
     * @template T
     *
     * @param PaginatedFetcher<T> $fetcher
     * @param int                 $first      how many items to assemble; must be >= 0
     * @param string|null         $pageCursor start position; null = from the beginning
     * @param int                 $skip       items to drop from the start position; must be >= 0
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

        // The cursor the page currently under inspection was FETCHED with. Page
        // itself only knows the cursor of the NEXT page, so we track this here.
        $fetchedWith = $pageCursor;

        $hasNextPage = false;
        $endCursor = null;
        $totalCount = null;

        foreach ($this->pages($fetcher, $pageCursor) as $page) {
            $totalCount = $page->totalCount;
            $size = \count($page->items);
            $consumed = 0;

            if ($remainingSkip > 0) {
                $dropped = min($remainingSkip, $size);
                $consumed += $dropped;
                $remainingSkip -= $dropped;
            }

            $take = min($size - $consumed, $first - \count($collected));
            if ($take > 0) {
                foreach (\array_slice($page->items, $consumed, $take) as $item) {
                    $collected[] = $item;
                }
                $consumed += $take;
            }

            if ($remainingSkip === 0 && \count($collected) >= $first) {
                $hasNextPage = $consumed < $size || $page->hasNextPage;

                if ($hasNextPage) {
                    $endCursor = $consumed < $size
                        ? $this->codec->encode($fetchedWith, $consumed)
                        : $page->endCursor;
                }

                // Leaves the generator suspended: page k+1 is never fetched.
                break;
            }

            $fetchedWith = $page->endCursor;
        }

        return new Page($collected, $endCursor, $hasNextPage, $totalCount);
    }
}
