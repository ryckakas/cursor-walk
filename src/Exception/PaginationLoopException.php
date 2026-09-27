<?php

declare(strict_types=1);

namespace CursorWalk\Exception;

/**
 * Thrown when the same cursor is handed out twice in one walk. Always a bug in
 * the upstream or the fetcher, and not retryable: the walk would loop forever.
 */
final class PaginationLoopException extends CursorWalkException
{
    private function __construct(string $message, private readonly string $cursor)
    {
        parent::__construct($message);
    }

    public static function repeatedCursor(string $cursor): self
    {
        return new self(
            \sprintf(
                'Pagination loop detected: cursor "%s" was returned again; the upstream would never terminate.',
                $cursor,
            ),
            $cursor,
        );
    }

    public function getCursor(): string
    {
        return $this->cursor;
    }
}
