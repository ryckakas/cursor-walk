<?php

declare(strict_types=1);

namespace CursorWalk\Exception;

/**
 * Thrown when a walk fetched `maxPages` pages and the upstream still reports
 * more data.
 *
 * Budget exhaustion is POLICY, not a bug — the stream may be perfectly healthy
 * and simply longer than the caller allowed. It is therefore a distinct class
 * from {@see PaginationLoopException} so the two can be handled differently:
 * file a bug for a loop, resume a batch job for a budget.
 *
 * The exception carries the cursor of the page that was NOT fetched, so a
 * caller can continue exactly where it stopped:
 *
 * ```php
 * try {
 *     foreach ($paginator->pages($fetcher) as $page) { ... }
 * } catch (PageBudgetExceededException $e) {
 *     $checkpoint = $e->getLastCursor(); // resume later via $startCursor
 * }
 * ```
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
     * @param int         $maxPages   the budget that was exhausted
     * @param string|null $lastCursor cursor of the next, un-fetched page; pass it
     *                                back as `$startCursor` to resume without gaps
     *                                or duplicates
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

    /**
     * The budget that was exhausted.
     */
    public function getMaxPages(): int
    {
        return $this->maxPages;
    }

    /**
     * Cursor of the page that was about to be fetched when the budget ran out.
     *
     * Pass it as `$startCursor` to {@see \CursorWalk\Paginator::pages()} (or as
     * `$pageCursor` to `items()` / `slice()`) to continue the walk.
     */
    public function getLastCursor(): ?string
    {
        return $this->lastCursor;
    }
}
