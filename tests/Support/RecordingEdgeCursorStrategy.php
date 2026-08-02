<?php

declare(strict_types=1);

namespace CursorWalk\Tests\Support;

use CursorWalk\Page;
use CursorWalk\Relay\EdgeCursorStrategy;

/**
 * A spying {@see EdgeCursorStrategy} that yields `edge-<index>` and records
 * every call it received.
 *
 * Used by the case-14 tests to prove that an injected strategy fully owns edge
 * cursor derivation, and that it is handed sequential indexes together with the
 * very {@see Page} instance being formatted. Declared as a named class rather
 * than an anonymous one so the recorded properties stay statically typed at the
 * call sites.
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
