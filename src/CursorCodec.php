<?php

declare(strict_types=1);

namespace CursorWalk;

/**
 * Encodes a (page cursor, offset) position as one opaque string, so a Relay
 * edge cursor can resume mid-page. The cursors are positional: they stay
 * correct only while the upstream data does not shift between requests.
 */
final class CursorCodec
{
    /**
     * Wire-format version, bumped when the payload shape changes.
     */
    public const VERSION = 1;

    /**
     * @param string|null $pageCursor the cursor the containing page was FETCHED with
     * @param int         $offset     items to skip from that page onwards
     *
     * @throws \JsonException if `$pageCursor` is not valid UTF-8
     */
    public function encode(?string $pageCursor, int $offset): string
    {
        return base64_encode(json_encode(
            ['v' => self::VERSION, 'c' => $pageCursor, 'o' => $offset],
            \JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Decode an `after` value into [pageCursor, skip]. Never throws: anything that
     * is not this codec's envelope is a foreign upstream cursor, returned as
     * [$after, 0]. The empty string decodes to [null, 0], the first page.
     *
     * @return array{?string, int}
     */
    public function decode(string $after): array
    {
        if ($after === '') {
            return [null, 0];
        }

        $json = base64_decode($after, true);
        if ($json === false) {
            return [$after, 0];
        }

        try {
            /** @var mixed $data */
            $data = json_decode($json, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [$after, 0];
        }

        if (!\is_array($data) || !\array_key_exists('v', $data) || !\array_key_exists('o', $data)) {
            return [$after, 0];
        }

        /** @var mixed $offset */
        $offset = $data['o'];
        if (!\is_int($offset) || $offset < 0) {
            return [$after, 0];
        }

        /** @var mixed $pageCursor */
        $pageCursor = $data['c'] ?? null;
        if ($pageCursor !== null && !\is_string($pageCursor)) {
            return [$after, 0];
        }

        return [$pageCursor, $offset];
    }
}
