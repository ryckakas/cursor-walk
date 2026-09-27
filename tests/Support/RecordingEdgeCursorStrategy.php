<?php

declare(strict_types=1);

namespace CursorWalk\Tests\Support;

use CursorWalk\Page;
use CursorWalk\Relay\EdgeCursorStrategy;

/**
 * A spying {@see EdgeCursorStrategy} that yields `edge-<index>` and records every call.
 * Named rather than anonymous so the recorded properties stay statically typed at the call sites.
 */
final class RecordingEdgeCursorStrategy implements EdgeCursorStrategy
{
    /** @var list<int> */
    public array $indexes = [];

    /** @var list<Page<mixed>> */
    public array $pages = [];

    /**
     * @param Page<mixed> $page
     */
    public function cursorForEdge(Page $page, int $index): string
    {
        $this->indexes[] = $index;
        $this->pages[] = $page;

        return 'edge-' . $index;
    }
}
