<?php

declare(strict_types=1);

namespace CursorWalk;

use CursorWalk\Exception\MalformedPageException;

/**
 * The single integration point you implement.
 *
 * A fetcher knows how to turn one opaque cursor into one {@see Page}. It owns
 * everything transport-specific — HTTP client, auth, retries, rate-limit
 * back-off, response parsing — and it owns upstream-specific strictness policy
 * (see the "escape hatch" note in {@see Page}). The engine owns only the walk.
 *
 * ```php
 * /** @implements PaginatedFetcher<array<string, mixed>> *\/
 * final class OrdersFetcher implements PaginatedFetcher
 * {
 *     public function fetchPage(?string $cursor): Page
 *     {
 *         $body = $this->http->getJson('/orders', $cursor === null ? [] : ['after' => $cursor]);
 *
 *         return new Page(
 *             items:       $body['data'],
 *             endCursor:   $body['next_cursor'] ?? null,
 *             hasNextPage: (bool) ($body['has_more'] ?? false),
 *             totalCount:  $body['total'] ?? null,
 *         );
 *     }
 * }
 * ```
 *
 * @template-covariant T
 */
interface PaginatedFetcher
{
    /**
     * Fetch exactly one page.
     *
     * `$cursor === null` means "fetch the first page". Any other value is an
     * opaque token that this fetcher previously produced as a page's
     * `endCursor`, or that a caller supplied as a resume checkpoint.
     *
     * Implementations MUST be side-effect free with respect to the walk: the
     * engine may call `fetchPage()` with the same cursor across separate walks.
     *
     * @return Page<T>
     *
     * @throws MalformedPageException when the upstream response cannot be
     *                                interpreted as a valid page
     */
    public function fetchPage(?string $cursor): Page;
}
