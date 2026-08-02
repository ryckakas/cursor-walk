<?php

declare(strict_types=1);

namespace CursorWalk\Tests\Relay;

use CursorWalk\CursorCodec;
use CursorWalk\Page;
use CursorWalk\Paginator;
use CursorWalk\Relay\ConnectionFormatter;
use CursorWalk\Relay\EdgeCursorStrategy;
use CursorWalk\Relay\OffsetEdgeCursorStrategy;
use CursorWalk\Tests\Support\ArrayFetcher;
use CursorWalk\Tests\Support\RecordingEdgeCursorStrategy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Relay connection formatter.
 *
 * ============================================================================
 * INTEGRATION NOTES — API assumptions baked into this file
 * ============================================================================
 * Notes 1-3 were written before src landed and have since been VERIFIED against
 * `src/Relay/ConnectionFormatter.php`, `EdgeCursorStrategy.php` and
 * `OffsetEdgeCursorStrategy.php` — they all hold. Note 4 records a conflict that
 * was found and resolved in favour of src.
 *
 * 1. STRATEGY INJECTION. `ConnectionFormatter` receives its `EdgeCursorStrategy`
 *    through the CONSTRUCTOR, with an optional default:
 *        new ConnectionFormatter()          -> OffsetEdgeCursorStrategy
 *        new ConnectionFormatter($strategy) -> $strategy
 *    Every custom-strategy formatter here is built by {@see self::formatterWith()}
 *    — that is the single call site to change if src differs.
 *
 * 2. PRODUCING PAGE CURSOR. For per-edge cursors to be RESUMABLE they must encode
 *    the cursor that PRODUCED the page, not `$page->endCursor` (which produces the
 *    NEXT page). We assume src threads it as an optional named parameter
 *    `pageStartCursor` on both `ConnectionFormatter::format()` and
 *    `EdgeCursorStrategy::cursorForEdge()`. Every call that needs it goes through
 *    {@see self::formatFrom()} — the single call site to change if the parameter
 *    ended up named differently (e.g. $producingCursor / $pageCursor).
 *
 * 3. EDGE CURSOR SEMANTICS. An edge cursor addresses the position AFTER that edge
 *    (Relay `after:` semantics): decoding edge `i`'s cursor must yield
 *    `[$producingPageCursor, $i + 1]`. Case 19 is the specification for this.
 *    Confirmed: `OffsetEdgeCursorStrategy::cursorForEdge()` encodes `index + 1`.
 *
 * 4. RESOLVED — `pageInfo.endCursor` IS `Page::$endCursor`, NOT the last edge's
 *    cursor. These tests originally asserted the strict-Relay reading (endCursor
 *    == last edge cursor); src deliberately emits the page-level anchor instead,
 *    with a documented rationale: for a multi-page `slice()` every edge is
 *    anchored to the slice START, so resuming from the last EDGE cursor would
 *    replay up to `first` items, whereas `Page::$endCursor` is anchored to the
 *    last upstream page touched and keeps "load more" O(1). Both denote the same
 *    logical position; only src's is cheap to resume from. The spec (section 4.5
 *    / test case 12) constrains the KEYS, not this value, so the tests were
 *    aligned to src. `startCursor` remains the first edge's cursor.
 * ============================================================================
 */
final class ConnectionFormatterTest extends TestCase
{
    private const ALPHABET = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i'];

    // ------------------------------------------------------------------
    // Case 12 — output shape matches the Relay spec keys exactly
    // ------------------------------------------------------------------

