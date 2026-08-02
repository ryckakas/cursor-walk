<?php

declare(strict_types=1);

namespace CursorWalk\Tests\Support;

use CursorWalk\Page;
use CursorWalk\PaginatedFetcher;
use LogicException;

/**
 * Returns a pre-scripted sequence of {@see Page} objects, one per call,
 * regardless of the cursor it is handed.
 *
 * Use this to model upstream shapes that are awkward to express with
 * {@see ArrayFetcher}: empty mid-stream pages, cursor sequences that repeat,
 * trailing end cursors on final pages, and so on.
 *
 * @template T
 *
 * @implements PaginatedFetcher<T>
 */
final class ScriptedFetcher implements PaginatedFetcher
{
    private int $index = 0;

    /** @var list<?string> */
    private array $cursors = [];

    /**
     * @param list<Page<T>> $pages pages to return, in call order
     */
    public function __construct(private readonly array $pages)
    {
    }

    /**
     * @return Page<T>
     */
    public function fetchPage(?string $cursor): Page
    {
        $this->cursors[] = $cursor;

        if (!isset($this->pages[$this->index])) {
            throw new LogicException(sprintf(
                'ScriptedFetcher exhausted: fetchPage() called %d time(s) but only %d page(s) were scripted.',
                $this->index + 1,
                count($this->pages),
            ));
        }

        return $this->pages[$this->index++];
    }

    public function callCount(): int
    {
        return count($this->cursors);
    }

    /**
     * @return list<?string>
     */
    public function cursors(): array
    {
        return $this->cursors;
    }
}
