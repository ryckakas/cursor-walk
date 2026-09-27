<?php

declare(strict_types=1);

namespace CursorWalk\Tests\Support;

use CursorWalk\Page;
use CursorWalk\PaginatedFetcher;

/**
 * A broken upstream that returns the same cursor forever, the fixture for
 * {@see \CursorWalk\Exception\PaginationLoopException}.
 *
 * @implements PaginatedFetcher<string>
 */
final class LoopingFetcher implements PaginatedFetcher
{
    private int $callCount = 0;

    public function __construct(private readonly string $stuckCursor = 'stuck-cursor')
    {
    }

    public function stuckCursor(): string
    {
        return $this->stuckCursor;
    }

    /**
     * @return Page<string>
     */
    public function fetchPage(?string $cursor): Page
    {
        ++$this->callCount;

        return new Page(['item-' . $this->callCount], $this->stuckCursor, true);
    }

    public function callCount(): int
    {
        return $this->callCount;
    }
}
