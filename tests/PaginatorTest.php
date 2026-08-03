<?php

declare(strict_types=1);

namespace CursorWalk\Tests;

use CursorWalk\CursorCodec;
use CursorWalk\Exception\MalformedPageException;
use CursorWalk\Exception\PageBudgetExceededException;
use CursorWalk\Exception\PaginationLoopException;
use CursorWalk\Page;
use CursorWalk\Paginator;
use CursorWalk\Tests\Support\ArrayFetcher;
use CursorWalk\Tests\Support\CountingFetcher;
use CursorWalk\Tests\Support\InfiniteFetcher;
use CursorWalk\Tests\Support\LoopingFetcher;
use CursorWalk\Tests\Support\ScriptedFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Traversable;

final class PaginatorTest extends TestCase
{
    /**
     * A cursor no fetcher in this suite can parse.
     */
    private const GARBAGE_CURSOR = '!!!not-a-cursor!!!';

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Drain a stream into a plain list, discarding keys.
     *
     * Keys are deliberately dropped: an engine built on `yield from` restarts
     * the inner generator's keys on every page, so preserving them would
     * silently collapse items on top of each other and hide real failures.
     *
     * @param Traversable<mixed, mixed> $stream
     *
     * @return list<mixed>
     */
    private static function drain(Traversable $stream): array
    {
        return iterator_to_array($stream, false);
    }

    /**
     * The inclusive item range `['i<from>', ..., 'i<to>']`; empty when $to < $from.
     *
     * @return list<string>
     */
    private static function itemRange(int $from, int $to): array
    {
        $items = [];

        for ($i = $from; $i <= $to; ++$i) {
            $items[] = 'i' . $i;
        }

        return $items;
    }

    /**
     * A backing data set of $count items, `i1` .. `i<count>`.
     *
     * @return list<string>
     */
    private static function dataset(int $count): array
    {
        return self::itemRange(1, $count);
    }

    /**
     * The inclusive run of {@see InfiniteFetcher} items between two offsets.
     *
     * @return list<string>
     */
    private static function infiniteRange(int $fromOffset, int $toOffset): array
    {
        $items = [];

        for ($i = $fromOffset; $i <= $toOffset; ++$i) {
            $items[] = InfiniteFetcher::itemAt($i);
        }

        return $items;
    }

    /**
     * A three-page script whose middle page is empty but *not* terminal.
     *
     * Returned freshly per call because {@see ScriptedFetcher} is stateful.
     *
     * @return ScriptedFetcher<string>
     */
    private static function emptyMidStreamScript(): ScriptedFetcher
    {
        return new ScriptedFetcher([
            new Page(['a', 'b'], 'cursor-1', true),
            new Page([], 'cursor-2', true),
            new Page(['c', 'd'], null, false),
        ]);
    }

    /**
     * A script containing exactly ONE page that reports more data upstream.
     *
     * Any second fetch throws `LogicException` out of the fetcher, which makes
     * the "no extra fetch" guarantee airtight rather than merely counted.
     *
     * @return ScriptedFetcher<string>
     */
    private static function singlePageScript(): ScriptedFetcher
    {
        return new ScriptedFetcher([
            new Page(['a', 'b', 'c', 'd', 'e'], 'next-page-cursor', true),
        ]);
    }

    // ---------------------------------------------------------------------
    // Data providers
    // ---------------------------------------------------------------------

    /**
     * dataset size, page size, expected number of upstream fetches.
     *
     * @return iterable<string, array{0: int, 1: int, 2: int}>
     */
    public static function datasetShapeProvider(): iterable
    {
        yield 'empty upstream (one terminal fetch)' => [0, 3, 1];
        yield 'single partial page' => [2, 3, 1];
        yield 'single page, exact multiple' => [3, 3, 1];
        yield 'many pages, exact multiple of page size' => [6, 2, 3];
        yield 'many pages, with a remainder' => [7, 3, 3];
        yield 'many pages, page size one' => [4, 1, 4];
    }

    /**
     * dataset size, page size, skip, expected items.
     *
     * @return iterable<string, array{0: int, 1: int, 2: int, 3: list<string>}>
     */
    public static function firstPageSkipProvider(): iterable
    {
        yield 'skip 0 yields the whole stream' => [6, 3, 0, self::itemRange(1, 6)];
        yield 'skip 1 drops one item of page 1' => [6, 3, 1, self::itemRange(2, 6)];
        yield 'skip 2 drops two items of page 1' => [6, 3, 2, self::itemRange(3, 6)];
        yield 'skip == page size drops all of page 1' => [6, 3, 3, self::itemRange(4, 6)];
        yield 'skip == page size with page size 1' => [4, 1, 1, self::itemRange(2, 4)];
    }

