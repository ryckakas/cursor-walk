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
 * Tests for the page-number fetcher base class.
 *
 * The claim under test is the one that made Proposal A cheap: a page number is a
 * legal opaque cursor, so the whole engine works over a page-numbered upstream
 * with no engine change at all. Every test here drives the real `Paginator`.
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
     * The whole walk's cursor sequence, asserted in one go rather than page by page.
     *
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

    // ------------------------------------------------------------------
    // Simple — the happy path, on every terminal condition
    // ------------------------------------------------------------------

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

    // ------------------------------------------------------------------
    // Zero / One
    // ------------------------------------------------------------------

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

    // ------------------------------------------------------------------
    // Many
    // ------------------------------------------------------------------

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

    // ------------------------------------------------------------------
    // Boundaries — the classic off-by-one
    // ------------------------------------------------------------------

    #[Test]
    public function aLastPageHoldingExactlyPageSizeRowsCostsNoExtraFetchWhenTotalsAreReported(): void
    {
        // 6 rows at page size 3: the last page is full, so "short page means the
        // end" cannot help. totalPages / totalItems settle it on the page itself.
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
        // The documented cost of the weakest terminal condition: one wasted round
        // trip, never a missed row.
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

    // ------------------------------------------------------------------
    // Resuming — positions are absolute, not walk-relative
    // ------------------------------------------------------------------

    #[Test]
    #[DataProvider('reportingProvider')]
    public function aWalkResumedFromAPageNumberContinuesWithNoGapsOrDuplicates(TotalsReporting $reporting): void
    {
        $all = self::rows(10);
        $fetcher = new PageNumberApiFetcher($all, 3, $reporting);

        // '3' is the cursor page 2 handed out; the resumed walk must start there.
        $resumed = iterator_to_array((new Paginator())->items($fetcher, '3'), false);

        self::assertSame(\array_slice($all, 6), $resumed);
        self::assertSame(3, $fetcher->requested()[0], 'the resumed walk must begin at the checkpointed page');
    }

    #[Test]
    public function totalItemsAccountingStaysCorrectOnAResumedWalk(): void
    {
        // The itemsThrough figure fed to OffsetPage is ABSOLUTE. If it were counted
        // from the start of this walk instead, a resumed walk would think it had
        // barely begun and would keep fetching past the end of the data.
        $fetcher = new PageNumberApiFetcher(self::rows(9), 3, TotalsReporting::TotalItems);

        $resumed = iterator_to_array((new Paginator())->items($fetcher, '3'), false);

        self::assertSame(['r7', 'r8', 'r9'], $resumed);
        self::assertSame([3], $fetcher->requested(), 'page 3 is the last page; nothing may follow it');
    }

    // ------------------------------------------------------------------
    // Interfaces — the engine's own surface over a page-numbered upstream
    // ------------------------------------------------------------------

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
        // The Interfaces case: page-number cursors have to survive the same
        // resolver round trip as a genuine opaque cursor — format a slice, send an
        // edge cursor back as `after`, decode it, resume exactly after that edge.
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
        // Same round trip one page in, where the anchor is a real page number
        // rather than null — the case that proves a bare integer survives being
        // wrapped by CursorCodec and handed back by a Relay client.
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

    // ------------------------------------------------------------------
    // Exceptions
    // ------------------------------------------------------------------

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
        // The realistic mistake: handing the resolver's raw `after` string straight
        // to the fetcher instead of decoding it first.
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

    #[Test]
    public function aRunawayEnvelopeIsStoppedByThePageBudgetBecauseLoopDetectionCannotFire(): void
    {
        // The honesty clause, executed: page numbers never repeat, so
        // PaginationLoopException is unreachable for this fetcher and the budget is
        // the only thing left. This test is the reason the class docblock says so.
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
