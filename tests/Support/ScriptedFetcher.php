<?php

declare(strict_types=1);

namespace CursorWalk\Tests\Support;

use CursorWalk\Page;
use CursorWalk\PaginatedFetcher;
use LogicException;

/**
 * Returns pre-scripted {@see Page} objects, one per call, ignoring the cursor. For upstream shapes
 * {@see ArrayFetcher} cannot express: empty mid-stream pages, repeating cursors, trailing end cursors.
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
     * @param list<Page<T>> $pages
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