    /**
     * A skip that overruns the first page, against 10 items at page size 3:
     * skip, expected remaining items.
     *
     * @return iterable<string, array{0: int, 1: list<string>}>
     */
    public static function overflowingSkipProvider(): iterable
    {
        yield 'skip lands one item into page 2' => [4, self::itemRange(5, 10)];
        yield 'skip lands two items into page 2' => [5, self::itemRange(6, 10)];
        yield 'skip consumes exactly two whole pages' => [6, self::itemRange(7, 10)];
        yield 'skip lands in the final partial page' => [9, self::itemRange(10, 10)];
        yield 'skip equal to the stream length yields nothing' => [10, []];
        yield 'skip past the end of the stream yields nothing' => [50, []];
    }

    /**
     * requested $first, expected items — both satisfied by the FIRST upstream page.
     *
     * @return iterable<string, array{0: int, 1: list<string>}>
     */
    public static function satisfiedByFirstPageProvider(): iterable
    {
        yield 'first stops mid page' => [3, self::itemRange(1, 3)];
        yield 'first consumes the page exactly' => [5, self::itemRange(1, 5)];
    }

    /**
     * The same two shapes against {@see singlePageScript()}, whose page holds `a` .. `e`.
     *
     * @return iterable<string, array{0: int, 1: list<string>}>
     */
    public static function satisfiedByScriptedPageProvider(): iterable
    {
        yield 'first stops mid page' => [3, ['a', 'b', 'c']];
        yield 'first consumes the page exactly' => [5, ['a', 'b', 'c', 'd', 'e']];
    }

    // ---------------------------------------------------------------------
    // spec case 3 — items() laziness
    // ---------------------------------------------------------------------

    #[Test]
    public function itemsFetchesNothingBeforeTheGeneratorIsStarted(): void
    {
        // spec case 3
        $fetcher = new CountingFetcher(new ArrayFetcher(self::dataset(6), 2));

        $stream = (new Paginator())->items($fetcher);

        self::assertSame(
            0,
            $fetcher->callCount(),
            'items() returns a Generator: no upstream call may happen until it is first advanced.',
        );

        // Touching it now proves the generator was live all along, not spent.
        $stream->rewind();
        self::assertSame(1, $fetcher->callCount());
    }

    #[Test]
    public function itemsFetchesTheNextPageOnlyWhenIterationCrossesThePageBoundary(): void
    {
        // spec case 3
        $fetcher = new CountingFetcher(new ArrayFetcher(self::dataset(6), 2));
        $stream = (new Paginator())->items($fetcher);

        $stream->rewind();
        self::assertSame('i1', $stream->current());
        self::assertSame(1, $fetcher->callCount(), 'the first item requires exactly one fetch');

        $stream->next();
        self::assertSame('i2', $stream->current());
        self::assertSame(1, $fetcher->callCount(), 'item 2 still lives on page 1 — no second fetch');

        $stream->next();
        self::assertSame('i3', $stream->current());
        self::assertSame(2, $fetcher->callCount(), 'crossing into page 2 costs exactly one more fetch');

        $stream->next();
        self::assertSame('i4', $stream->current());
        self::assertSame(2, $fetcher->callCount(), 'item 4 still lives on page 2 — no third fetch');

        $stream->next();
        self::assertSame('i5', $stream->current());
        self::assertSame(3, $fetcher->callCount(), 'crossing into page 3 costs exactly one more fetch');

        $stream->next();
        self::assertSame('i6', $stream->current());
        self::assertSame(3, $fetcher->callCount());

        $stream->next();
        self::assertFalse($stream->valid(), 'the stream ends after the last item of the last page');
        self::assertSame(
            3,
            $fetcher->callCount(),
            'the final page reported hasNextPage=false, so exhausting it must not fetch again',
        );
    }

    #[Test]
    #[DataProvider('datasetShapeProvider')]
    public function itemsMakesExactlyOneFetchPerPageWhenFullyConsumed(
        int $total,
        int $pageSize,
        int $expectedFetches,
    ): void {
        // spec cases 3, 4
        $fetcher = new CountingFetcher(new ArrayFetcher(self::dataset($total), $pageSize));

        $items = self::drain((new Paginator())->items($fetcher));

        self::assertSame(self::dataset($total), $items);
        self::assertSame(
            $expectedFetches,
            $fetcher->callCount(),
            'one upstream call per page — never a phantom extra fetch, not even when the '
            . 'item count is an exact multiple of the page size',
        );
    }

    // ---------------------------------------------------------------------
    // spec case 4 — items() over 0 / 1 / many pages
    // ---------------------------------------------------------------------

