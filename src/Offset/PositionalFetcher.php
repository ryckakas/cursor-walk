<?php

declare(strict_types=1);

namespace CursorWalk\Offset;

use CursorWalk\Exception\MalformedPageException;
use CursorWalk\PaginatedFetcher;

/**
 * Shared plumbing for the two positional fetchers: page-size handling and
 * cursor parsing.
 *
 * Extend {@see PageNumberFetcher} or {@see OffsetFetcher} — not this class. It
 * exists so the two of them cannot drift on how a cursor is validated, and it
 * deliberately defines no template method beyond that: each subclass writes its
 * own `fetchPage()`, because the position arithmetic is the entire difference
 * between them.
 *
 * @template-covariant T
 *
 * @implements PaginatedFetcher<T>
 */
abstract class PositionalFetcher implements PaginatedFetcher
{
    /**
     * @param int $pageSize rows requested per upstream call
     *
     * @throws \InvalidArgumentException if `$pageSize` is below 1
     */
    public function __construct(protected readonly int $pageSize)
    {
        if ($pageSize < 1) {
            throw new \InvalidArgumentException(\sprintf('$pageSize must be >= 1, got %d.', $pageSize));
        }
    }

    /**
     * Parse an incoming cursor into a position.
     *
     * `null` means "the first page" and yields `$firstPosition`. Anything else
     * must be the plain non-negative integer string that this fetcher emits as a
     * page's `endCursor` — see {@see PageNumberFetcher}'s class docblock for why
     * the wire format is a bare integer.
     *
     * @param int $firstPosition position that starts the walk (1 for page numbers,
     *                           0 for row offsets)
     *
     * @throws MalformedPageException if the cursor is not a plain integer at or
     *                                above `$firstPosition`
     */
    final protected function positionFrom(?string $cursor, int $firstPosition): int
    {
        if ($cursor === null) {
            return $firstPosition;
        }

        if (preg_match('/^\d+$/', $cursor) !== 1) {
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

        return $position;
    }
}
