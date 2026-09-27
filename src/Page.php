<?php

declare(strict_types=1);

namespace CursorWalk;

use CursorWalk\Exception\MalformedPageException;

/**
 * One page from a paginated upstream; `$endCursor` fetches the NEXT page, not
 * this one. Every state is legal except hasNextPage=true with a null or empty
 * endCursor. Stricter upstream policy belongs in the fetcher, not here.
 *
 * @template-covariant T
 */
final class Page implements \Countable
{
    /**
     * @param list<T> $items
     *
     * @throws MalformedPageException if `$hasNextPage` is true without a usable cursor, or `$items` is not a list
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
     * @return self<never>
     */
    public static function empty(): self
    {
        return new self([], null, false);
    }

    /**
     * Whether this page has no items. An empty page may still have hasNextPage=true.
     */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Items on this page, not the stream total, which is `$totalCount`.
     */
    public function count(): int
    {
        return \count($this->items);
    }
}