    #[Test]
    public function itemsYieldsNothingForAnEmptyUpstream(): void
    {
        // spec case 4
        $items = self::drain((new Paginator())->items(new ArrayFetcher([], 3)));

        self::assertSame([], $items);
    }

    #[Test]
    public function itemsYieldsEveryItemOfASinglePageUpstream(): void
    {
        // spec case 4
        $items = self::drain((new Paginator())->items(new ArrayFetcher(self::dataset(3), 5)));

        self::assertSame(self::itemRange(1, 3), $items);
    }

    #[Test]
    public function itemsYieldsEveryItemInOrderAcrossManyPages(): void
    {
        // spec case 4
        $items = self::drain((new Paginator())->items(new ArrayFetcher(self::dataset(10), 3)));

        self::assertSame(self::itemRange(1, 10), $items);
    }

    // ---------------------------------------------------------------------
    // spec case 5 — pages() boundaries
    // ---------------------------------------------------------------------

    #[Test]
    public function pagesYieldsPagesWithExactItemsCursorsAndBoundaries(): void
    {
        // spec case 5
        $fetcher = new CountingFetcher(new ArrayFetcher(self::dataset(7), 3));

        /** @var list<Page<string>> $pages */
        $pages = self::drain((new Paginator())->pages($fetcher));

        self::assertCount(3, $pages);

        self::assertSame(self::itemRange(1, 3), $pages[0]->items);
        self::assertSame(ArrayFetcher::cursorFor(3), $pages[0]->endCursor);
        self::assertTrue($pages[0]->hasNextPage);

        self::assertSame(self::itemRange(4, 6), $pages[1]->items);
        self::assertSame(ArrayFetcher::cursorFor(6), $pages[1]->endCursor);
        self::assertTrue($pages[1]->hasNextPage);

        self::assertSame(self::itemRange(7, 7), $pages[2]->items);
        self::assertNull($pages[2]->endCursor, 'the terminal page carries no next cursor');
        self::assertFalse($pages[2]->hasNextPage);

        self::assertSame(
            [null, ArrayFetcher::cursorFor(3), ArrayFetcher::cursorFor(6)],
            $fetcher->cursors(),
            'each fetch after the first must be threaded the previous page endCursor',
        );
    }

    #[Test]
    public function pagesYieldsExactlyOneTerminalPageForAnEmptyUpstream(): void
    {
        // spec case 5
        $paginator = new Paginator();

        /** @var list<Page<string>> $pages */
        $pages = self::drain($paginator->pages(new ArrayFetcher([], 3)));

        self::assertCount(1, $pages, 'an empty upstream still answers with one (empty) page');
        self::assertTrue($pages[0]->isEmpty());
        self::assertFalse($pages[0]->hasNextPage);

        self::assertSame(
            [],
            self::drain($paginator->items(new ArrayFetcher([], 3))),
            'the same upstream flattens to zero items',
        );
    }

    // ---------------------------------------------------------------------
    // spec cases 6, 7 — slice() sizing
    // ---------------------------------------------------------------------

    #[Test]
    public function sliceStopsFetchingOnceEnoughItemsAreAssembled(): void
    {
        // spec case 6
        $fetcher = new CountingFetcher(new ArrayFetcher(self::dataset(10), 5));

        $page = (new Paginator())->slice($fetcher, 3);

        self::assertSame(self::itemRange(1, 3), $page->items);
        self::assertSame(3, $page->count());
        self::assertTrue($page->hasNextPage, 'items remain in the fetched page beyond the slice end');
        self::assertSame(
            1,
            $fetcher->callCount(),
            'the first page already held enough items — slice() must not walk further',
        );
    }

    #[Test]
    public function sliceWithFirstLargerThanTheDatasetReturnsEverything(): void
    {
        // spec case 7
        $fetcher = new CountingFetcher(new ArrayFetcher(self::dataset(4), 3));

        $page = (new Paginator())->slice($fetcher, 10);

        self::assertSame(self::itemRange(1, 4), $page->items);
        self::assertSame(4, $page->count());
        self::assertFalse($page->hasNextPage, 'the upstream is exhausted, so nothing can follow');
        self::assertSame(2, $fetcher->callCount(), 'exactly the two pages the dataset spans');
    }

    // ---------------------------------------------------------------------
    // spec case 8 — repeated-cursor detection
    // ---------------------------------------------------------------------

