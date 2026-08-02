<?php

declare(strict_types=1);

namespace CursorWalk\Relay;

use CursorWalk\CursorCodec;
use CursorWalk\Page;

/**
 * Default strategy: derives each edge cursor from (page anchor, offset) via
 * {@see CursorCodec}.
 *
 * ## The anchor
 *
 * `$pageStartCursor` is the cursor the page was FETCHED with — its START
 * position — not `$page->endCursor`, which points at the NEXT page. That
 * distinction is the whole trick, because resuming works like this:
 *
 * ```php
 * [$anchor, $skip] = $codec->decode($edgeCursor);
 * $paginator->items($fetcher, $anchor, $skip);   // starts at $anchor, drops $skip items
 * ```
 *
 * `$skip` is counted from the start of the page fetched with `$anchor`. Anchor
 * to `endCursor` and every cursor would be one whole page too far.
 *
 * ## Off-by-one, on purpose
 *
 * The encoded offset is `index + 1`, i.e. the position immediately AFTER the
 * edge. Relay semantics are "give me what comes after this cursor", so decoding
 * edge *i* and resuming must land on item *i+1* — no duplicate of *i*, no gap.
 *
 * A pleasant consequence: the last edge's cursor is exactly the position
 * {@see \CursorWalk\Paginator::slice()} encodes as the resulting page's
 * `endCursor` when the slice ends mid-page. Edge cursors and `pageInfo` describe
 * the same positions.
 *
 * ## Anchors that are themselves encoded
 *
 * The anchor may already be a codec cursor — that is exactly what happens on the
 * second round trip, when the client sends back an `after` that pointed into the
 * middle of a page. So it is decoded first and its offset used as a base:
 *
 * ```
 * anchor = encode(cursorOfPageB, 4)   // slice began 4 items into page B
 * edge 0 -> encode(cursorOfPageB, 4 + 0 + 1) = position 5 items into page B
 * ```
 *
 * A raw upstream cursor decodes to `[$itself, 0]` (see
 * {@see CursorCodec::decode()}), and a null anchor means "the first page", so
 * all three anchor kinds collapse into one code path.
 *
 * ## Multi-page slices
 *
 * When a slice spans several upstream pages, every edge is anchored to the
 * slice's start rather than to the individual upstream page it came from —
 * {@see Page} cannot carry per-item provenance. Resumption stays correct because
 * {@see \CursorWalk\Paginator::items()} lets `$skip` carry across page
 * boundaries; the only cost is re-fetching at most `first` items' worth of
 * pages. `pageInfo.endCursor` is unaffected: `slice()` anchors it to the last
 * upstream page it touched, so ordinary "load more" paging never pays that cost.
 *
 * ## Stability
 *
 * These cursors are positional and therefore only valid while the upstream data
 * does not shift between requests — right for short-lived UI pagination, wrong
 * for durable bookmarks. Swap in a custom {@see EdgeCursorStrategy} when your
 * upstream offers item-level cursors.
 */
final class OffsetEdgeCursorStrategy implements EdgeCursorStrategy
{
    /**
     * @param string|null $pageStartCursor the cursor the page was fetched with;
     *                                     null means the page starts the stream.
     *                                     May itself be a codec-encoded position.
     * @param CursorCodec $codec           position codec
     */
    public function __construct(
        private readonly ?string $pageStartCursor = null,
        private readonly CursorCodec $codec = new CursorCodec(),
    ) {
    }

    /**
     * Return a copy anchored at a different page start.
     *
     * {@see ConnectionFormatter::format()} calls this per invocation, which is
     * how the anchor reaches the strategy without widening the
     * {@see EdgeCursorStrategy} interface.
     */
    public function withPageStartCursor(?string $pageStartCursor): self
    {
        return new self($pageStartCursor, $this->codec);
    }

    public function cursorForEdge(Page $page, int $index): string
    {
        [$anchor, $base] = $this->pageStartCursor === null
            ? [null, 0]
            : $this->codec->decode($this->pageStartCursor);

        return $this->codec->encode($anchor, $base + $index + 1);
    }
}
