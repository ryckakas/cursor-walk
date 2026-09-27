<?php

declare(strict_types=1);

namespace CursorWalk\Tests\Support;

use CursorWalk\Page;
use CursorWalk\PaginatedFetcher;

/**
 * Spy decorator around any {@see PaginatedFetcher} that records every cursor
 * fetchPage() received, for the laziness tests.
 *
 * @template T
 *
 * @implements PaginatedFetcher<T>
 */
final class CountingFetcher implements PaginatedFetcher
{
    /** @var list<?string> */
    private array $cursors = [];

    /**
     * @param PaginatedFetcher<T> $inner
     */
    public function __construct(private readonly PaginatedFetcher $inner)
    {
    }

    /**
     * @return Page<T>
     */
    public function fetchPage(?string $cursor): Page
    {
        $this->cursors[] = $cursor;

        return $this->inner->fetchPage($cursor);
    }

    public function callCount(): int
    {
        return count($this->cursors);
    }

    /**
     * @return list<?string>
     */
    public function cursors(): array
    {
        return $this->cursors;
    }

    public function firstCursor(): ?string
    {
        return $this->cursors[0] ?? null;
    }

    public function lastCursor(): ?string
    {
        return $this->cursors === [] ? null : $this->cursors[count($this->cursors) - 1];
    }
}