    #[Test]
    public function pagesThrowsOnARepeatedCursor(): void
    {
        // spec case 8
        $fetcher = new LoopingFetcher();

        /** @var list<Page<string>> $seen */
        $seen = [];

        try {
            foreach ((new Paginator())->pages($fetcher) as $page) {
                $seen[] = $page;

                if (count($seen) > 5) {
                    break; // safety net: the assertion below is the real check
                }
            }

            self::fail('pages() must reject an upstream that keeps handing back the same cursor.');
        } catch (PaginationLoopException $exception) {
            self::assertStringContainsString($fetcher->stuckCursor(), $exception->getMessage());
        }

        self::assertLessThanOrEqual(
            3,
            $fetcher->callCount(),
            'the repeat must be caught on the very next fetch, not after an unbounded walk',
        );
    }

    #[Test]
    public function itemsThrowsOnARepeatedCursor(): void
    {
        // spec case 8 — items() delegates to pages(), so the guard must carry over
        $fetcher = new LoopingFetcher();

        /** @var list<mixed> $seen */
        $seen = [];

        try {
            foreach ((new Paginator())->items($fetcher) as $item) {
                $seen[] = $item;

                if (count($seen) > 5) {
                    break;
                }
            }

            self::fail('items() must reject an upstream that keeps handing back the same cursor.');
        } catch (PaginationLoopException $exception) {
            self::assertStringContainsString($fetcher->stuckCursor(), $exception->getMessage());
        }

        self::assertLessThanOrEqual(3, $fetcher->callCount());
    }

    // ---------------------------------------------------------------------
    // spec cases 9, 10 — the page budget
    // ---------------------------------------------------------------------

    #[Test]
    public function itemsThrowsWhenThePageBudgetIsExhausted(): void
    {
        // spec case 9
        $fetcher = new InfiniteFetcher(1);
        $consumed = 0;

        try {
            foreach ((new Paginator(3))->items($fetcher) as $item) {
                self::assertSame(InfiniteFetcher::itemAt($consumed), $item);
                ++$consumed;
            }

            self::fail('a bounded paginator must stop an endless upstream.');
        } catch (PageBudgetExceededException $exception) {
            self::assertSame(3, $exception->getMaxPages());
            self::assertNotNull(
                $exception->getLastCursor(),
                'the exception must carry a resume point, otherwise the walk cannot be continued',
            );
        }

        self::assertSame(3, $consumed, 'the budget allows exactly three one-item pages through');
        self::assertSame(
            3,
            $fetcher->callCount(),
            'the budget must be checked BEFORE the fourth fetch — a page that can never be '
            . 'yielded must never be paid for',
        );
    }

    #[Test]
    public function itemsResumesFromTheBudgetCursorWithoutGapsOrDuplicates(): void
    {
        // spec case 9
        $fetcher = new InfiniteFetcher(1);
        $paginator = new Paginator(3);

        $batchOne = [];
        $resumeCursor = null;

        try {
            foreach ($paginator->items($fetcher) as $item) {
                $batchOne[] = $item;
            }

            self::fail('the first batch must end in a budget exception.');
        } catch (PageBudgetExceededException $exception) {
            $resumeCursor = $exception->getLastCursor();
        }

        self::assertSame(self::infiniteRange(0, 2), $batchOne, 'three pages of one item each');
        self::assertNotNull($resumeCursor);

        $batchTwo = [];

        try {
            foreach ($paginator->items($fetcher, $resumeCursor) as $item) {
                $batchTwo[] = $item;
            }

            self::fail('the resumed batch must also end in a budget exception.');
        } catch (PageBudgetExceededException) {
            // expected: the same bounded paginator hits the same budget again
        }

        self::assertSame(self::infiniteRange(3, 5), $batchTwo, 'the resume must pick up exactly where it stopped');
        self::assertSame([], array_intersect($batchOne, $batchTwo), 'the two batches must be disjoint');
        self::assertSame(
            self::infiniteRange(0, 5),
            array_merge($batchOne, $batchTwo),
            'concatenating the batches must reproduce the stream with no gaps and no duplicates',
        );
    }

    #[Test]
    public function pagesResumesFromTheBudgetCursorWithoutGapsOrDuplicates(): void
    {
        // spec case 9
        $fetcher = new InfiniteFetcher(2);
        $paginator = new Paginator(2);

        /** @var list<mixed> $batchOne */
        $batchOne = [];
        $resumeCursor = null;

        try {
            foreach ($paginator->pages($fetcher) as $page) {
                $batchOne = array_merge($batchOne, $page->items);
            }

            self::fail('the first batch must end in a budget exception.');
        } catch (PageBudgetExceededException $exception) {
            self::assertSame(2, $exception->getMaxPages());
            $resumeCursor = $exception->getLastCursor();
        }

        self::assertSame(self::infiniteRange(0, 3), $batchOne, 'two pages of two items each');
        self::assertNotNull($resumeCursor);

        /** @var list<mixed> $batchTwo */
        $batchTwo = [];

        try {
            foreach ($paginator->pages($fetcher, $resumeCursor) as $page) {
                $batchTwo = array_merge($batchTwo, $page->items);
            }

            self::fail('the resumed batch must also end in a budget exception.');
        } catch (PageBudgetExceededException) {
            // expected
        }

        self::assertSame(self::infiniteRange(4, 7), $batchTwo);
        self::assertSame([], array_intersect($batchOne, $batchTwo), 'the two batches must be disjoint');
        self::assertSame(self::infiniteRange(0, 7), array_merge($batchOne, $batchTwo));
    }

