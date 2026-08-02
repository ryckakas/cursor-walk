<?php

declare(strict_types=1);

namespace CursorWalk\Exception;

/**
 * Thrown when an upstream response cannot be interpreted as a valid page.
 *
 * Two sources:
 *  - the library itself, when a {@see \CursorWalk\Page} is constructed in an
 *    illegal state (`hasNextPage=true` with no usable cursor, or a non-list
 *    `items` array);
 *  - your own {@see \CursorWalk\PaginatedFetcher} implementation, when the
 *    upstream envelope is unusable or violates a policy your upstream is known
 *    to respect (for example "this API never returns an empty mid-stream page").
 *
 * Instances are created through the named constructors so that the debug
 * context (the cursor being fetched, plus a raw payload snippet) is always
 * carried alongside the message.
 */
final class MalformedPageException extends CursorWalkException
{
    private function __construct(
        string $message,
        private readonly ?string $cursor,
        private readonly mixed $rawContext,
    ) {
        parent::__construct($message);
    }

    /**
     * A page claims more data (`hasNextPage=true`) but provides no cursor to
     * fetch it with, so pagination cannot continue.
     */
    public static function missingCursor(): self
    {
        return new self(
            'Page reports hasNextPage=true but provides no endCursor; pagination cannot continue.',
            null,
            null,
        );
    }

    /**
     * The upstream envelope could not be interpreted as a page.
     *
     * @param string      $reason  human-readable reason, e.g. "empty mid-stream page"
     * @param string|null $cursor  the cursor that was being fetched when this happened
     * @param mixed       $raw     a raw payload snippet, kept for debugging only
     */
    public static function invalidEnvelope(string $reason, ?string $cursor = null, mixed $raw = null): self
    {
        return new self(
            \sprintf('Malformed page: %s', $reason),
            $cursor,
            $raw,
        );
    }

    /**
     * The cursor that was being fetched when the failure occurred, if known.
     */
    public function getCursor(): ?string
    {
        return $this->cursor;
    }

    /**
     * The raw payload snippet captured for debugging, if any.
     */
    public function getRawContext(): mixed
    {
        return $this->rawContext;
    }
}
