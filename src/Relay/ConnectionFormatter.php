<?php

declare(strict_types=1);

namespace CursorWalk\Relay;

use CursorWalk\CursorCodec;
use CursorWalk\Page;

/**
 * Renders a Page as a Relay connection array, with no dependency on any GraphQL
 * library: a webonyx/graphql-php resolver can return it as is.
 */
final class ConnectionFormatter
{
    public function __construct(
        private readonly EdgeCursorStrategy $edgeCursorStrategy = new OffsetEdgeCursorStrategy(),
        private readonly CursorCodec $codec = new CursorCodec(),
    ) {
    }

    /**
     * Pass `$pageStartCursor`, the cursor the page was FETCHED with (for a slice(),
     * the client's `after`). Omitted, edge cursors and hasPreviousPage treat the
     * page as the start of the stream, so omit it only for a first page.
     *
     * @template T
     *
     * @param Page<T>                 $page
     * @param null|callable(T): mixed $nodeMapper
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
