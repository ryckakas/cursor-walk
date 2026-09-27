<?php

declare(strict_types=1);

namespace CursorWalk\Tests\Support;

use CursorWalk\Exception\MalformedPageException;
use CursorWalk\Page;
use CursorWalk\PaginatedFetcher;

/**
 * An in-memory {@see PaginatedFetcher} over a fixed list of items.
 * Cursors are base64 rather than bare integers, so tests exercise opaque cursors like a real upstream's.
 *
 * @template T
 *
 * @implements PaginatedFetcher<T>
 */
final class ArrayFetcher implements PaginatedFetcher
{
    private const CURSOR_PREFIX = 'offset-';

    /**
     * @param list<T> $items
     */
    public function __construct(
        private readonly array $items,
        private readonly int $pageSize = 3,
        private readonly bool $withTotalCount = false,
    ) {
    }

    public static function cursorFor(int $offset): string
    {
        return base64_encode(self::CURSOR_PREFIX . $offset);
    }

    /**
     * @return Page<T>
     */
    public function fetchPage(?string $cursor): Page
    {
        $offset = $this->offsetFor($cursor);

        /** @var list<T> $slice */
        $slice = array_slice($this->items, $offset, $this->pageSize);

        $nextOffset = $offset + count($slice);
        $hasNextPage = $nextOffset < count($this->items);

        return new Page(
            $slice,
            $hasNextPage ? self::cursorFor($nextOffset) : null,
            $hasNextPage,
            $this->withTotalCount ? count($this->items) : null,
        );
    }

    private function offsetFor(?string $cursor): int
    {
        if ($cursor === null) {
            return 0;
        }

        $decoded = base64_decode($cursor, true);

        if ($decoded === false || !str_starts_with($decoded, self::CURSOR_PREFIX)) {
            throw MalformedPageException::invalidEnvelope(
                'unrecognized cursor',
                $cursor,
                $decoded === false ? null : $decoded,
            );
        }

        $offset = substr($decoded, strlen(self::CURSOR_PREFIX));

        if ($offset === '' || preg_match('/^\d+$/', $offset) !== 1) {
            throw MalformedPageException::invalidEnvelope('unrecognized cursor', $cursor, $decoded);
        }

        return (int) $offset;
    }
}