    #[Test]
    public function outputShapeMatchesRelaySpecKeysExactly(): void
    {
        // Case 12: top-level key list, pageInfo key set, edge key set.
        $formatter = new ConnectionFormatter();
        $page = $this->pageOf(['a', 'b', 'c'], 'next-page-cursor', true, 42);

        $result = $formatter->format($page);

        // `totalCount` is a SIBLING of edges/pageInfo, not nested inside either.
        self::assertSame(['edges', 'pageInfo', 'totalCount'], array_keys($result));

        $pageInfo = self::pageInfoOf($result);

        // INTEGRATION NOTE: key ORDER inside pageInfo is not semantically
        // meaningful, so this asserts the key SET only. A different order in src
        // is fine; a different SET is a src bug.
        self::assertEqualsCanonicalizing(
            ['endCursor', 'hasNextPage', 'startCursor', 'hasPreviousPage'],
            array_keys($pageInfo),
        );
        self::assertCount(4, $pageInfo);
        self::assertArrayHasKey('endCursor', $pageInfo);
        self::assertArrayHasKey('hasNextPage', $pageInfo);
        self::assertArrayHasKey('startCursor', $pageInfo);
        self::assertArrayHasKey('hasPreviousPage', $pageInfo);

        // Every edge is exactly {node, cursor} — no extras, no omissions.
        $edges = self::edgesOf($result);
        self::assertCount(3, $edges);

        foreach ($edges as $i => $edge) {
            self::assertEqualsCanonicalizing(['node', 'cursor'], array_keys($edge), "edge #{$i} key set");
            self::assertCount(2, $edge, "edge #{$i} must have exactly 2 keys");
        }

        self::assertSame(['a', 'b', 'c'], self::nodesOf($result));
        self::assertArrayHasKey('totalCount', $result);
        self::assertSame(42, self::valueAt($result, 'totalCount'));
    }

    #[Test]
    public function edgeCursorsAreNonEmptyStringsAndUniqueWithinThePage(): void
    {
        // Case 12: edge cursors are non-empty strings, unique per page.
        $formatter = new ConnectionFormatter();
        $page = $this->pageOf(['a', 'b', 'c', 'd'], 'next-page-cursor', true);

        $cursors = self::cursorsOf($formatter->format($page));

        self::assertCount(4, $cursors);

        foreach ($cursors as $i => $cursor) {
            self::assertNotSame('', $cursor, "edge #{$i} cursor must not be empty");
        }

        self::assertSame(
            $cursors,
            array_values(array_unique($cursors)),
            'edge cursors must be unique within a page',
        );
    }

    #[Test]
    public function pageInfoStartCursorIsTheFirstEdgeAndEndCursorIsThePageResumeAnchor(): void
    {
        // Case 12: startCursor = FIRST edge cursor (per the Relay spec);
        // endCursor = Page::$endCursor, the library's canonical resume anchor.
        // See RESOLVED NOTE #4 in the class docblock for why endCursor is NOT
        // re-derived from the last edge.
        $formatter = new ConnectionFormatter();
        $page = $this->pageOf(['a', 'b', 'c', 'd'], 'next-page-cursor', true);

        $result = $formatter->format($page);
        $cursors = self::cursorsOf($result);
        $pageInfo = self::pageInfoOf($result);

        self::assertSame($cursors[0], $pageInfo['startCursor'], 'startCursor must be the FIRST edge cursor');
        self::assertSame(
            'next-page-cursor',
            $pageInfo['endCursor'],
            'endCursor must be the page-level resume anchor (Page::$endCursor)',
        );
    }

