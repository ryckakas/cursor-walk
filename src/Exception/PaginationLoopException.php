<?php

declare(strict_types=1);

namespace CursorWalk\Exception;

/**
 * Thrown when the same cursor is handed out twice while walking a stream.
 *
 * This is always a BUG in the upstream or in the fetcher implementation: the
 * walk would otherwise loop forever over the same page. It is deliberately a
 * different class from {@see PageBudgetExceededException}, which signals a
 * policy limit on an otherwise healthy stream.
 */
final class PaginationLoopException extends CursorWalkException
{
    private function __construct(string $message, private readonly string $cursor)
    {
        parent::__construct($message);
    }

    /**
     * @param string $cursor the cursor that was returned a second time
     */
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

    /**
     * The cursor that repeated.
     */
    public function getCursor(): string
    {
        return $this->cursor;
    }
}