    #[Test]
    public function nullMaxPagesIteratesPastTheDefaultBudget(): void
    {
        // spec case 10
        $fetcher = new InfiniteFetcher(1);
        $limit = 10_050; // deliberately more than the 10_000 default budget

        $count = 0;
        $firstItem = null;
        $lastItem = null;

        foreach ((new Paginator(null))->items($fetcher) as $item) {
            ++$count;

            if ($count === 1) {
                $firstItem = $item;
            }

            $lastItem = $item;

            if ($count === $limit) {
                break;
            }
        }

        self::assertSame($limit, $count, 'maxPages: null must not impose any budget of its own');
        self::assertSame(InfiniteFetcher::itemAt(0), $firstItem);
        self::assertSame(InfiniteFetcher::itemAt($limit - 1), $lastItem);
        self::assertSame($limit, $fetcher->callCount(), 'one fetch per one-item page, and not one more');
    }

    // ---------------------------------------------------------------------
    // spec case 11 — resuming with a start cursor
    // ---------------------------------------------------------------------

    #[Test]
    public function pagesPassesTheStartCursorToTheFirstFetch(): void
    {
        // spec case 11
        $fetcher = new CountingFetcher(new ArrayFetcher(self::dataset(6), 2));
        $startCursor = ArrayFetcher::cursorFor(2);

        /** @var list<Page<string>> $pages */
        $pages = self::drain((new Paginator())->pages($fetcher, $startCursor));

        self::assertSame($startCursor, $fetcher->firstCursor(), 'the walk must begin at the given cursor');
        self::assertSame(self::itemRange(3, 4), $pages[0]->items);
        self::assertCount(2, $pages, 'four remaining items at page size two');
    }

    #[Test]
    public function itemsPassesThePageCursorToTheFirstFetch(): void
    {
        // spec case 11
        $fetcher = new CountingFetcher(new ArrayFetcher(self::dataset(6), 2));
        $pageCursor = ArrayFetcher::cursorFor(2);

        $items = self::drain((new Paginator())->items($fetcher, $pageCursor));

        self::assertSame($pageCursor, $fetcher->firstCursor());
        self::assertSame(self::itemRange(3, 6), $items);
    }

    // ---------------------------------------------------------------------
    // spec case 15 — early break
    // ---------------------------------------------------------------------

    #[Test]
    public function breakingOutOfItemsImmediatelyFetchesOnlyTheFirstPage(): void
    {
        // spec case 15
        $fetcher = new CountingFetcher(new ArrayFetcher(self::dataset(9), 3));

        $seen = [];

        foreach ((new Paginator())->items($fetcher) as $item) {
            $seen[] = $item;

            break;
        }

        self::assertSame(['i1'], $seen);
        self::assertSame(1, $fetcher->callCount(), 'abandoning the stream must not keep walking');
    }

    #[Test]
    public function breakingOutOfItemsMidStreamFetchesNoFurtherPages(): void
    {
        // spec case 15
        $fetcher = new CountingFetcher(new ArrayFetcher(self::dataset(9), 3));

        $seen = [];

        foreach ((new Paginator())->items($fetcher) as $item) {
            $seen[] = $item;

            if (count($seen) === 4) {
                break;
            }
        }

        self::assertSame(self::itemRange(1, 4), $seen);
        self::assertSame(
            2,
            $fetcher->callCount(),
            'item 4 is the first item of page 2 — page 3 must never be requested',
        );
    }

