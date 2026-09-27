<?php

declare(strict_types=1);

namespace CursorWalk\Tests\Offset;

use CursorWalk\CursorCodec;
use CursorWalk\Exception\MalformedPageException;
use CursorWalk\Exception\PageBudgetExceededException;
use CursorWalk\Exception\PaginationLoopException;
use CursorWalk\Offset\OffsetPage;
use CursorWalk\Offset\PageNumberFetcher;
use CursorWalk\Page;
use CursorWalk\Paginator;
use CursorWalk\Relay\ConnectionFormatter;
use CursorWalk\Tests\Support\PageNumberApiFetcher;
use CursorWalk\Tests\Support\TotalsReporting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A page number is a legal opaque cursor, so the engine works over a page-numbered
 * upstream unchanged. Every test drives the real `Paginator` to prove it.
 */
final class PageNumberFetcherTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function rows(int $count): array
    {
        $rows = [];

        for ($i = 1; $i <= $count; ++$i) {
            $rows[] = 'r' . $i;
        }

        return $rows;
    }

    /**
     * @return iterable<string, array{0: TotalsReporting}>
     */
    public static function reportingProvider(): iterable
    {
        yield 'envelope reports totalPages' => [TotalsReporting::TotalPages];
        yield 'envelope reports totalItems' => [TotalsReporting::TotalItems];
        yield 'envelope reports neither' => [TotalsReporting::Neither];
    }

    /**
     * @param list<Page<string>> $pages
     *
     * @return list<?string>
     */
    private static function endCursorsOf(array $pages): array
    {
        return array_map(static fn (Page $page): ?string => $page->endCursor, $pages);
    }

    /**
     * @param list<Page<string>> $pages
     *
     * @return list<bool>
     */
    private static function hasNextFlagsOf(array $pages): array
    {
        return array_map(static fn (Page $page): bool => $page->hasNextPage, $pages);
    }

    // Simple

    #[Test]
    #[DataProvider('reportingProvider')]
    public function itemsWalksEveryPageInOrder(TotalsReporting $reporting): void
    {
        $fetcher = new PageNumberApiFetcher(self::rows(7), 3, $reporting);

        $items = iterator_to_array((new Paginator())->items($fetcher), false);

        self::assertSame(self::rows(7), $items);
        self::assertSame(
            [1, 2, 3],
            \array_slice($fetcher->requested(), 0, 3),
            'pages must be requested by ascending page number, starting at 1',
        );
    }

    #[Test]
    #[DataProvider('reportingProvider')]
    public function pagesThreadsTheNextPageNumberAsThePlainCursor(TotalsReporting $reporting): void
    {
        $fetcher = new PageNumberApiFetcher(self::rows(7), 3, $reporting);

        /** @var list<Page<string>> $pages */
        $pages = iterator_to_array((new Paginator())->pages($fetcher), false);

        self::assertSame(self::rows(3), $pages[0]->items);
        self::assertSame(
            ['2', '3', null],
            self::endCursorsOf($pages),
            'each page hands out the next page NUMBER, and the terminal page hands out nothing',
        );
        self::assertSame([true, true, false], self::hasNextFlagsOf($pages));
    }

    // Zero / One

    #[Test]
    #[DataProvider('reportingProvider')]
    public function anEmptyUpstreamCostsOneFetchAndYieldsNothing(TotalsReporting $reporting): void
    {
        $fetcher = new PageNumberApiFetcher([], 3, $reporting);

        $items = iterator_to_array((new Paginator())->items($fetcher), false);

        self::assertSame([], $items);
        self::assertSame(1, $fetcher->callCount(), 'an empty upstream must not be probed twice');
        self::assertSame([1], $fetcher->requested());
    }

    #[Test]
    #[DataProvider('reportingProvider')]
    public function aSingleShortPageTerminatesWithoutASecondFetch(TotalsReporting $reporting): void
    {
        $fetcher = new PageNumberApiFetcher(['only'], 3, $reporting);

        /** @var list<Page<string>> $pages */
        $pages = iterator_to_array((new Paginator())->pages($fetcher), false);

        self::assertCount(1, $pages);
        self::assertSame(['only'], $pages[0]->items);
        self::assertFalse($pages[0]->hasNextPage);
        self::assertSame(1, $fetcher->callCount());
    }

    // Many

    #[Test]
    #[DataProvider('reportingProvider')]
    public function aLongUpstreamWalksEveryPageExactlyOnce(TotalsReporting $reporting): void
    {
        $fetcher = new PageNumberApiFetcher(self::rows(250), 7, $reporting);

        $items = iterator_to_array((new Paginator())->items($fetcher), false);

        self::assertSame(self::rows(250), $items);
        self::assertSame(
            range(1, 36),
            \array_slice($fetcher->requested(), 0, 36),
            '250 rows at page size 7 spans 36 pages, requested in order and never twice',
        );
    }

    // Boundaries — the classic off-by-one

    #[Test]
    public function aLastPageHoldingExactlyPageSizeRowsCostsNoExtraFetchWhenTotalsAreReported(): void
    {
        // The last page is full, so "a short page is the last one" cannot help; the
        // reported total settles it on the page itself.
        foreach ([TotalsReporting::TotalPages, TotalsReporting::TotalItems] as $reporting) {
            $fetcher = new PageNumberApiFetcher(self::rows(6), 3, $reporting);

            $items = iterator_to_array((new Paginator())->items($fetcher), false);

            self::assertSame(self::rows(6), $items, $reporting->name);
            self::assertSame(2, $fetcher->callCount(), $reporting->name . ': the reported total ends the walk');
        }
    }

    #[Test]
    public function aLastPageHoldingExactlyPageSizeRowsCostsOneEmptyProbeWithoutTotals(): void
    {
        $fetcher = new PageNumberApiFetcher(self::rows(6), 3, TotalsReporting::Neither);

        $items = iterator_to_array((new Paginator())->items($fetcher), false);

        self::assertSame(self::rows(6), $items, 'no row may be lost to the extra probe');
        self::assertSame(3, $fetcher->callCount());
        self::assertSame([1, 2, 3], $fetcher->requested(), 'page 3 is the empty probe that ends the walk');
    }

    #[Test]
    #[DataProvider('reportingProvider')]
    public function aDatasetOfExactlyOnePageIsHandledWithoutClaimingASecond(TotalsReporting $reporting): void
    {
        $fetcher = new PageNumberApiFetcher(self::rows(3), 3, $reporting);

        /** @var list<Page<string>> $pages */
        $pages = iterator_to_array((new Paginator())->pages($fetcher), false);

        self::assertSame(self::rows(3), $pages[0]->items);

        // Without a reported total, a page filled to the requested size is
        // indistinguishable from a mid-stream one, so it costs one empty probe.
        self::assertSame(
            $reporting === TotalsReporting::Neither ? [true, false] : [false],
            self::hasNextFlagsOf($pages),
        );
        self::assertSame($reporting === TotalsReporting::Neither ? 2 : 1, $fetcher->callCount());
    }

    #[Test]
    public function aPageSizeOfExactlyOneIsTheSmallestLegalValueAndIsAccepted(): void
    {
        $fetcher = new PageNumberApiFetcher(self::rows(3), 1, TotalsReporting::TotalPages);

        $items = iterator_to_array((new Paginator())->items($fetcher), false);

        self::assertSame(self::rows(3), $items);
        self::assertSame([1, 2, 3], $fetcher->requested());
    }

    // Resuming — positions are absolute, not walk-relative

    #[Test]
    #[DataProvider('reportingProvider')]
    public function aWalkResumedFromAPageNumberContinuesWithNoGapsOrDuplicates(TotalsReporting $reporting): void
    {
        $all = self::rows(10);
        $fetcher = new PageNumberApiFetcher($all, 3, $reporting);

        $resumed = iterator_to_array((new Paginator())->items($fetcher, '3'), false);

        self::assertSame(\array_slice($all, 6), $resumed);
        self::assertSame(3, $fetcher->requested()[0], 'the resumed walk must begin at the checkpointed page');
    }

    #[Test]
    public function totalItemsAccountingStaysCorrectOnAResumedWalk(): void
    {
        // itemsThrough must be absolute: counted from this walk's start, a resumed walk
        // would think it had barely begun and fetch past the end of the data.
        $fetcher = new PageNumberApiFetcher(self::rows(9), 3, TotalsReporting::TotalItems);

        $resumed = iterator_to_array((new Paginator())->items($fetcher, '3'), false);

        self::assertSame(['r7', 'r8', 'r9'], $resumed);
        self::assertSame([3], $fetcher->requested(), 'page 3 is the last page; nothing may follow it');
    }

    /**
     * Four pages of two rows each out of three requested, like a server-side post-filter.
     *
     * @return PageNumberFetcher<string>
     */
    private static function filteringUpstream(TotalsReporting $reporting): PageNumberFetcher
    {
        /** @extends PageNumberFetcher<string> */
        return new class (3, $reporting) extends PageNumberFetcher {
            private const PAGES = 4;

            private const ROWS_PER_PAGE = 2;

            public function __construct(int $pageSize, private readonly TotalsReporting $reporting)
            {
                parent::__construct($pageSize);
            }

            protected function fetchAt(int $position, int $pageSize): OffsetPage
            {
                $rows = $position <= self::PAGES ? ['p' . $position . 'a', 'p' . $position . 'b'] : [];

                return new OffsetPage(
                    $rows,
                    totalItems: $this->reporting === TotalsReporting::TotalItems
                        ? self::PAGES * self::ROWS_PER_PAGE
                        : null,
                    totalPages: $this->reporting === TotalsReporting::TotalPages ? self::PAGES : null,
                );
            }
        };
    }

    /**
     * @return list<string>
     */
    private static function filteredRows(int $throughPage): array
    {
        $rows = [];

        for ($page = 1; $page <= $throughPage; ++$page) {
            $rows[] = 'p' . $page . 'a';
            $rows[] = 'p' . $page . 'b';
        }

        return $rows;
    }

    #[Test]
    public function totalPagesIsTheOnlySignalThatWalksAnUpstreamWithShortMidStreamPages(): void
    {
        // A page count is exact for a page-numbered fetcher however many rows each page holds.
        $items = iterator_to_array(
            (new Paginator())->items(self::filteringUpstream(TotalsReporting::TotalPages)),
            false,
        );

        self::assertSame(self::filteredRows(4), $items);
    }

    #[Test]
    public function theOtherSignalsStopEarlyOnAnUpstreamWithShortMidStreamPages(): void
    {
        // A row count derived from the page number runs ahead of the rows read on short
        // pages, and with no totals a short page looks like the last one.
        $withRowCount = iterator_to_array(
            (new Paginator())->items(self::filteringUpstream(TotalsReporting::TotalItems)),
            false,
        );
        $withNothing = iterator_to_array(
            (new Paginator())->items(self::filteringUpstream(TotalsReporting::Neither)),
            false,
        );

        self::assertSame(
            self::filteredRows(3),
            $withRowCount,
            'by page 3 the derived count claims 8 of 8 rows read, though only 6 were',
        );
        self::assertSame(self::filteredRows(1), $withNothing, 'the first short page reads as terminal');
    }

    // Interfaces

    #[Test]
    public function sliceHonoursAFirstArgumentThatDoesNotDivideThePageSize(): void
    {
        $fetcher = new PageNumberApiFetcher(self::rows(20), 3, TotalsReporting::TotalPages);

        $slice = (new Paginator())->slice($fetcher, 7);

        self::assertSame(self::rows(7), $slice->items);
        self::assertTrue($slice->hasNextPage);
        self::assertSame(3, $fetcher->callCount(), 'seven rows at page size three spans exactly three pages');
    }

    #[Test]
    public function sliceRoundTripsThroughTheRelayFormatterOverAPageNumberedUpstream(): void
    {
        $rows = self::rows(20);
        $fetcher = new PageNumberApiFetcher($rows, 4, TotalsReporting::TotalItems);
        $paginator = new Paginator();
        $codec = new CursorCodec();

        $slice = $paginator->slice($fetcher, 5);
        $connection = (new ConnectionFormatter())->format($slice, null, $codec->encode(null, 0));

        self::assertSame($rows[0], $connection['edges'][0]['node']);
        self::assertTrue($connection['pageInfo']['hasNextPage']);
        self::assertSame(20, $connection['totalCount'] ?? null, 'totalItems must surface as totalCount');

        $afterThirdEdge = $connection['edges'][2]['cursor'];
        [$pageCursor, $skip] = $codec->decode($afterThirdEdge);

        self::assertNull($pageCursor, 'a slice taken from the origin anchors its edges at the first page');
        self::assertSame(3, $skip);

        $next = $paginator->slice($fetcher, 5, $pageCursor, $skip);

        self::assertSame(\array_slice($rows, 3, 5), $next->items, 'resuming must continue right after edge #2');
        self::assertSame([], array_intersect(\array_slice($slice->items, 0, 3), $next->items));
    }

    #[Test]
    public function anEdgeCursorAnchoredToAPageNumberResumesAtTheRightRow(): void
    {
        // Unlike a slice from the origin, the anchor here is a real page number rather
        // than null, so this proves a bare integer survives the CursorCodec wrapping.
        $rows = self::rows(20);
        $fetcher = new PageNumberApiFetcher($rows, 4, TotalsReporting::TotalItems);
        $paginator = new Paginator();
        $codec = new CursorCodec();

        $slice = $paginator->slice($fetcher, 5, '2');
        self::assertSame(\array_slice($rows, 4, 5), $slice->items, 'precondition: page 2 starts at row 5');

        $connection = (new ConnectionFormatter())->format($slice, null, pageStartCursor: '2');
        [$pageCursor, $skip] = $codec->decode($connection['edges'][1]['cursor']);

        self::assertSame('2', $pageCursor, 'the edge cursor must carry the page number that produced its page');
        self::assertSame(2, $skip);

        $resumed = $paginator->slice($fetcher, 3, $pageCursor, $skip);

        self::assertSame(\array_slice($rows, 6, 3), $resumed->items, 'resuming must continue right after edge #1');
    }

    #[Test]
    public function totalItemsSurfacesAsPageTotalCountAndTotalPagesDoesNot(): void
    {
        $withItems = new PageNumberApiFetcher(self::rows(7), 3, TotalsReporting::TotalItems);
        $withPages = new PageNumberApiFetcher(self::rows(7), 3, TotalsReporting::TotalPages);

        self::assertSame(7, $withItems->fetchPage(null)->totalCount);
        self::assertNull(
            $withPages->fetchPage(null)->totalCount,
            'a page count is not a row count and must not be reported as one',
        );
    }

    // Exceptions

    #[Test]
    public function aCursorThatIsNotAPlainIntegerIsRejected(): void
    {
        $fetcher = new PageNumberApiFetcher(self::rows(5), 3);

        $this->expectException(MalformedPageException::class);

        $fetcher->fetchPage('page-two');
    }

    #[Test]
    public function anEncodedSyntheticCursorIsRejectedWithAPointerToTheCodec(): void
    {
        // The realistic mistake: passing the resolver's raw `after` to the fetcher undecoded.
        $fetcher = new PageNumberApiFetcher(self::rows(5), 3);
        $encoded = (new CursorCodec())->encode('2', 1);

        try {
            $fetcher->fetchPage($encoded);

            self::fail('an encoded position must not be mistaken for a page number.');
        } catch (MalformedPageException $exception) {
            self::assertStringContainsString('CursorCodec::decode()', $exception->getMessage());
            self::assertSame($encoded, $exception->getCursor());
        }
    }

    #[Test]
    public function pageZeroIsRejectedBecauseTheWalkIsOneBased(): void
    {
        $fetcher = new PageNumberApiFetcher(self::rows(5), 3);

        $this->expectException(MalformedPageException::class);

        $fetcher->fetchPage('0');
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function illegalPageSizeProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-5];
    }

    #[Test]
    #[DataProvider('illegalPageSizeProvider')]
    public function aPageSizeBelowOneIsACallerBugAndIsRejectedOnConstruction(int $pageSize): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PageNumberApiFetcher(self::rows(5), $pageSize);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function malformedCursorProvider(): iterable
    {
        yield 'trailing newline' => ["5\n"];
        yield 'leading whitespace' => [' 5'];
        yield 'just past the addressable maximum' => [(string) (intdiv(\PHP_INT_MAX, 3))];
        yield 'saturating the int cast' => ['9999999999999999999'];
    }

    #[Test]
    #[DataProvider('malformedCursorProvider')]
    public function aCursorThatWouldOverflowThePositionArithmeticIsRejected(string $cursor): void
    {
        // Page numbers are multiplied by the page size, so they overflow sooner than an
        // offset. Unguarded, the float reaches OffsetPage as a TypeError, after the upstream
        // has already been called with the absurd page number.
        $fetcher = new PageNumberApiFetcher(self::rows(5), 3);

        $this->expectException(MalformedPageException::class);

        $fetcher->fetchPage($cursor);
    }

    #[Test]
    public function theLargestAddressablePageNumberIsStillAccepted(): void
    {
        $fetcher = new PageNumberApiFetcher(self::rows(5), 3);

        $page = $fetcher->fetchPage((string) (intdiv(\PHP_INT_MAX, 3) - 1));

        self::assertSame([], $page->items);
    }

    #[Test]
    public function aRunawayEnvelopeIsStoppedByThePageBudgetBecauseLoopDetectionCannotFire(): void
    {
        /** @extends PageNumberFetcher<string> */
        $endless = new class (3) extends PageNumberFetcher {
            public int $callCount = 0;

            protected function fetchAt(int $position, int $pageSize): OffsetPage
            {
                ++$this->callCount;

                return new OffsetPage(['row-' . $position, 'filler', 'filler']);
            }
        };

        try {
            iterator_to_array((new Paginator(maxPages: 4))->items($endless), false);

            self::fail('an upstream that always claims a full page must be stopped by the budget.');
        } catch (PaginationLoopException) {
            self::fail('page numbers cannot repeat, so loop detection must never be what fires here.');
        } catch (PageBudgetExceededException $exception) {
            self::assertSame(4, $exception->getMaxPages());
            self::assertSame('5', $exception->getLastCursor(), 'the resume cursor is the un-fetched page number');
        }

        self::assertSame(4, $endless->callCount, 'the budget is checked before the fifth fetch, not after it');
    }
}
