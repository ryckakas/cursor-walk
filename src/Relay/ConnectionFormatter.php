<?php

declare(strict_types=1);

namespace CursorWalk\Relay;

use CursorWalk\CursorCodec;
use CursorWalk\Page;

/**
 * Renders a {@see Page} as a Relay Connection-shaped plain array.
 *
 * No dependency on any GraphQL library — the output is arrays, which is exactly
 * what a webonyx/graphql-php resolver returns and what ivome/graphql-relay-php's
 * Connection types consume. This package feeds those shapes from a live
 * paginated source; it does not define the schema.
 *
 * ```php
 * 'orders' => fn ($root, array $args) => (function () use ($args) {
 *     $codec     = new CursorCodec();
 *     [$c, $skip] = $codec->decode($args['after'] ?? '');
 *     $page      = (new Paginator(maxPages: 50))->slice($this->fetcher, $args['first'], $c, $skip);
 *
 *     return (new ConnectionFormatter())->format($page, null, $codec->encode($c, $skip));
 * })(),
 * ```
 */
final class ConnectionFormatter
{
    /**
     * @param EdgeCursorStrategy $edgeCursorStrategy how per-edge cursors are derived
     * @param CursorCodec        $codec              position codec, used to read
     *                                               `$pageStartCursor` when deciding
     *                                               `pageInfo.hasPreviousPage`
     */
    public function __construct(
        private readonly EdgeCursorStrategy $edgeCursorStrategy = new OffsetEdgeCursorStrategy(),
        private readonly CursorCodec $codec = new CursorCodec(),
    ) {
    }

    /**
     * Format one page as a Relay connection array.
     *
     * ```
     * [
     *   'edges' => [ ['node' => mixed, 'cursor' => string], ... ],
     *   'pageInfo' => [
     *     'endCursor' => ?string, 'hasNextPage' => bool,
     *     'startCursor' => ?string, 'hasPreviousPage' => bool,
     *   ],
     *   'totalCount' => int,   // present only when the page carries one
     * ]
     * ```
     *
     * ## `$pageStartCursor`
     *
     * The cursor the page was FETCHED with. It is a separate argument because a
     * {@see Page} deliberately does not carry it: `Page::$endCursor` addresses
     * the NEXT page, and widening `Page` to also carry its own origin would
     * change a documented public value object for the benefit of one optional
     * formatter.
     *
     * Getting it right matters — it is what makes an edge cursor round-trip:
     * `format()` → client sends `edges[i].cursor` back as `after` →
     * `CursorCodec::decode()` → `Paginator::items()/slice()` resumes at item
     * *i+1* with no duplicate and no gap. Pass:
     *  - `null` for a page fetched from the very beginning;
     *  - the raw upstream cursor you passed to `fetchPage()`;
     *  - `CursorCodec::encode($pageCursor, $skip)` for a page produced by
     *    `Paginator::slice($fetcher, $first, $pageCursor, $skip)` — or simply the
     *    original `after` string the client sent, which decodes to the same
     *    position.
     *
     * Omitting it is safe but positions every edge as though the page began the
     * stream, so only do that for a first page. A custom
     * {@see EdgeCursorStrategy} ignores this argument entirely and owns its own
     * cursor semantics; the built-in {@see OffsetEdgeCursorStrategy} is rebound
     * to the anchor for the duration of the call.
     *
     * ## `pageInfo.endCursor`
     *
     * Emitted as `$page->endCursor` — the library's canonical resume anchor —
     * rather than a re-derivation of the last edge's cursor. The two denote the
     * same position, but `$page->endCursor` is anchored to the nearest upstream
     * page (for `slice()` results it is already an exact codec-encoded end
     * position), which keeps successive "load more" round trips O(1) instead of
     * repeatedly replaying from the origin.
     *
     * `startCursor` is the first edge's cursor, per the Relay spec.
     *
     * ## `pageInfo.hasPreviousPage`
     *
     * `true` when `$pageStartCursor` decodes to a position other than the origin —
     * a non-null page anchor, or a non-zero offset into the first page. Both are
     * proof that something precedes this window, and the Relay spec permits
     * reporting `true` whenever the server can determine that efficiently, which
     * here costs one `decode()`.
     *
     * It reads the cursor as the codec does, not as any one fetcher does. A bare
     * positional cursor decodes to a non-null anchor whatever integer it holds, so
     * `'0'` — the {@see \CursorWalk\Offset\OffsetFetcher} origin — reports `true`.
     * Teaching this formatter that some anchors are really origins would put one
     * fetcher's wire format inside the Relay layer; passing `null` for the first
     * page, as the documented recipes do, is the fix on the caller's side.
     *
     * This is NOT backward pagination: it reports a fact the engine already holds
     * and adds no way to travel backwards. Note the argument it depends on —
     * omitting `$pageStartCursor` positions the page as though it began the
     * stream, so `hasPreviousPage` comes back `false` for it, exactly as edge
     * cursors are anchored to the origin.
     *
     * @template T
     *
     * @param Page<T>                 $page
     * @param null|callable(T): mixed $nodeMapper      optional node transform, e.g. hydrating
     *                                                 an array row into an entity
     * @param string|null             $pageStartCursor cursor the page was fetched with
     *
     * @return array{
     *     edges: list<array{node: mixed, cursor: string}>,
     *     pageInfo: array{endCursor: ?string, hasNextPage: bool, startCursor: ?string, hasPreviousPage: bool},
     *     totalCount?: int,
     * }
     */
    public function format(Page $page, ?callable $nodeMapper = null, ?string $pageStartCursor = null): array
    {
        $strategy = $this->edgeCursorStrategy;
        if ($strategy instanceof OffsetEdgeCursorStrategy) {
            $strategy = $strategy->withPageStartCursor($pageStartCursor);
        }

        $edges = [];
        foreach ($page->items as $index => $item) {
            $edges[] = [
                'node' => $nodeMapper === null ? $item : $nodeMapper($item),
                'cursor' => $strategy->cursorForEdge($page, $index),
            ];
        }

        [$anchor, $offset] = $this->codec->decode($pageStartCursor ?? '');

        $connection = [
            'edges' => $edges,
            'pageInfo' => [
                'endCursor' => $page->endCursor,
                'hasNextPage' => $page->hasNextPage,
                'startCursor' => $edges === [] ? null : $edges[0]['cursor'],
                'hasPreviousPage' => $anchor !== null || $offset > 0,
            ],
        ];

        if ($page->totalCount !== null) {
            $connection['totalCount'] = $page->totalCount;
        }

        return $connection;
    }
}
