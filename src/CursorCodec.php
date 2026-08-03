<?php

declare(strict_types=1);

namespace CursorWalk;

/**
 * Encodes and decodes *positions* — a (page cursor, offset) pair — as a single
 * opaque string.
 *
 * ## Why this lives in the core namespace
 *
 * {@see Paginator::slice()} promises an `endCursor` describing the ACTUAL end of
 * the slice, which is frequently in the middle of an upstream page. Position
 * encoding is therefore a core concern; `CursorWalk\Relay\` owns output shape
 * only. The dependency arrow points Relay → core, never the reverse.
 *
 * ## Why positions need encoding at all
 *
 * Most upstreams hand out page-level cursors only. A Relay client, however,
 * sends an individual edge's `cursor` back as `after`, and expects to resume
 * immediately after that edge. Wrapping (page cursor, offset) into one opaque
 * token makes that round trip work instead of merely look Relay-shaped.
 *
 * ## Wire format
 *
 * `base64(json_encode(['v' => 1, 'c' => $pageCursor, 'o' => $offset]))`
 *
 * JSON rather than a delimited string, because upstream cursors are opaque and
 * may legitimately contain any delimiter you might pick (`|`, `:`, `.`). It is
 * also debuggable — `base64 -d` yields readable JSON — and the `v` field gives
 * real versioning for the planned v2 ID-anchored format instead of relying on a
 * magic prefix.
 *
 * ## Cursor stability
 *
 * These cursors are POSITIONAL. They stay correct only while the upstream data
 * does not shift between requests, which is the right tradeoff for short-lived
 * UI pagination and the wrong one for long-lived bookmarks. When your upstream
 * exposes item-level cursors, emit those from your fetcher instead.
 */
final class CursorCodec
{
    /**
     * Current wire-format version. Bumped when the payload shape changes.
     */
    public const VERSION = 1;

    /**
     * Encode a position into an opaque cursor.
     *
     * @param string|null $pageCursor the cursor that the containing page was
     *                                FETCHED with (null = the first page)
     * @param int         $offset     number of items to skip from that page
     *                                onwards to reach this position
     *
     * @throws \JsonException if `$pageCursor` is not valid UTF-8; every cursor
     *                        that survived transport as JSON or an HTTP header
     *                        already is
     */
    public function encode(?string $pageCursor, int $offset): string
    {
        return base64_encode(json_encode(
            ['v' => self::VERSION, 'c' => $pageCursor, 'o' => $offset],
            \JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Decode an incoming `after` value into `[?string $pageCursor, int $skip]`.
     *
     * Foreign-cursor detection runs three gates — valid strict base64, then
     * valid JSON, then an object carrying both `v` and `o`. A value that fails
     * any gate is assumed to be a raw upstream cursor and returned untouched as
     * `[$after, 0]`. This method NEVER throws: an application cannot control
     * what a client sends back, and a raw upstream cursor is a perfectly
     * legitimate `after` value.
     *
     * The empty string is the one exception to pass-through: it decodes to
     * `[null, 0]` — the first page. It is what `$args['after'] ?? ''` produces
     * for an absent argument, and no legitimate flow emits a bare `''` cursor
     * ({@see Page} forbids an empty `endCursor` on a continuing page).
     *
     * @return array{?string, int} the page cursor to fetch, and how many items
     *                             to skip from there
     */
    public function decode(string $after): array
    {
        if ($after === '') {
            return [null, 0];
        }

        // Gate 1: strict base64. Anything with out-of-alphabet bytes is foreign.
        $json = base64_decode($after, true);
        if ($json === false) {
            return [$after, 0];
        }

        // Gate 2: valid JSON.
        try {
            /** @var mixed $data */
            $data = json_decode($json, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [$after, 0];
        }

        // Gate 3: our envelope — an object carrying both 'v' and 'o'.
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
