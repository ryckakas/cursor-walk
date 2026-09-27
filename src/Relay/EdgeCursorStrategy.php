<?php

declare(strict_types=1);

namespace CursorWalk\Relay;

use CursorWalk\Page;

/**
 * Produces per-edge cursors: the seam for swapping in real item-level cursors.
 * A cursor sent back as `after` must resume at the item immediately AFTER its
 * edge, with no duplicates and no gaps.
 */
interface EdgeCursorStrategy
{
    /**
     * @param Page<mixed> $page
     * @param int         $index 0-based index into `$page->items`
     */
    public function cursorForEdge(Page $page, int $index): string;
}
