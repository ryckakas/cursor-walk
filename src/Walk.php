<?php

declare(strict_types=1);

namespace CursorWalk;

use CursorWalk\Exception\PageBudgetExceededException;
use CursorWalk\Exception\PaginationLoopException;

/**
 * The walk policy as a stepper you drive, for callers that cannot let the
 * library call fetchPage() (workflow engines, event loops, Fibers); prefer
 * Paginator otherwise. Single-use: nextCursor() then advance(), once each.
 */
final class Walk
{
    private ?string $cursor;

    private int $fetched = 0;

    /**
     * @var array<string, true>
     */
    private array $seen = [];

    private bool $finished = false;

    private bool $awaitingPage = false;

    /**
     * @param int|null $maxPages maximum cursors this walk may draw; null disables the bound
     */
    public function __construct(?string $startCursor = null, private readonly ?int $maxPages = 10_000)
    {
        $this->cursor = $startCursor;

        // A page pointing back at the resume point is a loop like any other.
        if ($startCursor !== null) {
            $this->seen[$startCursor] = true;
        }
    }

    /**
     * Whether the walk still has data to ask for. Not "the next nextCursor() will
     * succeed": an exhausted budget still reports true and throws on the draw, so
     * it is never mistaken for the end of the stream.
     */
    public function hasNext(): bool
    {
        return !$this->finished;
    }

    /**
     * Draw the cursor for the next fetch, consuming one unit of page budget. The
     * budget is checked before the fetch, so the exception's cursor resumes the
     * walk with no gaps and no duplicates.
     *
     * @throws PageBudgetExceededException when the budget is exhausted
     * @throws \LogicException             if the walk is finished, or the previous page was not advanced
     */
    public function nextCursor(): ?string
    {
        if ($this->finished) {
            throw new \LogicException(
                'This walk is finished; there is no next cursor to draw. A Walk is single-use — '
                . 'check hasNext() before drawing, and construct a new Walk to walk again.',
            );
        }

        if ($this->awaitingPage) {
            throw new \LogicException(
                'nextCursor() was called twice without an advance() in between. A driver must hand '
                . 'each fetched page back before drawing the next cursor.',
            );
        }

        if ($this->maxPages !== null && $this->fetched >= $this->maxPages) {
            throw PageBudgetExceededException::exceeded($this->maxPages, $this->cursor);
        }

        ++$this->fetched;
        $this->awaitingPage = true;

        return $this->cursor;
    }

    /**
     * Hand back the page fetched with the cursor just drawn. Call it after you
     * process the page: a page that trips loop detection still holds valid items,
     * and a per-page checkpoint must include them.
     *
     * @param Page<mixed> $page only endCursor and hasNextPage are read
     *
     * @throws PaginationLoopException if the page hands back a cursor this walk already used
     * @throws \LogicException         if no cursor has been drawn for this page
     */
    public function advance(Page $page): void
    {
        if (!$this->awaitingPage) {
            throw new \LogicException(
                'advance() was called without a cursor having been drawn for this page. '
                . 'The protocol is nextCursor() then advance(), once each, in that order.',
            );
        }

        $this->awaitingPage = false;

        if (!$page->hasNextPage) {
            $this->finished = true;

            return;
        }

        $next = $page->endCursor;

        // Unreachable through Page's constructor, but a Page from unserialize() or
        // reflection skips it, and stopping beats re-fetching one cursor forever.
        if ($next === null || $next === '') {
            $this->finished = true;

            return;
        }

        if (isset($this->seen[$next])) {
            // Finish first, so a driver that swallows the exception cannot keep walking.
            $this->finished = true;

            throw PaginationLoopException::repeatedCursor($next);
        }

        $this->seen[$next] = true;
        $this->cursor = $next;
    }

    /**
     * How many cursors this walk has drawn. Counted at nextCursor(), so a driver
     * that dies before advance() over-counts by one, the safe direction for a budget.
     */
    public function pagesFetched(): int
    {
        return $this->fetched;
    }
}
