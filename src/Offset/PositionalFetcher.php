<?php

declare(strict_types=1);

namespace CursorWalk\Offset;

use CursorWalk\Exception\MalformedPageException;
use CursorWalk\PaginatedFetcher;

/**
 * Shared page-size and cursor validation for the positional fetchers, so the
 * two cannot drift. Extend PageNumberFetcher or OffsetFetcher, not this class.
 *
 * @template-covariant T
 *
 * @implements PaginatedFetcher<T>
 */
abstract class PositionalFetcher implements PaginatedFetcher
{
    /**
     * @throws \InvalidArgumentException if `$pageSize` is below 1
     */
    public function __construct(protected readonly int $pageSize)
    {
        if ($pageSize < 1) {
            throw new \InvalidArgumentException(\sprintf('$pageSize must be >= 1, got %d.', $pageSize));
        }
    }

    /**
     * @throws MalformedPageException if the cursor is not a plain integer in range
     */
    final protected function positionFrom(?string $cursor, int $firstPosition): int
    {
        if ($cursor === null) {
            return $firstPosition;
        }

        // \A and \z rather than ^ and $: unanchored `$` also matches before a
        // trailing newline, so "5\n" would parse as page 5.
        if (preg_match('/\A\d+\z/', $cursor) !== 1) {
            throw MalformedPageException::invalidEnvelope(
                \sprintf(
                    'positional cursors are plain non-negative integers, got "%s". A synthetic edge '
                    . 'cursor must be run through CursorCodec::decode() first — pass its page cursor, '
                    . 'not the encoded string.',
                    $cursor,
                ),
                $cursor,
            );
        }

        $position = (int) $cursor;

        if ($position < $firstPosition) {
            throw MalformedPageException::invalidEnvelope(
                \sprintf('positional cursor "%s" is below the first position (%d).', $cursor, $firstPosition),
                $cursor,
            );
        }

        if ($position > $this->maxPosition()) {
            throw MalformedPageException::invalidEnvelope(
                \sprintf(
                    'positional cursor "%s" is above the largest position this page size can address (%d).',
                    $cursor,
                    $this->maxPosition(),
                ),
                $cursor,
            );
        }

        return $position;
    }

    private function maxPosition(): int
    {
        // Cursors are client-supplied, and past this the position arithmetic overflows
        // to float and surfaces as a TypeError. `- 1` leaves a page of headroom.
        return intdiv(\PHP_INT_MAX, $this->pageSize) - 1;
    }
}
