<?php

declare(strict_types=1);

namespace CursorWalk;

use CursorWalk\Exception\PageBudgetExceededException;
use CursorWalk\Exception\PaginationLoopException;

/**
 * The walk POLICY, with no opinion on who performs the fetch: budget accounting,
 * cursor threading, repeated-cursor detection and termination.
 *
 * ## Reach for {@see Paginator} first
 *
 * `Paginator` is the answer for essentially every consumer, and it is implemented
 * in terms of this class, so the two cannot drift. Use `Walk` directly ONLY when
 * you cannot let the library call `fetchPage()` for you:
 *
 *  - a workflow engine whose activities must be reached through `yield`
 *    (Temporal, and anything else replay-based);
 *  - an event loop or coroutine runtime (ReactPHP, Amp, Fibers);
 *  - a driver that prefetches or batches fetches itself.
 *
 * Those callers cannot surrender the call site, and before this class existed
 * they had to reimplement every guard above to get pagination at all. There are
 * deliberately no convenience methods here — no `items()`, no `slice()`. If you
 * want those, you want `Paginator`.
 *
 * ## The protocol: strictly alternating
 *
 * ```php
 * $walk = new Walk($resumeCursor, maxPages: 500);
 *
 * while ($walk->hasNext()) {
 *     $result = yield $this->activity->fetchContacts($walk->nextCursor());
 *     // ... process the page ...
 *     $walk->advance(new Page($result->items, $result->endCursor, $result->hasNextPage));
 * }
 * ```
 *
 * `nextCursor()` then `advance()`, once each, in that order, until `hasNext()`
 * reports false. Any other order is a driver bug and throws `\LogicException` —
 * not for general robustness, but for exception hygiene: a tolerant stepper would
 * let a double `advance()` feed the same page into loop detection twice and raise
 * {@see PaginationLoopException}, the "page the on-call engineer" exception, for
 * what is actually a bug in the driver.
 *
 * ## Single-use, and never shared
 *
 * One walk, one `Walk` instance. Drawing from a finished walk throws — create a
 * new one instead of resetting this one. Never share an instance between
 * concurrent walks, and never register it as a service: `Paginator` is the thing
 * you inject, and it stays stateless.
 *
 * (The state here is not new state in the library. In v0.1.0 the same three
 * variables lived as locals in the `Paginator::pages()` generator frame; this
 * class is that frame, named and handed to the caller. What the generator gave
 * for free was misuse-proofing, which the alternation check above replaces.)
 *
 * ## Replay safety
 *
 * Every bit of this object's state derives from the {@see Page} values handed to
 * `advance()`, which a replaying workflow engine reproduces identically — so a
 * replayed walk takes exactly the same decisions. Reconstruct that `Page` on the
 * workflow side, as above, rather than marshalling one through a payload
 * converter: converters instantiate via reflection and bypass the constructor
 * that is the only enforcement point of `Page`'s legal states.
 *
 * A guard exception thrown inside workflow code is yours to convert into
 * whatever your engine treats as non-retryable. Left alone, it will be retried
 * forever.
 */
final class Walk
{
    /**
     * The cursor the next fetch must use; null means "the first page".
     */
    private ?string $cursor;

    private int $fetched = 0;

    /**
     * Cursors already handed out, for repeated-cursor detection. O(pages) memory,
     * the same as v0.1.0's generator-local set.
     *
     * @var array<string, true>
     */
    private array $seen = [];

    /**
     * Set once the walk can produce nothing further: a page reported no more data,
     * or a guard rejected the stream.
     */
    private bool $finished = false;

    /**
     * True between `nextCursor()` and its matching `advance()`.
     */
    private bool $awaitingPage = false;

    /**
     * @param string|null $startCursor resume point; null = from the beginning
     * @param int|null    $maxPages    maximum fetches this walk may draw a cursor
     *                                 for; null disables the bound. See
     *                                 {@see Paginator::__construct()} for how to
     *                                 size it — the reasoning is identical
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
     * Whether another page may be fetched.
     *
     * True before the first fetch, including for an empty upstream: one fetch is
     * always needed to learn there is nothing there.
     */
    public function hasNext(): bool
    {
        return !$this->finished;
    }

    /**
     * Draw the cursor for the next fetch, consuming one unit of page budget.
     *
     * The budget is checked HERE, before the fetch, so the exception carries the
     * cursor of a page nobody has paid for yet — pass it back as `$startCursor` to
     * a fresh `Walk` (or to `Paginator`) to resume with no gaps and no duplicates.
     *
     * @throws PageBudgetExceededException when the budget is exhausted
     * @throws \LogicException             if the walk is finished, or if the
     *                                     previous page has not been handed back
     *                                     via {@see self::advance()} yet
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
     * Hand back the page fetched with the cursor just drawn, so the walk can
     * decide whether to continue and from where.
     *
     * Call this AFTER whatever you do with the page. A page that trips loop
     * detection has then already been processed, which is deliberate: its items
     * are valid data already paid for, and a driver that checkpoints per page must
     * see it, or the position it stores falls behind the data it has.
     *
     * @param Page<mixed> $page the page fetched with the drawn cursor. The item type
     *                          is irrelevant here: a walk reads only `endCursor` and
     *                          `hasNextPage`
     *
     * @throws PaginationLoopException if the page hands back a cursor this walk has
     *                                 already used — an upstream or fetcher bug
     *                                 that would never terminate
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

        // Not reachable through Page's constructor, which rejects hasNextPage=true
        // with a null or empty cursor. It stays because advance() is public: a Page
        // that reached the caller through a payload converter, an unserialize(), or
        // reflection has never run that validation, and without this stop such a
        // page would spin forever re-fetching the same cursor. Terminating is the
        // safe degradation.
        if ($next === null || $next === '') {
            $this->finished = true;

            return;
        }

        if (isset($this->seen[$next])) {
            // Terminal: a loop is neither retryable nor resumable, so a driver that
            // swallows the exception must not be able to keep walking.
            $this->finished = true;

            throw PaginationLoopException::repeatedCursor($next);
        }

        $this->seen[$next] = true;
        $this->cursor = $next;
    }

    /**
     * How many cursors this walk has drawn.
     *
     * Counted at `nextCursor()` rather than at `advance()`, so a driver that draws
     * a cursor and then dies before handing the page back has this over-count by
     * one — the safe direction for a budget.
     */
    public function pagesFetched(): int
    {
        return $this->fetched;
    }
}
