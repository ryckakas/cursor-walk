<?php

declare(strict_types=1);

namespace CursorWalk\Tests\Offset;

use CursorWalk\Offset\OffsetPage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The {@see OffsetPage::hasMoreAfter()} precedence table is pinned here, so the
 * fetcher tests only have to prove they feed it the right numbers.
 */
final class OffsetPageTest extends TestCase
{
    // Precedence between the three signals

    #[Test]
    public function totalPagesWinsOverTotalItemsWhenBothArePresentAndDisagree(): void
    {
        // The two only disagree on an inconsistent upstream, and totalPages answers
        // "is there another page?" directly.
        $page = new OffsetPage(['a', 'b'], totalItems: 1_000, totalPages: 2);

        self::assertTrue($page->hasMoreAfter(pageNumber: 1, itemsThrough: 2, pageSize: 2));
        self::assertFalse(
            $page->hasMoreAfter(pageNumber: 2, itemsThrough: 4, pageSize: 2),
            'totalPages says this was the last page, so a huge totalItems must not override it',
        );
    }

    #[Test]
    public function totalItemsIsUsedWhenTotalPagesIsAbsent(): void
    {
        $page = new OffsetPage(['a', 'b'], totalItems: 5);

        self::assertTrue($page->hasMoreAfter(pageNumber: 1, itemsThrough: 2, pageSize: 2));
        self::assertFalse($page->hasMoreAfter(pageNumber: 3, itemsThrough: 5, pageSize: 2));
    }

    #[Test]
    public function aFullPageIsTheOnlySignalLeftWhenTheEnvelopeReportsNoTotals(): void
    {
        $full = new OffsetPage(['a', 'b', 'c']);
        $short = new OffsetPage(['a', 'b']);

        self::assertTrue(
            $full->hasMoreAfter(pageNumber: 1, itemsThrough: 3, pageSize: 3),
            'a page filled to the requested size implies there may be more',
        );
        self::assertFalse(
            $short->hasMoreAfter(pageNumber: 1, itemsThrough: 2, pageSize: 3),
            'a short page cannot be followed by anything',
        );
    }

    #[Test]
    public function anOverFullPageKeepsTheWalkGoingRatherThanTruncatingIt(): void
    {
        // An upstream ignoring the limit is broken; "keep walking" hands it to the
        // engine's guards instead of silently dropping the rest of the stream.
        $page = new OffsetPage(['a', 'b', 'c', 'd']);

        self::assertTrue($page->hasMoreAfter(pageNumber: 1, itemsThrough: 4, pageSize: 3));
    }

    // Boundaries — exactly at the reported total

    /**
     * @return iterable<string, array{0: int, 1: bool}>
     */
    public static function pageNumberAgainstTotalPagesProvider(): iterable
    {
        yield 'one page before the last' => [2, true];
        yield 'exactly the last page' => [3, false];
        yield 'past the last page' => [4, false];
    }

    #[Test]
    #[DataProvider('pageNumberAgainstTotalPagesProvider')]
    public function totalPagesIsExclusiveOfThePageJustFetched(int $pageNumber, bool $expected): void
    {
        $page = new OffsetPage(['a'], totalPages: 3);

        self::assertSame($expected, $page->hasMoreAfter($pageNumber, itemsThrough: 1, pageSize: 1));
    }

    /**
     * @return iterable<string, array{0: int, 1: bool}>
     */
    public static function itemsThroughAgainstTotalItemsProvider(): iterable
    {
        yield 'one row short of the total' => [9, true];
        yield 'exactly the total' => [10, false];
        yield 'past the total' => [11, false];
    }

    #[Test]
    #[DataProvider('itemsThroughAgainstTotalItemsProvider')]
    public function totalItemsIsExclusiveOfTheRowsAlreadyRead(int $itemsThrough, bool $expected): void
    {
        $page = new OffsetPage(['a'], totalItems: 10);

        self::assertSame($expected, $page->hasMoreAfter(pageNumber: 1, itemsThrough: $itemsThrough, pageSize: 1));
    }

    // Zero

    #[Test]
    public function anEmptyPageTerminatesUnderEverySignal(): void
    {
        $noTotals = new OffsetPage([]);
        $zeroItems = new OffsetPage([], totalItems: 0);
        $zeroPages = new OffsetPage([], totalPages: 0);

        self::assertFalse($noTotals->hasMoreAfter(pageNumber: 1, itemsThrough: 0, pageSize: 3));
        self::assertFalse($zeroItems->hasMoreAfter(pageNumber: 1, itemsThrough: 0, pageSize: 3));
        self::assertFalse($zeroPages->hasMoreAfter(pageNumber: 1, itemsThrough: 0, pageSize: 3));
    }

    #[Test]
    public function anEmptyPageThatStillClaimsMoreRowsIsBelievedRatherThanSecondGuessed(): void
    {
        // A filtered result set can legitimately return no rows while more remain.
        $page = new OffsetPage([], totalItems: 100);

        self::assertTrue($page->hasMoreAfter(pageNumber: 1, itemsThrough: 0, pageSize: 3));
    }

    // The value object itself

    #[Test]
    public function totalsDefaultToAbsentSoAnUnhelpfulEnvelopeNeedsNoCeremony(): void
    {
        $page = new OffsetPage(['a']);

        self::assertSame(['a'], $page->items);
        self::assertNull($page->totalItems);
        self::assertNull($page->totalPages);
    }
}
