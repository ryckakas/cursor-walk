<?php

declare(strict_types=1);

namespace CursorWalk\Exception;

/**
 * Thrown when an upstream response cannot be interpreted as a valid page, by
 * Page itself or by your fetcher. The named constructors keep the cursor and a
 * raw payload snippet next to the message for debugging.
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
     * A page reports hasNextPage=true but has no cursor to fetch it with.
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
     * The upstream envelope is unusable, or breaks a policy your fetcher enforces.
     */
    public static function invalidEnvelope(string $reason, ?string $cursor = null, mixed $raw = null): self
    {
        return new self(
            \sprintf('Malformed page: %s', $reason),
            $cursor,
            $raw,
        );
    }

    public function getCursor(): ?string
    {
        return $this->cursor;
    }

    public function getRawContext(): mixed
    {
        return $this->rawContext;
    }
}
