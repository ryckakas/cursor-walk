<?php

declare(strict_types=1);

namespace CursorWalk\Relay;

use CursorWalk\CursorCodec;
use CursorWalk\Page;

/**
 * Default edge cursors: CursorCodec positions at (anchor, index + 1), so resuming
 * lands on the item after the edge. The anchor is the cursor the page was FETCHED
 * with; anchoring to its endCursor would put every edge a whole page too far.
 */
final class OffsetEdgeCursorStrategy implements EdgeCursorStrategy
{
    /**
     * @param string|null $pageStartCursor the cursor the page was fetched with; may itself be codec-encoded
     */
    public function __construct(
        private readonly ?string $pageStartCursor = null,
        private readonly CursorCodec $codec = new CursorCodec(),
    ) {
    }

    /**
     * A copy anchored at a different page start. ConnectionFormatter rebinds the
     * anchor this way, so the EdgeCursorStrategy interface stays two-argument.
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
