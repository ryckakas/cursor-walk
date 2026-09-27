<?php

declare(strict_types=1);

namespace CursorWalk\Exception;

/**
 * Thrown when a walk used up its page budget while the upstream still reports
 * more. Policy, not a bug, which is why it is distinct from PaginationLoopException:
 * resume the job from getLastCursor() instead of filing a bug.
 */
final class PageBudgetExceededException extends CursorWalkException
{
    private function __construct(
        string $message,
        private readonly int $maxPages,
        private readonly ?string $lastCursor,
    ) {
        parent::__construct($message);
    }

    /**
     * @param string|null $lastCursor cursor of the next, un-fetched page
     */
    public static function exceeded(int $maxPages, ?string $lastCursor = null): self
    {
        return new self(
            \sprintf(
                'Page budget of %d exceeded while the upstream still reports more data. '
                . 'Resume from the last cursor, or raise/disable the budget (maxPages: null).',
                $maxPages,
            ),
            $maxPages,
            $lastCursor,
        );
    }

    public function getMaxPages(): int
    {
        return $this->maxPages;
    }

    /**
     * Cursor of the page the budget stopped before fetching. Pass it as the start
     * cursor to pages(), items() or slice() to resume with no gaps and no duplicates.
     */
    public function getLastCursor(): ?string
    {
        return $this->lastCursor;
    }
}
