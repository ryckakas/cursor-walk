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

final class ConnectionFormatterTest extends TestCase
{
    private const ALPHABET = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i'];

    // Case 12 — output shape matches the Relay spec keys exactly

    #[Test]
    public function outputShapeMatchesRelaySpecKeysExactly(): void
    {
        $formatter = new ConnectionFormatter();
        $page = $this->pageOf(['a', 'b', 'c'], 'next-page-cursor', true, 42);

        $result = $formatter->format($page);

        self::assertSame(['edges', 'pageInfo', 'totalCount'], array_keys($result));

        $pageInfo = self::pageInfoOf($result);

        // Key order inside pageInfo carries no meaning, so only the key set is asserted.
        self::assertEqualsCanonicalizing(
            ['endCursor', 'hasNextPage', 'startCursor', 'hasPreviousPage'],
            array_keys($pageInfo),
        );
        self::assertCount(4, $pageInfo);
        self::assertArrayHasKey('endCursor', $pageInfo);
        self::assertArrayHasKey('hasNextPage', $pageInfo);
        self::assertArrayHasKey('startCursor', $pageInfo);
        self::assertArrayHasKey('hasPreviousPage', $pageInfo);

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
        // Not the last edge's cursor: slice() anchors every edge to the slice start, so
        // resuming from it would replay items. Page::$endCursor anchors to the last
        // upstream page touched, which keeps "load more" O(1).
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
    public function hasNextPageMirrorsThePage(bool $hasNextPage): void
    {
        $formatter = new ConnectionFormatter();
        $page = $this->pageOf(['a', 'b'], $hasNextPage ? 'next-page-cursor' : null, $hasNextPage);

        $pageInfo = self::pageInfoOf($formatter->format($page));

        self::assertSame($hasNextPage, $pageInfo['hasNextPage']);
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

    // hasPreviousPage — derived from the page's own start position

    /**
     * @return iterable<string, array{0: ?string, 1: bool}>
     */
    public static function pageStartCursorProvider(): iterable
    {
        $codec = new CursorCodec();

        yield 'omitted entirely' => [null, false];
        yield 'the empty string a resolver produces for an absent `after`' => ['', false];
        yield 'an encoded origin with nothing skipped' => [$codec->encode(null, 0), false];
        yield 'an encoded origin with items skipped' => [$codec->encode(null, 3), true];
        yield 'an encoded position inside a later page' => [$codec->encode('page-2-cursor', 0), true];
        yield 'an encoded position mid later page' => [$codec->encode('page-2-cursor', 4), true];
        yield 'a raw upstream cursor' => ['b2Zmc2V0LTQ=', true];
        yield 'a bare page number from CursorWalk\Offset' => ['3', true];
    }

    #[Test]
    #[DataProvider('pageStartCursorProvider')]
    public function hasPreviousPageIsTrueForEveryStartPositionThatIsNotTheOrigin(
        ?string $pageStartCursor,
        bool $expected,
    ): void {
        // `encode(null, 0)` is why this cannot be a bare "non-empty string" check: the
        // documented resolver recipe passes exactly that for a first request.
        $formatter = new ConnectionFormatter();
        $page = $this->pageOf(['a', 'b'], 'next-page-cursor', true);

        $pageInfo = self::pageInfoOf($formatter->format($page, null, $pageStartCursor));

        self::assertSame($expected, $pageInfo['hasPreviousPage']);
    }

    #[Test]
    public function hasPreviousPageIsIndependentOfWhetherThePageItselfHasItems(): void
    {
        $result = (new ConnectionFormatter())->format(Page::empty(), null, 'some-upstream-cursor');
        $pageInfo = self::pageInfoOf($result);

        self::assertSame([], self::edgesOf($result));
        self::assertTrue($pageInfo['hasPreviousPage']);
        self::assertNull($pageInfo['startCursor'], 'an empty page still has no first edge to point at');
    }

    #[Test]
    public function walkingAStreamReportsHasPreviousPageFalseOnlyForTheFirstWindow(): void
    {
        $fetcher = new ArrayFetcher(self::ALPHABET, pageSize: 4);
        $formatter = new ConnectionFormatter();

        $flags = [];
        $fetchedWith = null;

        while (true) {
            $page = $fetcher->fetchPage($fetchedWith);
            $flags[] = self::pageInfoOf($formatter->format($page, null, $fetchedWith))['hasPreviousPage'];

            if (!$page->hasNextPage) {
                break;
            }

            $fetchedWith = $page->endCursor;
        }

        self::assertSame([false, true, true], $flags, 'nine items at page size four is three windows');
    }

    #[Test]
    public function totalCountKeyIsAbsentWhenThePageHasNoTotalCount(): void
    {
        $formatter = new ConnectionFormatter();
        $page = $this->pageOf(['a', 'b'], 'next-page-cursor', true, null);

        $result = $formatter->format($page);

        self::assertSame(['edges', 'pageInfo'], array_keys($result));
        self::assertArrayNotHasKey('totalCount', $result);
    }

    #[Test]
    public function totalCountOfZeroIsEmittedRatherThanDroppedAsFalsy(): void
    {
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

    // Case 13 — nodeMapper transforms nodes

    #[Test]
    public function nodeMapperTransformsEveryNode(): void
    {
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
        // Cursors address positions, not node contents.
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

    // Case 14 — custom EdgeCursorStrategy is honored

    #[Test]
    public function customEdgeCursorStrategySuppliesEveryEdgeCursor(): void
    {
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
        $strategy = new RecordingEdgeCursorStrategy();

        $result = self::formatterWith($strategy)->format(Page::empty());

        self::assertSame([], $strategy->indexes);
        self::assertSame([], self::edgesOf($result));
    }

    // Case 19 — end-to-end Relay round trip

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
        // An edge cursor addresses the position AFTER its edge (Relay `after:`), so edge
        // i decodes to skip i + 1 relative to the cursor that produced the page.
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

        // Only localises an `i` vs `i + 1` encoding bug; the resumed list is the binding assertion.
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

    // Extra coverage

    #[Test]
    #[DataProvider('producingCursorProvider')]
    public function defaultStrategyEmitsSyntheticCursorsThatTheCodecRecognises(?string $producingCursor): void
    {
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
        $fetcher = new ArrayFetcher(self::ALPHABET, pageSize: 4);
        $page = $fetcher->fetchPage(null);

        $default = $this->formatFrom(new ConnectionFormatter(), $page, null);
        $explicit = $this->formatFrom(self::formatterWith(new OffsetEdgeCursorStrategy()), $page, null);

        self::assertSame($default, $explicit);
    }

    #[Test]
    public function formattingAPageProducedBySliceYieldsASaneConnection(): void
    {
        // slice() pages carry a synthetic mid-page endCursor, unlike a raw upstream page.
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
        self::assertFalse(
            $pageInfo['hasPreviousPage'],
            'no $pageStartCursor was passed, so the page is positioned as though it began the stream',
        );
    }

    // Helpers

    /**
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