    #[Test]
    #[DataProvider('hasNextPageProvider')]
    public function hasNextPageMirrorsThePageAndHasPreviousPageIsAlwaysFalse(bool $hasNextPage): void
    {
        // Case 12: hasNextPage mirrors Page::$hasNextPage; hasPreviousPage is false in v1.
        $formatter = new ConnectionFormatter();
        $page = $this->pageOf(['a', 'b'], $hasNextPage ? 'next-page-cursor' : null, $hasNextPage);

        $pageInfo = self::pageInfoOf($formatter->format($page));

        self::assertSame($hasNextPage, $pageInfo['hasNextPage']);
        self::assertFalse($pageInfo['hasPreviousPage'], 'hasPreviousPage is always false in v1');
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function hasNextPageProvider(): array
    {
        return [
            'more pages follow' => [true],
            'last page' => [false],
        ];
    }

    #[Test]
    public function totalCountKeyIsAbsentWhenThePageHasNoTotalCount(): void
    {
        // Case 12: the key must be ABSENT, not present-and-null.
        $formatter = new ConnectionFormatter();
        $page = $this->pageOf(['a', 'b'], 'next-page-cursor', true, null);

        $result = $formatter->format($page);

        self::assertSame(['edges', 'pageInfo'], array_keys($result));
        self::assertArrayNotHasKey('totalCount', $result);
    }

    #[Test]
    public function totalCountOfZeroIsEmittedRatherThanDroppedAsFalsy(): void
    {
        // Case 12: 0 is a real total, not "no total".
        $formatter = new ConnectionFormatter();
        $page = $this->pageOf([], null, false, 0);

        $result = $formatter->format($page);

        self::assertArrayHasKey('totalCount', $result, 'totalCount of 0 must not be dropped as falsy');
        self::assertSame(0, self::valueAt($result, 'totalCount'));
        self::assertSame(['edges', 'pageInfo', 'totalCount'], array_keys($result));
    }

    #[Test]
    public function emptyPageProducesAnEmptyConnectionWithNullBoundaryCursors(): void
    {
        // Case 12: Page::empty() formats to a well-formed empty connection.
        $formatter = new ConnectionFormatter();

        $result = $formatter->format(Page::empty());

        self::assertSame([], self::edgesOf($result));
        self::assertArrayNotHasKey('totalCount', $result);

        $pageInfo = self::pageInfoOf($result);
        self::assertNull($pageInfo['startCursor'], 'an empty page has no first edge to point at');
        self::assertNull($pageInfo['endCursor'], 'a terminal empty page has no resume anchor');
        self::assertFalse($pageInfo['hasNextPage']);
        self::assertFalse($pageInfo['hasPreviousPage']);
    }

    // ------------------------------------------------------------------
    // Case 13 — nodeMapper transforms nodes
    // ------------------------------------------------------------------

    #[Test]
    public function nodeMapperTransformsEveryNode(): void
    {
        // Case 13: the mapper's return value becomes the edge node.
        $formatter = new ConnectionFormatter();

        $strings = $formatter->format(
            $this->pageOf(['a', 'b', 'c'], 'next-page-cursor', true),
            strtoupper(...),
        );
        self::assertSame(['A', 'B', 'C'], self::nodesOf($strings));

        $records = $formatter->format(
            $this->pageOf(self::people(), 'next-page-cursor', true),
            self::nameOf(...),
        );
        self::assertSame(['alpha', 'bravo', 'charlie'], self::nodesOf($records));
    }

    #[Test]
    public function nodeMapperLeavesEdgeCursorsAndPageInfoUntouched(): void
    {
        // Case 13: cursors address positions, not node contents.
        $formatter = new ConnectionFormatter();
        $page = $this->pageOf(self::people(), 'next-page-cursor', true);

        $unmapped = $formatter->format($page);
        $mapped = $formatter->format($page, self::idOf(...));

        self::assertSame(
            self::cursorsOf($unmapped),
            self::cursorsOf($mapped),
            'a nodeMapper must not change edge cursors',
        );
        self::assertSame(
            self::pageInfoOf($unmapped),
            self::pageInfoOf($mapped),
            'a nodeMapper must not change pageInfo',
        );
        self::assertSame([1, 2, 3], self::nodesOf($mapped));
        self::assertSame(self::people(), self::nodesOf($unmapped));
    }

    #[Test]
    public function nodeMapperIsCalledExactlyOncePerItemInOrder(): void
    {
        // Case 13: no double-mapping, no skipped items, source order preserved.
        $formatter = new ConnectionFormatter();
        $page = $this->pageOf(self::people(), 'next-page-cursor', true);

        /** @var list<mixed> $seen */
        $seen = [];

        $mapper = static function (mixed $person) use (&$seen): string {
            $seen[] = $person;

            self::assertIsArray($person);
            self::assertArrayHasKey('name', $person);
            self::assertIsString($person['name']);

            return $person['name'];
        };

        $result = $formatter->format($page, $mapper);

        self::assertCount(3, $seen, 'the mapper must be called exactly once per item');
        self::assertSame(self::people(), $seen, 'the mapper must receive the raw items, in page order');
        self::assertSame(['alpha', 'bravo', 'charlie'], self::nodesOf($result));
    }

    #[Test]
    public function nodeMapperIsNeverCalledForAnEmptyPage(): void
    {
        // Case 13: nothing to map means nothing to call.
        $formatter = new ConnectionFormatter();

        /** @var list<mixed> $seen */
        $seen = [];

        $mapper = static function (mixed $item) use (&$seen): mixed {
            $seen[] = $item;

            return $item;
        };

        $result = $formatter->format(Page::empty(), $mapper);

        self::assertSame([], $seen, 'the mapper must not be invoked for an empty page');
        self::assertSame([], self::edgesOf($result));
    }

    // ------------------------------------------------------------------
    // Case 14 — custom EdgeCursorStrategy is honored
    // ------------------------------------------------------------------

    #[Test]
    public function customEdgeCursorStrategySuppliesEveryEdgeCursor(): void
    {
        // Case 14: the injected strategy fully controls edge cursors.
        $strategy = new RecordingEdgeCursorStrategy();
        $formatter = self::formatterWith($strategy);
        $page = $this->pageOf(['a', 'b', 'c', 'd'], 'next-page-cursor', true);

        $result = $formatter->format($page);

        self::assertSame(['edge-0', 'edge-1', 'edge-2', 'edge-3'], self::cursorsOf($result));
        self::assertSame(['a', 'b', 'c', 'd'], self::nodesOf($result), 'the strategy must not disturb the nodes');
    }

    #[Test]
    public function pageInfoStartCursorComesFromTheCustomStrategy(): void
    {
        // Case 14: startCursor is computed from the first EDGE, so a custom
        // strategy controls it. endCursor stays the page-level resume anchor and
        // is therefore untouched by the strategy (RESOLVED NOTE #4).
        $strategy = new RecordingEdgeCursorStrategy();
        $formatter = self::formatterWith($strategy);
        $page = $this->pageOf(['a', 'b', 'c', 'd'], 'next-page-cursor', true);

        $pageInfo = self::pageInfoOf($formatter->format($page));

        self::assertSame('edge-0', $pageInfo['startCursor'], 'startCursor must come from the custom strategy');
        self::assertSame(
            'next-page-cursor',
            $pageInfo['endCursor'],
            'endCursor is the page resume anchor and is not produced by the edge strategy',
        );
    }

    #[Test]
    public function customEdgeCursorStrategyReceivesSequentialIndexesAndTheSamePageInstance(): void
    {
        // Case 14: indexes are 0..n-1 in order, and the strategy sees the very
        // Page instance being formatted.
        $strategy = new RecordingEdgeCursorStrategy();
        $formatter = self::formatterWith($strategy);
        $page = $this->pageOf(['a', 'b', 'c', 'd'], 'next-page-cursor', true);

        $formatter->format($page);

        self::assertSame([0, 1, 2, 3], $strategy->indexes);
        self::assertCount(4, $strategy->pages);

        foreach ($strategy->pages as $i => $seenPage) {
            self::assertSame($page, $seenPage, "strategy call #{$i} must receive the Page being formatted");
        }
    }

    #[Test]
    public function customEdgeCursorStrategyIsNotConsultedForAnEmptyPage(): void
    {
        // Case 14: no edges, no strategy calls.
        $strategy = new RecordingEdgeCursorStrategy();

        $result = self::formatterWith($strategy)->format(Page::empty());

        self::assertSame([], $strategy->indexes);
        self::assertSame([], self::edgesOf($result));
    }

    // ------------------------------------------------------------------
    // Case 19 — end-to-end Relay round trip
    // ------------------------------------------------------------------

    /**
     * @param list<string> $expectedTail
     */
    #[Test]
    #[DataProvider('roundTripProvider')]
    public function relayRoundTripResumesStrictlyAfterTheChosenEdge(
        ?string $producingCursor,
        int $edgeIndex,
        string $expectedNode,
        array $expectedTail,
    ): void {
        // Case 19: fetch a page -> format it -> take an edge cursor -> decode it
        // -> feed it back to Paginator::items() -> get exactly the tail that
        // follows the chosen edge.
        //
        // CONVENTION UNDER TEST: an edge cursor addresses the position AFTER that
        // edge (Relay `after:` semantics), i.e. decoding edge `i`'s cursor yields
        // skip === i + 1 relative to the cursor that PRODUCED the page.
        $fetcher = new ArrayFetcher(self::ALPHABET, pageSize: 4);
        $formatter = new ConnectionFormatter();

        $page = $fetcher->fetchPage($producingCursor);
        $result = $this->formatFrom($formatter, $page, $producingCursor);

        self::assertSame(
            $expectedNode,
            self::nodesOf($result)[$edgeIndex],
            'precondition: the chosen edge must hold the expected node',
        );

        $edgeCursor = self::cursorsOf($result)[$edgeIndex];
        [$resumeCursor, $skip] = (new CursorCodec())->decode($edgeCursor);

        // INTEGRATION NOTE: a mid-page edge cursor must carry a NON-ZERO skip.
        // If src encodes offset `i` instead of `i + 1`, THIS TEST IS THE
        // SPECIFICATION and src must be adjusted. The resumed list below is the
        // binding assertion; this one only localises the failure.
        self::assertGreaterThan(0, $skip, 'a mid-page edge cursor must encode a non-zero skip');

        /** @var list<string> $resumed */
        $resumed = iterator_to_array((new Paginator())->items($fetcher, $resumeCursor, $skip), false);

        self::assertSame(
            $expectedTail,
            $resumed,
            'resuming from an edge cursor must yield every item strictly AFTER that edge: '
            . 'no duplicates (the edge node itself must not reappear) and no gaps',
        );
        self::assertNotContains(
            $expectedNode,
            $resumed,
            "the chosen node '{$expectedNode}' must not be replayed after resuming from its own cursor",
        );
    }

    /**
     * @param list<string> $expectedTail
     */
    #[Test]
    #[DataProvider('roundTripProvider')]
    public function relayRoundTripAlsoWorksThroughPaginatorSlice(
        ?string $producingCursor,
        int $edgeIndex,
        string $expectedNode,
        array $expectedTail,
    ): void {
        // Case 19: same round trip, resumed with slice() instead of items().
        $fetcher = new ArrayFetcher(self::ALPHABET, pageSize: 4);
        $formatter = new ConnectionFormatter();

        $page = $fetcher->fetchPage($producingCursor);
        $result = $this->formatFrom($formatter, $page, $producingCursor);

        $edgeCursor = self::cursorsOf($result)[$edgeIndex];
        [$resumeCursor, $skip] = (new CursorCodec())->decode($edgeCursor);

        $slice = (new Paginator())->slice($fetcher, 3, $resumeCursor, $skip);

        self::assertSame(
            array_slice($expectedTail, 0, 3),
            $slice->items,
            'slice() resumed from an edge cursor must return the 3 items following that edge',
        );
        self::assertNotContains(
            $expectedNode,
            $slice->items,
            "the chosen node '{$expectedNode}' must not be replayed by slice()",
        );
    }

    /**
     * Data set ['a'..'i'] with pageSize 4:
     *   page 1 (producing cursor null)         -> a b c d
     *   page 2 (producing cursor cursorFor(4)) -> e f g h
     *   page 3 (producing cursor cursorFor(8)) -> i
     *
     * @return array<string, array{0: ?string, 1: int, 2: string, 3: list<string>}>
     */
    public static function roundTripProvider(): array
    {
        return [
            'first page, 2nd of 4 edges' => [null, 1, 'b', ['c', 'd', 'e', 'f', 'g', 'h', 'i']],
            'first page, last edge' => [null, 3, 'd', ['e', 'f', 'g', 'h', 'i']],
            'second page, first edge' => [ArrayFetcher::cursorFor(4), 0, 'e', ['f', 'g', 'h', 'i']],
            'second page, 2nd of 4 edges' => [ArrayFetcher::cursorFor(4), 1, 'f', ['g', 'h', 'i']],
        ];
    }

    // ------------------------------------------------------------------
    // Extra coverage
    // ------------------------------------------------------------------

    #[Test]
    #[DataProvider('producingCursorProvider')]
    public function defaultStrategyEmitsSyntheticCursorsThatTheCodecRecognises(?string $producingCursor): void
    {
        // extra: locks in the OffsetEdgeCursorStrategy <-> CursorCodec pairing.
        // A FOREIGN cursor decodes to [$cursor, 0]; a synthetic one must not.
        $fetcher = new ArrayFetcher(self::ALPHABET, pageSize: 4);
        $formatter = new ConnectionFormatter();

        $page = $fetcher->fetchPage($producingCursor);
        $result = $this->formatFrom($formatter, $page, $producingCursor);

        $edgeCursor = self::cursorsOf($result)[2];
        [$resumeCursor, $skip] = (new CursorCodec())->decode($edgeCursor);

        self::assertNotSame(
            $edgeCursor,
            $resumeCursor,
            'the codec must recognise its own cursor instead of taking the foreign-cursor fallback',
        );
        self::assertSame(
            $producingCursor,
            $resumeCursor,
            'an edge cursor must carry the cursor that PRODUCED its page, not Page::$endCursor',
        );
        self::assertGreaterThan(0, $skip, 'a mid-page edge must decode to a non-zero skip');
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function producingCursorProvider(): array
    {
        return [
            'first page' => [null],
            'mid-stream page' => [ArrayFetcher::cursorFor(4)],
        ];
    }

    #[Test]
    public function defaultStrategyMatchesAnExplicitlyInjectedOffsetEdgeCursorStrategy(): void
    {
        // extra: pins INTEGRATION NOTE #1 — the no-arg constructor must default
        // to OffsetEdgeCursorStrategy.
        $fetcher = new ArrayFetcher(self::ALPHABET, pageSize: 4);
        $page = $fetcher->fetchPage(null);

        $default = $this->formatFrom(new ConnectionFormatter(), $page, null);
        $explicit = $this->formatFrom(self::formatterWith(new OffsetEdgeCursorStrategy()), $page, null);

        self::assertSame($default, $explicit);
    }

    #[Test]
    public function formattingAPageProducedBySliceYieldsASaneConnection(): void
    {
        // extra: slice() pages already carry a SYNTHETIC mid-page endCursor;
        // formatting one must still produce a well-formed connection.
        $fetcher = new ArrayFetcher(self::ALPHABET, pageSize: 4);

        $slice = (new Paginator())->slice($fetcher, 3, null, 2);
        self::assertSame(['c', 'd', 'e'], $slice->items, 'precondition: slice contents');

        $result = (new ConnectionFormatter())->format($slice);

        self::assertSame(['c', 'd', 'e'], self::nodesOf($result));

        $cursors = self::cursorsOf($result);
        self::assertCount(3, $cursors);
        self::assertSame($cursors, array_values(array_unique($cursors)), 'edge cursors must stay unique');

        $pageInfo = self::pageInfoOf($result);
        self::assertSame($cursors[0], $pageInfo['startCursor']);
        self::assertSame(
            $slice->endCursor,
            $pageInfo['endCursor'],
            "a slice's own encoded end position is passed straight through as pageInfo.endCursor",
        );
        self::assertSame($slice->hasNextPage, $pageInfo['hasNextPage']);
        self::assertFalse($pageInfo['hasPreviousPage']);
    }

    // ------------------------------------------------------------------
    // Helpers — deliberately few call sites, see INTEGRATION NOTES above
    // ------------------------------------------------------------------

    /**
     * INTEGRATION NOTE: the producing page cursor is passed BY NAME so that this
     * is the only call site to touch if the src author named the parameter
     * differently (e.g. $producingCursor / $pageCursor) or threaded it another
     * way. Tests that do not exercise resumability call format() directly.
     *
     * @param Page<mixed>                 $page
     * @param null|callable(mixed): mixed $nodeMapper
     *
     * @return array<string, mixed>
     */
    private function formatFrom(
        ConnectionFormatter $formatter,
        Page $page,
        ?string $producingCursor,
        ?callable $nodeMapper = null,
    ): array {
        return $formatter->format($page, $nodeMapper, pageStartCursor: $producingCursor);
    }

    /**
     * INTEGRATION NOTE: single construction site for a custom-strategy formatter
     * (assumption #1 — constructor injection).
     */
    private static function formatterWith(EdgeCursorStrategy $strategy): ConnectionFormatter
    {
        return new ConnectionFormatter($strategy);
    }

    /**
     * @template TItem
     *
     * @param list<TItem> $items
     *
     * @return Page<TItem>
     */
    private function pageOf(
        array $items,
        ?string $endCursor,
        bool $hasNextPage,
        ?int $totalCount = null,
    ): Page {
        return new Page($items, $endCursor, $hasNextPage, $totalCount);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private static function people(): array
    {
        return [
            ['id' => 1, 'name' => 'alpha'],
            ['id' => 2, 'name' => 'bravo'],
            ['id' => 3, 'name' => 'charlie'],
        ];
    }

    /**
     * @param array{id: int, name: string} $person
     */
    private static function nameOf(array $person): string
    {
        return $person['name'];
    }

    /**
     * @param array{id: int, name: string} $person
     */
    private static function idOf(array $person): int
    {
        return $person['id'];
    }

    /**
     * Narrow the formatter's output to a list of edges, asserting the structure
     * on the way through so downstream assertions read cleanly.
     *
     * @return list<array<string, mixed>>
     */
    private static function edgesOf(mixed $connection): array
    {
        self::assertIsArray($connection);
        self::assertArrayHasKey('edges', $connection);

        $edges = $connection['edges'];
        self::assertIsArray($edges);
        self::assertIsList($edges, 'edges must be a JSON array, not an object');

        $out = [];

        foreach ($edges as $edge) {
            self::assertIsArray($edge);
            /** @var array<string, mixed> $edge */
            $out[] = $edge;
        }

        return $out;
    }

    /**
     * Read a top-level key without tripping PHPStan on optional array shapes.
     */
    private static function valueAt(mixed $connection, string $key): mixed
    {
        self::assertIsArray($connection);
        self::assertArrayHasKey($key, $connection);

        return $connection[$key];
    }

    /**
     * @return array<string, mixed>
     */
    private static function pageInfoOf(mixed $connection): array
    {
        self::assertIsArray($connection);
        self::assertArrayHasKey('pageInfo', $connection);

        $pageInfo = $connection['pageInfo'];
        self::assertIsArray($pageInfo);

        /** @var array<string, mixed> $pageInfo */
        return $pageInfo;
    }

    /**
     * @return list<string>
     */
    private static function cursorsOf(mixed $connection): array
    {
        $cursors = [];

        foreach (self::edgesOf($connection) as $i => $edge) {
            self::assertArrayHasKey('cursor', $edge, "edge #{$i} is missing a 'cursor' key");
            self::assertIsString($edge['cursor'], "edge #{$i} cursor must be a string");
            $cursors[] = $edge['cursor'];
        }

        return $cursors;
    }

    /**
     * @return list<mixed>
     */
    private static function nodesOf(mixed $connection): array
    {
        $nodes = [];

        foreach (self::edgesOf($connection) as $i => $edge) {
            self::assertArrayHasKey('node', $edge, "edge #{$i} is missing a 'node' key");
            $nodes[] = $edge['node'];
        }

        return $nodes;
    }
}