    // ---------------------------------------------------------------------
    // spec case 18 — items() with $skip
    //
    // RESOLVED: the spec only fixes `$skip` for the FIRST page, because in
    // practice a skip always originates as an offset WITHIN one page (a decoded
    // edge cursor). A skip larger than that page was therefore left open. src
    // resolves it as "drop N items from the head of the stream", carrying the
    // remainder into the following pages rather than discarding it — documented
    // in Paginator::items()'s docblock and pinned by
    // {@see self::itemsCarriesASkipLargerThanTheFirstPageIntoLaterPages()}
    // below. The alternative reading ("clamp to the first page") would make a
    // position stop being a position as soon as the upstream re-chunked its
    // pages, and would silently duplicate items on a multi-page slice resume.
    // ---------------------------------------------------------------------

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('firstPageSkipProvider')]
    public function itemsAppliesSkipToTheFirstPageOnly(
        int $total,
        int $pageSize,
        int $skip,
        array $expected,
    ): void {
        // spec case 18
        $fetcher = new ArrayFetcher(self::dataset($total), $pageSize);

        $items = self::drain((new Paginator())->items($fetcher, null, $skip));

        self::assertSame(
            $expected,
            $items,
            'skip must consume items from the first page only — later pages are untouched',
        );
    }

    #[Test]
    public function itemsWithSkipEqualToPageSizeStartsAtTheFirstItemOfTheSecondPage(): void
    {
        // spec case 18
        $fetcher = new CountingFetcher(new ArrayFetcher(self::dataset(6), 3));

        $items = self::drain((new Paginator())->items($fetcher, null, 3));

        self::assertSame('i4', $items[0] ?? null, 'the whole first page was skipped');
        self::assertSame(self::itemRange(4, 6), $items, 'iteration continues normally afterwards');
        self::assertSame(
            2,
            $fetcher->callCount(),
            'page 1 is still fetched (its items are skipped, not un-requested)',
        );
    }

    #[Test]
    public function itemsAppliesSkipRelativeToTheGivenPageCursor(): void
    {
        // spec cases 11, 18
        $fetcher = new CountingFetcher(new ArrayFetcher(self::dataset(6), 3));

        $items = self::drain((new Paginator())->items($fetcher, ArrayFetcher::cursorFor(3), 1));

        self::assertSame(ArrayFetcher::cursorFor(3), $fetcher->firstCursor());
        self::assertSame(self::itemRange(5, 6), $items, 'skip is measured from the start of the resumed page');
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('overflowingSkipProvider')]
    public function itemsCarriesASkipLargerThanTheFirstPageIntoLaterPages(
        int $skip,
        array $expected,
    ): void {
        // spec case 18, extended: $skip > first page size.
        $fetcher = new ArrayFetcher(self::dataset(10), 3);

        $items = self::drain((new Paginator())->items($fetcher, null, $skip));

        self::assertSame(
            $expected,
            $items,
            'a skip is a position in the stream, so its remainder carries into the following pages',
        );
    }

    #[Test]
    public function itemsWithSkipSpanningTwoPagesResumesAtTheExactGlobalIndex(): void
    {
        // spec case 18, extended: pageSize 3 + skip 5 lands two items into page 2.
        $all = self::dataset(10);
        $fetcher = new CountingFetcher(new ArrayFetcher($all, 3));

        $items = self::drain((new Paginator())->items($fetcher, null, 5));

        // The contract stated positionally: iteration begins at global index 5
        // and reproduces the tail of the stream exactly — no duplicate of the
        // straddled page's already-skipped items, and no gap where the page
        // boundary fell.
        self::assertSame(array_slice($all, 5), $items);
        self::assertSame('i6', $items[0] ?? null, 'global item index 5 is i6');
        self::assertSame($items, array_values(array_unique($items)), 'no item is yielded twice');
        self::assertCount(5, $items, '10 items less a skip of 5 leaves 5');
        self::assertSame(
            4,
            $fetcher->callCount(),
            'the two straddled pages are still fetched — their items are skipped, not un-requested',
        );
    }

    #[Test]
    public function itemsYieldsSequentialKeysAcrossPageBoundaries(): void
    {
        // Keys must run 0..n-1 over the WHOLE walk, not restart per page:
        // iterator_to_array() without $preserve_keys=false is a thing people
        // write, and per-page keys would make it silently drop items.
        $paginator = new Paginator();

        $keyed = iterator_to_array($paginator->items(new ArrayFetcher(self::dataset(7), 2)));

        self::assertSame(range(0, 6), array_keys($keyed));
        self::assertSame(self::itemRange(1, 7), array_values($keyed));
        self::assertCount(7, $keyed, 'a page-local key counter would have collapsed these into 2 entries');

        // $skip counts skipped items out of the stream, not out of the keys.
        $skipped = iterator_to_array($paginator->items(new ArrayFetcher(self::dataset(7), 2), null, 5));

        self::assertSame([0 => 'i6', 1 => 'i7'], $skipped);
    }

    // ---------------------------------------------------------------------
    // spec cases 20, 21, 22 — slice() spanning and resuming
    // ---------------------------------------------------------------------

    #[Test]
    public function sliceAssemblesExactlyFirstItemsAcrossMultipleUpstreamPages(): void
    {
        // spec case 20
        $fetcher = new CountingFetcher(new ArrayFetcher(self::dataset(10), 3));

        $page = (new Paginator())->slice($fetcher, 7);

        self::assertSame(self::itemRange(1, 7), $page->items);
        self::assertSame(7, $page->count(), 'slice() returns exactly $first items when the data exists');
        self::assertTrue($page->hasNextPage);
        self::assertSame(3, $fetcher->callCount(), 'seven items at page size three span exactly three fetches');
    }

    #[Test]
    public function sliceEndingMidUpstreamPageEncodesThatPositionInItsEndCursor(): void
    {
        // spec case 21
        $fetcher = new ArrayFetcher(self::dataset(10), 3);

        $page = (new Paginator())->slice($fetcher, 4);

        self::assertSame(self::itemRange(1, 4), $page->items);
        self::assertTrue($page->hasNextPage, 'items 5 and 6 remain in the last fetched upstream page');

        $endCursor = $page->endCursor;
        self::assertNotNull($endCursor, 'a slice with more data behind it must expose a resume cursor');

        [$pageCursor, $offset] = (new CursorCodec())->decode($endCursor);

        self::assertTrue(
            $pageCursor !== null || $offset !== 0,
            'the endCursor must decode to a real position (a page cursor and/or a non-zero offset), '
            . 'otherwise the slice end mid-page is not addressable',
        );
    }

    #[Test]
    public function sliceEndingExactlyOnAPageBoundaryEmitsTheRawUpstreamEndCursor(): void
    {
        // Documented in Paginator::slice(): a boundary-aligned slice anchors to
        // the upstream's own cursor, the cheapest possible resume point.
        $fetcher = new ArrayFetcher(self::dataset(10), 5);

        $page = (new Paginator())->slice($fetcher, 5);

        self::assertSame(self::itemRange(1, 5), $page->items);
        self::assertTrue($page->hasNextPage);
        self::assertSame(
            ArrayFetcher::cursorFor(5),
            $page->endCursor,
            'a boundary-aligned slice must emit the raw upstream endCursor, not a synthetic position',
        );
    }

    #[Test]
    public function slicePropagatesTotalCountFromTheLastFetchedPage(): void
    {
        $fetcher = new ArrayFetcher(self::dataset(10), 3, withTotalCount: true);

        $page = (new Paginator())->slice($fetcher, 4);

        self::assertSame(self::itemRange(1, 4), $page->items);
        self::assertSame(10, $page->totalCount);
    }

    #[Test]
    public function sliceResumesFromItsOwnEndCursorWithoutGapsOrDuplicates(): void
    {
        // spec case 21
        $fetcher = new ArrayFetcher(self::dataset(12), 3);
        $paginator = new Paginator();
        $codec = new CursorCodec();

        $first = $paginator->slice($fetcher, 4);
        self::assertSame(self::itemRange(1, 4), $first->items);

        $endCursor = $first->endCursor;
        self::assertNotNull($endCursor);

        [$pageCursor, $skip] = $codec->decode($endCursor);

        $second = $paginator->slice($fetcher, 4, $pageCursor, $skip);

        self::assertSame(
            self::itemRange(5, 8),
            $second->items,
            'the follow-up slice must continue at the exact item after the first slice ended',
        );
        self::assertSame([], array_intersect($first->items, $second->items), 'no item may be served twice');
        self::assertSame(
            self::itemRange(1, 8),
            array_merge($first->items, $second->items),
            'the two slices must concatenate into one contiguous run',
        );
    }

    #[Test]
    public function sliceReturnsTheRemainderWhenFewerThanFirstItemsRemain(): void
    {
        // spec case 22
        $fetcher = new CountingFetcher(new ArrayFetcher(self::dataset(5), 3));

        $page = (new Paginator())->slice($fetcher, 10, ArrayFetcher::cursorFor(3));

        self::assertSame(self::itemRange(4, 5), $page->items);
        self::assertSame(2, $page->count());
        self::assertFalse($page->hasNextPage, 'the tail of the dataset cannot report more data');
        self::assertSame(1, $fetcher->callCount());
    }

    // ---------------------------------------------------------------------
    // spec case 23 — slice() minimizes fetches
    // ---------------------------------------------------------------------

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('satisfiedByFirstPageProvider')]
    public function sliceDoesNotFetchAnExtraPageWhenTheCurrentPageProvesHasNextPage(
        int $first,
        array $expected,
    ): void {
        // spec case 23
        $fetcher = new CountingFetcher(new ArrayFetcher(self::dataset(10), 5));

        $page = (new Paginator())->slice($fetcher, $first);

        self::assertSame($expected, $page->items);
        self::assertTrue($page->hasNextPage);
        self::assertSame(
            1,
            $fetcher->callCount(),
            'page 1 already reports hasNextPage=true, so hasNextPage needs no confirming fetch',
        );
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('satisfiedByScriptedPageProvider')]
    public function sliceNeverFetchesBeyondThePageThatSatisfiesTheRequest(
        int $first,
        array $expected,
    ): void {
        // spec case 23 — a second fetch throws LogicException out of the fixture
        $fetcher = self::singlePageScript();

        $page = (new Paginator())->slice($fetcher, $first);

        self::assertSame($expected, $page->items);
        self::assertTrue($page->hasNextPage);
        self::assertSame(1, $fetcher->callCount());
    }

    // ---------------------------------------------------------------------
    // spec case 24 — empty mid-stream page
    // ---------------------------------------------------------------------

    #[Test]
    public function itemsFlowsStraightThroughAnEmptyMidStreamPage(): void
    {
        // spec case 24
        $fetcher = self::emptyMidStreamScript();

        $items = self::drain((new Paginator())->items($fetcher));

        self::assertSame(['a', 'b', 'c', 'd'], $items, 'an empty mid-stream page is invisible to items()');
        self::assertSame(3, $fetcher->callCount());
    }

    #[Test]
    public function pagesYieldsAnEmptyMidStreamPageAsIs(): void
    {
        // spec case 24
        $fetcher = self::emptyMidStreamScript();

        /** @var list<Page<string>> $pages */
        $pages = self::drain((new Paginator())->pages($fetcher));

        self::assertCount(3, $pages, 'pages() passes the empty page through rather than swallowing it');

        self::assertSame(['a', 'b'], $pages[0]->items);
        self::assertTrue($pages[0]->hasNextPage);

        self::assertTrue($pages[1]->isEmpty(), 'the middle page is empty but still a real page');
        self::assertSame(0, $pages[1]->count());
        self::assertTrue($pages[1]->hasNextPage);
        self::assertSame('cursor-2', $pages[1]->endCursor);

        self::assertSame(['c', 'd'], $pages[2]->items);
        self::assertFalse($pages[2]->hasNextPage);
    }

    // ---------------------------------------------------------------------
    // extra — robustness
    // ---------------------------------------------------------------------

    #[Test]
    public function sliceWithFirstZeroReturnsAnEmptyPage(): void
    {
        // extra
        // INTEGRATION NOTE: the spec does not say whether slice($f, 0) may fetch.
        // Only the returned shape is asserted here; if src settles on "never
        // fetches", add the callCount assertion then.
        $page = (new Paginator())->slice(new ArrayFetcher(self::dataset(5), 2), 0);

        self::assertInstanceOf(Page::class, $page);
        self::assertSame(0, $page->count());
        self::assertTrue($page->isEmpty());
    }

    #[Test]
    public function sliceRejectsANegativeFirst(): void
    {
        // extra — argument validation; a negative window is a caller bug, not a
        // pagination failure, so it is an \InvalidArgumentException rather than a
        // CursorWalkException.
        $this->expectException(\InvalidArgumentException::class);

        (new Paginator())->slice(new ArrayFetcher(self::dataset(5), 2), -1);
    }

    #[Test]
    public function sliceRejectsANegativeSkip(): void
    {
        // extra — argument validation
        $this->expectException(\InvalidArgumentException::class);

        (new Paginator())->slice(new ArrayFetcher(self::dataset(5), 2), 2, null, -1);
    }

    #[Test]
    public function malformedPageExceptionFromTheFetcherPropagatesOutOfPages(): void
    {
        // extra — the engine must not swallow a fetcher-level failure
        $this->expectException(MalformedPageException::class);

        self::drain((new Paginator())->pages(new ArrayFetcher(self::dataset(3), 2), self::GARBAGE_CURSOR));
    }

    #[Test]
    public function malformedPageExceptionFromTheFetcherPropagatesOutOfItems(): void
    {
        // extra
        $this->expectException(MalformedPageException::class);

        self::drain((new Paginator())->items(new ArrayFetcher(self::dataset(3), 2), self::GARBAGE_CURSOR));
    }

    #[Test]
    public function paginatorIsStatelessAndReusableAcrossFetchersAndWalks(): void
    {
        // extra
        $paginator = new Paginator();
        $first = new ArrayFetcher(self::dataset(5), 2);
        $second = new ArrayFetcher(['x', 'y', 'z'], 2);

        self::assertSame(self::itemRange(1, 5), self::drain($paginator->items($first)));
        self::assertSame(['x', 'y', 'z'], self::drain($paginator->items($second)));
        self::assertSame(
            self::itemRange(1, 5),
            self::drain($paginator->items($first)),
            're-walking the first fetcher must produce the complete stream again',
        );
        self::assertSame(self::itemRange(1, 3), (new Paginator())->slice($first, 3)->items);
    }
}
