<?php

declare(strict_types=1);

namespace CursorWalk\Tests\Support;

use CursorWalk\Page;
use CursorWalk\PaginatedFetcher;

/**
 * A broken upstream that keeps handing back the *same* cursor forever.
 *
 * This is a bug in the upstream or in the fetcher — following it would loop
 * until the heat death of the universe — so the engine must detect the
 * repeat and throw {@see \CursorWalk\Exception\PaginationLoopException}.
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
