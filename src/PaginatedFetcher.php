<?php

declare(strict_types=1);

namespace CursorWalk;

use CursorWalk\Exception\MalformedPageException;

/**
 * The single integration point you implement: turn one opaque cursor into one
 * Page. The fetcher owns transport, retries, parsing and any stricter upstream
 * policy; the engine owns only the walk.
 *
 * @template-covariant T
 */
interface PaginatedFetcher
{
    /**
     * Fetch exactly one page; a null cursor means the first page. Must be free of
     * side effects on the walk: the engine may fetch the same cursor again in a
     * separate walk.
     *
     * @return Page<T>
     *
     * @throws MalformedPageException when the upstream response cannot be interpreted as a valid page
     */
    public function fetchPage(?string $cursor): Page;
}
