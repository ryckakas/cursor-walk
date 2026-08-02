<?php

declare(strict_types=1);

namespace CursorWalk\Relay;

use CursorWalk\Page;

/**
 * Produces the per-edge `cursor` values in a Relay connection.
 *
 * Most upstreams expose page-level cursors only, so the library has to derive
 * something per edge. The strategy is the seam where you replace that derivation
 * — with a real item-level cursor, a signed token, an encoded primary key,
 * whatever your upstream supports.
 *
 * ## Contract
 *
 * `cursorForEdge($page, $index)` must return a token that, when a client sends
 * it back as `after`, resumes at the item immediately AFTER `$page->items[$index]`
 * — no duplicates, no gaps. The built-in {@see OffsetEdgeCursorStrategy} does
 * that by encoding the position *after* the edge rather than the position *of*
 * it.
 *
 * ## Why there is no page-start-cursor parameter here
 *
 * A page's own fetch cursor is not part of {@see Page} (a Page knows the cursor
 * of the NEXT page, not its own). {@see OffsetEdgeCursorStrategy} therefore
 * takes that anchor as CONSTRUCTOR state, and
 * {@see ConnectionFormatter::format()} rebinds it per call from its
 * `$pageStartCursor` argument. Keeping it out of this signature means a custom
 * strategy stays a two-argument implementation and owns its cursor semantics
 * end to end.
 */
interface EdgeCursorStrategy
{
    /**
     * @param Page<mixed> $page  the page being formatted
     * @param int         $index zero-based index of the edge within `$page->items`
     *
     * @return string opaque cursor identifying the position after that edge
     */
    public function cursorForEdge(Page $page, int $index): string;
}
