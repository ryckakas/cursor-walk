<?php

declare(strict_types=1);

namespace CursorWalk;

use CursorWalk\Exception\MalformedPageException;

/**
 * One page of results from a paginated upstream.
 *
 * Immutable value object. `$endCursor` is the opaque token used to fetch the
 * NEXT page — it is not a token describing this page's own position. (That
 * asymmetry is why {@see Relay\ConnectionFormatter::format()} accepts the
 * cursor a page was FETCHED with as a separate argument.)
 *
 * ## Legal states
 *
 * Every combination of `$items`, `$endCursor` and `$hasNextPage` is legal
 * EXCEPT `hasNextPage=true` together with a null or empty `$endCursor`: a page
 * that claims more data while providing no way to reach it is unusable, and the
 * constructor rejects it with {@see MalformedPageException::missingCursor()}.
 *
 * | items     | endCursor | hasNextPage | verdict                                          |
 * |-----------|-----------|-------------|--------------------------------------------------|
 * | non-empty | non-empty | true        | ordinary mid-stream page                          |
 * | non-empty | non-empty | false       | final page with a trailing cursor — legal, ignored|
 * | non-empty | null      | false       | ordinary final page                               |
 * | empty     | non-empty | true        | empty mid-stream page — legal, see below          |
 * | empty     | non-empty | false       | terminal page that happens to carry a cursor      |
 * | empty     | null      | false       | terminal empty page, see {@see self::empty()}     |
 * | any       | null / '' | true        | ILLEGAL — throws MalformedPageException           |
 *
 * ### Trailing cursors
 * An upstream that always emits a cursor, even on the last page, is fine. When
 * `hasNextPage=false` the engine stops and ignores `$endCursor`.
 *
 * ### Empty mid-stream pages
 * `items: []` with `hasNextPage: true` is VALID and does occur in the wild —
 * DynamoDB filtered `Query`/`Scan`, and any upstream that post-filters a page
 * server-side, can return a page with no rows plus a continuation token.
 * {@see Paginator::pages()} yields such pages as-is, so per-page checkpointing
 * still observes them; {@see Paginator::items()} transparently continues to the
 * next page, so item iteration never sees the gap.
 *
 * ### Escape hatch for stricter upstreams
 * If YOUR upstream never legitimately returns an empty mid-stream page, that is
 * fetcher policy, not engine policy — there is deliberately no Paginator flag
 * for it. Enforce it where the knowledge lives:
 *
 * ```php
 * if ($rows === [] && $body['has_more']) {
 *     throw MalformedPageException::invalidEnvelope('empty mid-stream page', $cursor, $body);
 * }
 * ```
 *
 * @template-covariant T
 */
final class Page implements \Countable
{
    /**
     * @param list<T>     $items       the page's items, in upstream order
     * @param string|null $endCursor   opaque token for fetching the next page
     * @param bool        $hasNextPage whether the upstream reports more data
     * @param int|null    $totalCount  total across the whole stream, when the
     *                                 upstream exposes it
     *
     * @throws MalformedPageException if `$hasNextPage` is true without a usable
     *                                cursor, or if `$items` is not a list
     */
    public function __construct(
        public readonly array $items,
        public readonly ?string $endCursor,
        public readonly bool $hasNextPage,
        public readonly ?int $totalCount = null,
    ) {
        // @phpstan-ignore function.alreadyNarrowedType (runtime guard for callers without static analysis)
        if (!array_is_list($items)) {
            throw MalformedPageException::invalidEnvelope(
                'items must be a list (sequential integer keys starting at 0)',
                $endCursor,
                array_slice(array_keys($items), 0, 10),
            );
        }

        if ($hasNextPage && ($endCursor === null || $endCursor === '')) {
            throw MalformedPageException::missingCursor();
        }
    }

    /**
     * The terminal empty page: no items, no cursor, no more data.
     *
     * Handy as a fetcher's response when the upstream signals "nothing here"
     * without returning a usable envelope.
     *
     * @return self<never>
     */
    public static function empty(): self
    {
        return new self([], null, false);
    }

    /**
     * Whether this page carries no items. An empty page may still have
     * `hasNextPage=true` — see the class docblock.
     */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Number of items on this page — not the total across the stream, which is
     * `$totalCount`.
     */
    public function count(): int
    {
        return \count($this->items);
    }
}
