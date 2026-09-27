<?php

declare(strict_types=1);

namespace CursorWalk\Tests\Support;

use CursorWalk\Exception\MalformedPageException;
use CursorWalk\Page;
use CursorWalk\PaginatedFetcher;

/**
 * A never-ending upstream with a fresh cursor every page. Loop detection cannot catch it, so it is
 * the fixture for {@see \CursorWalk\Exception\PageBudgetExceededException}. Cursors are positional,
 * so resuming from `getLastCursor()` continues with no duplicates and no gaps.
 *
 * @implements PaginatedFetcher<string>
 */
final class InfiniteFetcher implements PaginatedFetcher
{
    private const CURSOR_PREFIX = 'inf-';

    private int $callCount = 0;

    public function __construct(private readonly int $pageSize = 1)
    {
    }

    public static function cursorFor(int $offset): string
    {
        return base64_encode(self::CURSOR_PREFIX . $offset);
    }

    public static function itemAt(int $offset): string
    {
        return 'item-' . $offset;
    }

    /**
     * @return Page<string>
     */
    public function fetchPage(?string $cursor): Page
    {
        ++$this->callCount;

        $offset = $this->offsetFor($cursor);

        /** @var list<string> $items */
        $items = [];
        for ($i = 0; $i < $this->pageSize; ++$i) {
            $items[] = self::itemAt($offset + $i);
        }

        return new Page($items, self::cursorFor($offset + $this->pageSize), true);
    }

    public function callCount(): int
    {
        return $this->callCount;
    }

    private function offsetFor(?string $cursor): int
    {
        if ($cursor === null) {
            return 0;
        }

        $decoded = base64_decode($cursor, true);

        if ($decoded === false || !str_starts_with($decoded, self::CURSOR_PREFIX)) {
            throw MalformedPageException::invalidEnvelope('unrecognized cursor', $cursor);
        }

        return (int) substr($decoded, strlen(self::CURSOR_PREFIX));
    }
}
