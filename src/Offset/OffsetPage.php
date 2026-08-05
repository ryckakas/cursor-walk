<?php

declare(strict_types=1);

namespace CursorWalk\Offset;

/**
 * What a page-numbered or offset-based upstream actually told you.
 *
 * The intermediate value object between your `fetchAt()` implementation and the
 * {@see \CursorWalk\Page} the engine walks. It carries the two envelope signals
 * such upstreams commonly expose — a total row count and/or a total page count —
 * and owns the rule for turning either into a terminal condition. Both are
 * optional, because plenty of upstreams expose neither.
 *
 * You never construct a {@see \CursorWalk\Page} yourself when extending
 * {@see PageNumberFetcher} or {@see OffsetFetcher}: report what the envelope
 * said, and the fetcher derives `hasNextPage` and the next cursor from it.
 *
 * ```php
 * return new OffsetPage(
 *     items:      $body['data'],
 *     totalItems: $body['meta']['total'] ?? null,
 *     totalPages: $body['meta']['totalPages'] ?? null,
 * );
 * ```
 *
 * @template-covariant T
 */
final class OffsetPage
{
    /**
     * @param list<T>  $items      this page's rows, in upstream order
     * @param int|null $totalItems rows in the whole result set, when the envelope
     *                             reports one (`total`, `count`, `totalElements`, …)
     * @param int|null $totalPages pages in the whole result set, when the envelope
     *                             reports one (`totalPages`, `pages`, `last_page`, …)
     */
    public function __construct(
        public readonly array $items,
        public readonly ?int $totalItems = null,
        public readonly ?int $totalPages = null,
    ) {
    }

    /**
     * Whether rows remain beyond this page.
     *
     * Precedence, strongest signal first:
     *
     * | upstream reports  | terminal condition             |
     * |-------------------|--------------------------------|
     * | `totalPages`      | `$pageNumber < $totalPages`    |
     * | `totalItems` only | `$itemsThrough < $totalItems`  |
     * | neither           | a page shorter than `$pageSize` is the last one |
     *
     * The third rule is the weakest of the three: an upstream whose final page
     * happens to hold exactly `$pageSize` rows costs one extra fetch, which comes
     * back empty and ends the walk. A wasted round trip, never a missed row — and
     * the reason the first two signals are preferred whenever the envelope has
     * them.
     *
     * Both position arguments are ABSOLUTE within the result set rather than
     * counted from the start of this walk, which is what keeps the comparisons
     * correct for a walk resumed from a checkpoint. The price of being stateless is
     * that a fetcher can only derive the position in its own unit exactly:
     *
     * | signal       | exact for                | assumes uniform pages for |
     * |--------------|--------------------------|---------------------------|
     * | `totalPages` | {@see PageNumberFetcher} | {@see OffsetFetcher}      |
     * | `totalItems` | {@see OffsetFetcher}     | {@see PageNumberFetcher}  |
     *
     * So report the signal that matches your fetcher's own unit whenever the
     * envelope has it. The cross-unit combination is not wrong, only approximate:
     * it holds as long as every page is full.
     *
     * That matters most for an upstream returning SHORT mid-stream pages — anything
     * post-filtering a page server-side. There, the matching signal is not a
     * preference but a requirement: without it a short page is indistinguishable
     * from the last one, so rule three ends the walk at the first filtered page,
     * while the mismatched total runs ahead of the rows actually read and ends it
     * early too. `totalPages` from a {@see PageNumberFetcher} (or `totalItems` from
     * an {@see OffsetFetcher}) is exact regardless of how many rows each page holds.
     *
     * @param int $pageNumber   1-based ordinal of this page within the result set
     * @param int $itemsThrough rows in the result set up to and including this page
     * @param int $pageSize     rows this page was requested with
     */
    public function hasMoreAfter(int $pageNumber, int $itemsThrough, int $pageSize): bool
    {
        if ($this->totalPages !== null) {
            return $pageNumber < $this->totalPages;
        }

        if ($this->totalItems !== null) {
            return $itemsThrough < $this->totalItems;
        }

        // `>=` rather than `===`: an upstream that ignores the requested size and
        // over-delivers is broken, but degrading towards "keep walking" leaves the
        // engine's own guards in charge instead of silently truncating the stream.
        return \count($this->items) >= $pageSize;
    }
}
