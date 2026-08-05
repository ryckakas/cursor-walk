<?php

declare(strict_types=1);

namespace CursorWalk\Tests\Offset;

use CursorWalk\CursorCodec;
use CursorWalk\Exception\MalformedPageException;
use CursorWalk\Exception\PaginationLoopException;
use CursorWalk\Offset\OffsetFetcher;
use CursorWalk\Offset\OffsetPage;
use CursorWalk\Page;
use CursorWalk\Paginator;
use CursorWalk\Relay\ConnectionFormatter;
use CursorWalk\Tests\Support\OffsetApiFetcher;
use CursorWalk\Tests\Support\TotalsReporting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the row-offset fetcher base class.
 *
 * Where {@see PageNumberFetcherTest} proves a page number works as a cursor, this
 * file pins the arithmetic that makes offsets the *preferable* wire format: the
 * next cursor tracks rows actually returned, so it stays correct when the upstream
 * hands back a short page or the page size changes between requests.
 */
final class OffsetFetcherTest extends TestCase
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

    // ------------------------------------------------------------------
    // Simple
    // ------------------------------------------------------------------

    #[Test]
    #[DataProvider('reportingProvider')]
    public function itemsWalksEveryWindowInOrderStartingAtOffsetZero(TotalsReporting $reporting): void
    {
        $fetcher = new OffsetApiFetcher(self::rows(7), 3, $reporting);

        $items = iterator_to_array((new Paginator())->items($fetcher), false);

        self::assertSame(self::rows(7), $items);
        self::assertSame([0, 3, 6], \array_slice($fetcher->requested(), 0, 3));
    }

    #[Test]
    #[DataProvider('reportingProvider')]
    public function pagesThreadsTheNextRowOffsetAsThePlainCursor(TotalsReporting $reporting): void
    {
        $fetcher = new OffsetApiFetcher(self::rows(7), 3, $reporting);

        /** @var list<Page<string>> $pages */
        $pages = iterator_to_array((new Paginator())->pages($fetcher), false);

        self::assertSame(
            ['3', '6', null],
            array_map(static fn (Page $page): ?string => $page->endCursor, $pages),
            'each cursor is the offset of the next unread row; the terminal page has none',
        );
        self::assertSame(
            [true, true, false],
            array_map(static fn (Page $page): bool => $page->hasNextPage, $pages),
        );
    }

    // ------------------------------------------------------------------
    // The offset-specific arithmetic
    // ------------------------------------------------------------------

    #[Test]
    public function theNextCursorCountsRowsActuallyReturnedRatherThanRowsRequested(): void
    {
        // An upstream that post-filters a window returns fewer rows than asked for
        // while more data remains. Advancing by $pageSize would skip the difference;
        // advancing by the rows returned resumes exactly where the data stopped.
        /** @extends OffsetFetcher<string> */
        $shortPages = new class (5) extends OffsetFetcher {
            /** @var list<int> */
            public array $requested = [];

            protected function fetchAt(int $position, int $pageSize): OffsetPage
            {
                $this->requested[] = $position;

                // Two rows per call out of a five-row window, 6 rows in total.
                return new OffsetPage(
                    $position < 6 ? ['row-' . $position, 'row-' . ($position + 1)] : [],
                    totalItems: 6,
                );
            }
        };

        /** @var list<Page<string>> $pages */
        $pages = iterator_to_array((new Paginator())->pages($shortPages), false);

        self::assertSame([0, 2, 4], $shortPages->requested, 'each call resumes two rows on, not five');
        self::assertSame('2', $pages[0]->endCursor);
        self::assertSame(['row-4', 'row-5'], $pages[2]->items);
        self::assertFalse($pages[2]->hasNextPage, 'six rows read out of six reported ends the walk');
    }

    #[Test]
    public function anEmptyWindowThatStillClaimsMoreRowsIsCaughtByLoopDetection(): void
    {
        // The one job repeated-cursor detection retains for this fetcher: a
        // stationary offset. Unlike a page number, an offset that does not advance
        // repeats its cursor, so the guard fires on the next fetch instead of
        // letting the walk spin until the budget runs out.
        /** @extends OffsetFetcher<string> */
        $stuck = new class (3) extends OffsetFetcher {
            public int $callCount = 0;

            protected function fetchAt(int $position, int $pageSize): OffsetPage
            {
                ++$this->callCount;

                return new OffsetPage([], totalItems: 100);
            }
        };

        try {
            iterator_to_array((new Paginator())->pages($stuck), false);

            self::fail('an offset that never advances must be rejected.');
        } catch (PaginationLoopException $exception) {
            self::assertSame('0', $exception->getCursor());
        }

        self::assertSame(2, $stuck->callCount, 'the repeat is caught on the very next fetch');
    }

    #[Test]
    public function totalPagesIsComparedAgainstThePageOrdinalDerivedFromTheOffset(): void
    {
        // totalPages is a page count, but this fetcher walks in rows — so the
        // comparison needs the 1-based ordinal of the current window. Resuming
        // mid-stream is where a walk-relative counter would get it wrong.
        $fetcher = new OffsetApiFetcher(self::rows(9), 3, TotalsReporting::TotalPages);

        $resumed = iterator_to_array((new Paginator())->items($fetcher, '6'), false);

        self::assertSame(['r7', 'r8', 'r9'], $resumed);
        self::assertSame([6], $fetcher->requested(), 'offset 6 is page 3 of 3 — nothing may follow it');
    }

    // ------------------------------------------------------------------
    // Zero / One / Many / Boundaries
    // ------------------------------------------------------------------

    #[Test]
    #[DataProvider('reportingProvider')]
    public function anEmptyUpstreamCostsOneFetchAndYieldsNothing(TotalsReporting $reporting): void
    {
        $fetcher = new OffsetApiFetcher([], 3, $reporting);

        self::assertSame([], iterator_to_array((new Paginator())->items($fetcher), false));
        self::assertSame([0], $fetcher->requested());
    }

    #[Test]
    #[DataProvider('reportingProvider')]
    public function aSingleRowTerminatesWithoutASecondFetch(TotalsReporting $reporting): void
    {
        $fetcher = new OffsetApiFetcher(['only'], 3, $reporting);

        self::assertSame(['only'], iterator_to_array((new Paginator())->items($fetcher), false));
        self::assertSame(1, $fetcher->callCount());
    }

    #[Test]
    #[DataProvider('reportingProvider')]
    public function aLongUpstreamWalksEveryWindowExactlyOnce(TotalsReporting $reporting): void
    {
        $fetcher = new OffsetApiFetcher(self::rows(250), 7, $reporting);

        $items = iterator_to_array((new Paginator())->items($fetcher), false);

        self::assertSame(self::rows(250), $items);
        self::assertSame(range(0, 245, 7), \array_slice($fetcher->requested(), 0, 36));
    }

    #[Test]
    public function aFinalWindowFilledToTheRequestedSizeEndsWithoutAnExtraFetchWhenTotalsAreReported(): void
    {
        foreach ([TotalsReporting::TotalPages, TotalsReporting::TotalItems] as $reporting) {
            $fetcher = new OffsetApiFetcher(self::rows(6), 3, $reporting);

            self::assertSame(self::rows(6), iterator_to_array((new Paginator())->items($fetcher), false));
            self::assertSame(2, $fetcher->callCount(), $reporting->name);
        }
    }

    #[Test]
    public function aFinalWindowFilledToTheRequestedSizeCostsOneEmptyProbeWithoutTotals(): void
    {
        $fetcher = new OffsetApiFetcher(self::rows(6), 3, TotalsReporting::Neither);

        self::assertSame(self::rows(6), iterator_to_array((new Paginator())->items($fetcher), false));
        self::assertSame([0, 3, 6], $fetcher->requested(), 'offset 6 is the empty probe that ends the walk');
    }

    // ------------------------------------------------------------------
    // Resuming and Interfaces
    // ------------------------------------------------------------------

    #[Test]
    #[DataProvider('reportingProvider')]
    public function aWalkResumedFromAnOffsetContinuesWithNoGapsOrDuplicates(TotalsReporting $reporting): void
    {
        $all = self::rows(10);
        $fetcher = new OffsetApiFetcher($all, 3, $reporting);

        $resumed = iterator_to_array((new Paginator())->items($fetcher, '4'), false);

        self::assertSame(\array_slice($all, 4), $resumed, 'an offset cursor addresses a row, not a page boundary');
        self::assertSame(4, $fetcher->requested()[0]);
    }

    #[Test]
    public function anOffsetCursorSurvivesAChangeOfPageSize(): void
    {
        // The asymmetry that makes offsets the preferred wire format: the cursor
        // '6' means the same row whatever page size reads it, so a resolver that
        // changes its chunk size does not invalidate cursors already in flight.
        $all = self::rows(12);

        $small = new OffsetApiFetcher($all, 3, TotalsReporting::TotalItems);
        $large = new OffsetApiFetcher($all, 5, TotalsReporting::TotalItems);

        self::assertSame(
            iterator_to_array((new Paginator())->items($small, '6'), false),
            iterator_to_array((new Paginator())->items($large, '6'), false),
            'the same offset cursor must address the same row under either page size',
        );
    }

    #[Test]
    public function sliceRoundTripsThroughTheRelayFormatterOverAnOffsetUpstream(): void
    {
        $rows = self::rows(20);
        $fetcher = new OffsetApiFetcher($rows, 4, TotalsReporting::TotalItems);
        $paginator = new Paginator();
        $codec = new CursorCodec();

        $slice = $paginator->slice($fetcher, 5, '4');
        self::assertSame(\array_slice($rows, 4, 5), $slice->items, 'precondition: the slice starts at row 5');

        $connection = (new ConnectionFormatter())->format($slice, null, pageStartCursor: '4');
        [$pageCursor, $skip] = $codec->decode($connection['edges'][1]['cursor']);

        self::assertSame('4', $pageCursor, 'the edge cursor must carry the offset that produced its page');
        self::assertSame(2, $skip);
        self::assertSame(20, $connection['totalCount'] ?? null);

        $resumed = $paginator->slice($fetcher, 3, $pageCursor, $skip);

        self::assertSame(\array_slice($rows, 6, 3), $resumed->items);
    }

    // ------------------------------------------------------------------
    // Exceptions
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function illegalCursorProvider(): iterable
    {
        yield 'not a number' => ['offset-4'];
        yield 'negative' => ['-1'];
        yield 'decimal' => ['4.5'];
        yield 'empty string' => [''];
    }

    #[Test]
    #[DataProvider('illegalCursorProvider')]
    public function aCursorThatIsNotAPlainNonNegativeIntegerIsRejected(string $cursor): void
    {
        $fetcher = new OffsetApiFetcher(self::rows(5), 3);

        $this->expectException(MalformedPageException::class);

        $fetcher->fetchPage($cursor);
    }

    #[Test]
    public function offsetZeroIsLegalUnlikePageZero(): void
    {
        $fetcher = new OffsetApiFetcher(self::rows(5), 3);

        self::assertSame(self::rows(3), $fetcher->fetchPage('0')->items);
    }

    #[Test]
    public function aPageSizeBelowOneIsRejectedOnConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OffsetApiFetcher(self::rows(5), 0);
    }
}
