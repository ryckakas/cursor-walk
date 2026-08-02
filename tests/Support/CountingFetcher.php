<?php

declare(strict_types=1);

namespace CursorWalk\Tests\Support;

use CursorWalk\Page;
use CursorWalk\PaginatedFetcher;

/**
 * Spy decorator around any {@see PaginatedFetcher}.
 *
 * Records how many times fetchPage() was called and which cursor each call
 * received, which is what the laziness tests assert on.
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
     * Every cursor received, in call order. `null` is the first-page fetch.
     *
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
