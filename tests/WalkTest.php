<?php

declare(strict_types=1);

namespace CursorWalk\Tests;

use CursorWalk\Exception\PageBudgetExceededException;
use CursorWalk\Exception\PaginationLoopException;
use CursorWalk\Page;
use CursorWalk\Walk;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the caller-driven stepper.
 *
 * `Paginator` is implemented in terms of `Walk`, so {@see PaginatorTest} already
 * covers the policy end to end through the library's own driver. This file covers
 * what only a hand-written driver can reach: the alternation protocol, the
 * accounting, and the states a generator frame used to make unreachable.
 */
final class WalkTest extends TestCase
{
    private const STUCK = 'stuck-cursor';

    /**
     * @return Page<string>
     */
    private static function pageWith(?string $endCursor, bool $hasNextPage = true): Page
    {
        return new Page(['item'], $endCursor, $hasNextPage);
    }

    /**
     * @return Page<string>
     */
    private static function terminalPage(): Page
    {
        return new Page(['last'], null, false);
    }

    /**
     * Drive a whole walk over a list of scripted pages, collecting the cursors the
     * walk asked to be fetched.
     *
     * This is the shape every real driver has — `Paginator::pages()`, a Fiber, a
     * workflow — with the fetch replaced by an array lookup.
     *
     * @param list<Page<string>> $pages
     *
     * @return list<?string>
     */
    private static function drive(Walk $walk, array $pages): array
    {
        $requested = [];

        while ($walk->hasNext()) {
            $requested[] = $walk->nextCursor();

            $page = $pages[\count($requested) - 1] ?? self::terminalPage();

            $walk->advance($page);
        }

        return $requested;
    }

    // ------------------------------------------------------------------
    // Simple — the ordinary walk
    // ------------------------------------------------------------------

    #[Test]
    public function aWalkThreadsEachPagesCursorIntoTheNextFetch(): void
    {
        $walk = new Walk();

        $requested = self::drive($walk, [
            self::pageWith('cursor-1'),
            self::pageWith('cursor-2'),
            self::terminalPage(),
        ]);

        self::assertSame([null, 'cursor-1', 'cursor-2'], $requested);
        self::assertSame(3, $walk->pagesFetched());
        self::assertFalse($walk->hasNext(), 'a page reporting no more data ends the walk');
    }

    #[Test]
    public function aWalkStartsAtItsStartCursor(): void
    {
        $walk = new Walk('resume-here');

        $requested = self::drive($walk, [self::pageWith('cursor-2'), self::terminalPage()]);

        self::assertSame(['resume-here', 'cursor-2'], $requested);
    }

    // ------------------------------------------------------------------
    // Zero / One
    // ------------------------------------------------------------------

    #[Test]
    public function aFreshWalkAlwaysOffersOneFetchSoAnEmptyUpstreamCanBeObserved(): void
    {
        $walk = new Walk();

        self::assertTrue($walk->hasNext(), 'one fetch is always needed to learn there is nothing there');
        self::assertSame(0, $walk->pagesFetched());

        self::assertNull($walk->nextCursor(), 'that fetch starts at the beginning');
        $walk->advance(new Page([], null, false));

        self::assertFalse($walk->hasNext());
        self::assertSame(1, $walk->pagesFetched());
    }

    #[Test]
    public function anEmptyMidStreamPageContinuesTheWalk(): void
    {
        // Same contract as Paginator: an empty page carrying a cursor is legal and
        // does occur (a server-side filtered window), so it must not terminate.
        $walk = new Walk();
        $walk->nextCursor();
        $walk->advance(new Page([], 'cursor-1', true));

        self::assertTrue($walk->hasNext());
        self::assertSame('cursor-1', $walk->nextCursor());
    }

    // ------------------------------------------------------------------
    // Many
    // ------------------------------------------------------------------

    #[Test]
    public function aLongWalkKeepsAccountingCorrectlyAcrossHundredsOfPages(): void
    {
        $walk = new Walk(maxPages: null);
        $requested = [];

        for ($i = 0; $i < 500; ++$i) {
            $requested[] = $walk->nextCursor();
            $walk->advance(self::pageWith('cursor-' . $i));
        }

        self::assertSame(500, $walk->pagesFetched());
        self::assertSame([null, 'cursor-0', 'cursor-1'], \array_slice($requested, 0, 3));
        self::assertSame('cursor-499', $walk->nextCursor(), 'the walk is still threading the latest cursor');
    }

    // ------------------------------------------------------------------
    // Boundaries — the page budget
    // ------------------------------------------------------------------

    #[Test]
    public function theBudgetIsSpentOnDrawingCursorsAndThrowsOnTheDrawAfterTheLastOne(): void
    {
        $walk = new Walk(maxPages: 2);

        self::assertNull($walk->nextCursor());
        $walk->advance(self::pageWith('cursor-1'));

        self::assertSame('cursor-1', $walk->nextCursor());
        $walk->advance(self::pageWith('cursor-2'));

        self::assertTrue($walk->hasNext(), 'the upstream still reports more; only the budget is spent');

        try {
            $walk->nextCursor();

            self::fail('a third draw must exceed a budget of two.');
        } catch (PageBudgetExceededException $exception) {
            self::assertSame(2, $exception->getMaxPages());
            self::assertSame(
                'cursor-2',
                $exception->getLastCursor(),
                'the exception must carry the un-fetched cursor, so the walk can be resumed from it',
            );
        }

        self::assertSame(2, $walk->pagesFetched(), 'a refused draw must not be counted');
    }

    #[Test]
    public function aBudgetOfOneAllowsExactlyOneFetch(): void
    {
        $walk = new Walk(maxPages: 1);

        self::assertNull($walk->nextCursor());
        $walk->advance(self::pageWith('cursor-1'));

        $this->expectException(PageBudgetExceededException::class);

        $walk->nextCursor();
    }

    #[Test]
    public function aBudgetOfZeroRefusesTheVeryFirstFetchAndStillCarriesAResumePoint(): void
    {
        $walk = new Walk('resume-here', maxPages: 0);

        try {
            $walk->nextCursor();

            self::fail('a budget of zero must permit no fetch at all.');
        } catch (PageBudgetExceededException $exception) {
            self::assertSame('resume-here', $exception->getLastCursor());
        }

        self::assertSame(0, $walk->pagesFetched());
    }

    #[Test]
    public function aRefusedDrawIsIdempotentRatherThanCorruptingTheWalk(): void
    {
        $walk = new Walk(maxPages: 1);
        $walk->nextCursor();
        $walk->advance(self::pageWith('cursor-1'));

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            try {
                $walk->nextCursor();

                self::fail('the budget is exhausted; every draw must be refused.');
            } catch (PageBudgetExceededException $exception) {
                self::assertSame('cursor-1', $exception->getLastCursor(), "attempt {$attempt}");
            }
        }

        self::assertSame(1, $walk->pagesFetched(), 'refused draws must not accumulate against the budget');
    }

    #[Test]
    public function aNullBudgetImposesNoBoundOfItsOwn(): void
    {
        $walk = new Walk(maxPages: null);

        for ($i = 0; $i < 10_050; ++$i) {
            $walk->nextCursor();
            $walk->advance(self::pageWith('cursor-' . $i));
        }

        self::assertSame(10_050, $walk->pagesFetched(), 'well past the 10,000 default');
    }

    // ------------------------------------------------------------------
    // Loop detection
    // ------------------------------------------------------------------

    #[Test]
    public function aRepeatedCursorIsRejectedAfterThePageCarryingItWasHandedBack(): void
    {
        $walk = new Walk();

        $walk->nextCursor();
        $walk->advance(self::pageWith(self::STUCK));

        self::assertSame(self::STUCK, $walk->nextCursor());

        try {
            $walk->advance(self::pageWith(self::STUCK));

            self::fail('handing back the same cursor twice must be rejected.');
        } catch (PaginationLoopException $exception) {
            self::assertSame(self::STUCK, $exception->getCursor());
        }
    }

    #[Test]
    public function aPagePointingBackAtTheStartCursorIsALoopToo(): void
    {
        $walk = new Walk(self::STUCK);
        $walk->nextCursor();

        $this->expectException(PaginationLoopException::class);

        $walk->advance(self::pageWith(self::STUCK));
    }

    #[Test]
    public function aWalkThatDetectedALoopIsFinishedSoASwallowedExceptionCannotKeepWalking(): void
    {
        // A loop is neither retryable nor resumable. A driver with a `catch` around
        // its loop body must not be able to carry on re-fetching the same page.
        $walk = new Walk(self::STUCK);
        $walk->nextCursor();

        try {
            $walk->advance(self::pageWith(self::STUCK));
        } catch (PaginationLoopException) {
            // swallowed on purpose — that is the scenario under test
        }

        self::assertFalse($walk->hasNext(), 'the walk must refuse to continue after a loop');

        $this->expectException(\LogicException::class);

        $walk->nextCursor();
    }

    #[Test]
    public function aCursorMayRepeatAcrossSeparateWalksBecauseEachWalkTracksItsOwn(): void
    {
        $first = new Walk();
        $first->nextCursor();
        $first->advance(self::pageWith('cursor-1'));

        $second = new Walk();
        $second->nextCursor();
        $second->advance(self::pageWith('cursor-1'));

        self::assertSame('cursor-1', $second->nextCursor(), 'two walks must not interfere');
    }

    // ------------------------------------------------------------------
    // The defensive empty-cursor stop
    // ------------------------------------------------------------------

    #[Test]
    public function aPageClaimingMoreDataWithNoUsableCursorStopsTheWalkInsteadOfSpinning(): void
    {
        // Page's constructor makes this state impossible, so the page here is built
        // WITHOUT it — the way a payload converter, an unserialize(), or an ORM-style
        // hydrator would. advance() is public, so that page can reach a walk, and
        // without this stop the walk would re-fetch the same cursor forever.
        // Terminating is the safe degradation.
        $illegal = self::pageBypassingValidation('', true);

        $walk = new Walk('some-cursor');
        $walk->nextCursor();
        $walk->advance($illegal);

        self::assertFalse($walk->hasNext(), 'a page that claims more data but cannot reach it ends the walk');
    }

    #[Test]
    public function thatStopAlsoCoversANullCursorOnAPageClaimingMoreData(): void
    {
        $walk = new Walk('some-cursor');
        $walk->nextCursor();
        $walk->advance(self::pageBypassingValidation(null, true));

        self::assertFalse($walk->hasNext());
    }

    /**
     * Build a {@see Page} in a state its constructor rejects.
     *
     * Reflection rather than a hand-written `unserialize()` payload: it fails loudly
     * if a property is ever renamed, instead of quietly producing a different object.
     *
     * @return Page<string>
     */
    private static function pageBypassingValidation(?string $endCursor, bool $hasNextPage): Page
    {
        /** @var Page<string> $page */
        $page = (new \ReflectionClass(Page::class))->newInstanceWithoutConstructor();

        foreach (
            [
                'items' => ['item'],
                'endCursor' => $endCursor,
                'hasNextPage' => $hasNextPage,
                'totalCount' => null,
            ] as $property => $value
        ) {
            (new \ReflectionProperty(Page::class, $property))->setValue($page, $value);
        }

        return $page;
    }

    // ------------------------------------------------------------------
    // The alternation protocol — driver bugs are LogicExceptions
    // ------------------------------------------------------------------

    #[Test]
    public function drawingTwiceWithoutHandingBackAPageIsADriverBug(): void
    {
        $walk = new Walk();
        $walk->nextCursor();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/advance\(\)/');

        $walk->nextCursor();
    }

    #[Test]
    public function handingBackAPageThatWasNeverAskedForIsADriverBug(): void
    {
        $walk = new Walk();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/nextCursor\(\)/');

        $walk->advance(self::pageWith('cursor-1'));
    }

    #[Test]
    public function advancingTwiceForOneDrawnCursorIsADriverBugRatherThanAFalseLoopReport(): void
    {
        // This is the reason the protocol is enforced at all. A tolerant stepper
        // would feed the same page into loop detection twice and raise
        // PaginationLoopException — the exception that means "page the on-call
        // engineer, the upstream is broken" — for a bug in the driver.
        $walk = new Walk();
        $walk->nextCursor();
        $walk->advance(self::pageWith('cursor-1'));

        try {
            $walk->advance(self::pageWith('cursor-1'));

            self::fail('a double advance() must be rejected.');
        } catch (PaginationLoopException) {
            self::fail('a driver bug must not be reported as a broken upstream.');
        } catch (\LogicException $exception) {
            self::assertStringNotContainsString('loop', strtolower($exception->getMessage()));
        }
    }

    #[Test]
    public function drawingFromAFinishedWalkIsADriverBugBecauseAWalkIsSingleUse(): void
    {
        $walk = new Walk();
        $walk->nextCursor();
        $walk->advance(self::terminalPage());

        self::assertFalse($walk->hasNext());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/single-use/');

        $walk->nextCursor();
    }

    /**
     * @return iterable<string, array{0: \Closure(Walk): void}>
     */
    public static function protocolViolationProvider(): iterable
    {
        yield 'advance before any draw' => [
            static fn (Walk $walk): null => $walk->advance(self::pageWith('c')),
        ];
        yield 'draw twice' => [
            static function (Walk $walk): void {
                $walk->nextCursor();
                $walk->nextCursor();
            },
        ];
        yield 'advance twice' => [
            static function (Walk $walk): void {
                $walk->nextCursor();
                $walk->advance(self::pageWith('c'));
                $walk->advance(self::pageWith('c2'));
            },
        ];
        yield 'draw after the walk finished' => [
            static function (Walk $walk): void {
                $walk->nextCursor();
                $walk->advance(self::terminalPage());
                $walk->nextCursor();
            },
        ];
    }

    /**
     * @param \Closure(Walk): void $violate
     */
    #[Test]
    #[DataProvider('protocolViolationProvider')]
    public function everyProtocolViolationRaisesABareLogicExceptionAndNoNewExceptionType(
        \Closure $violate,
    ): void {
        // Deliberately \LogicException and not a CursorWalkException subclass: the
        // library's exception taxonomy is about upstream bugs and policy limits, and
        // a driver misusing the stepper is neither. It also keeps the change at zero
        // new public types.
        $walk = new Walk();

        try {
            $violate($walk);

            self::fail('the violation must have been rejected.');
        } catch (\LogicException $exception) {
            // Catching \LogicException IS the assertion about the taxonomy: a
            // CursorWalkException would not be caught here, because it extends
            // \RuntimeException.
            self::assertNotSame('', $exception->getMessage(), 'a driver bug must say what the driver did wrong');
        }
    }

    // ------------------------------------------------------------------
    // Interfaces — the shape a workflow engine drives it in
    // ------------------------------------------------------------------

    #[Test]
    public function aWalkDrivenThroughGeneratorYieldsCompletesTheSameAsPaginator(): void
    {
        // The Temporal shape without the Temporal SDK: every fetch leaves the walk's
        // frame as a yielded request and comes back as a plain result, exactly as an
        // activity call does. Nothing in Walk cares.
        $upstream = [
            null => ['rows' => ['a', 'b'], 'next' => 'c1'],
            'c1' => ['rows' => ['c'], 'next' => 'c2'],
            'c2' => ['rows' => ['d', 'e'], 'next' => null],
        ];

        $run = self::workflowDriver();
        $activityCalls = 0;

        while ($run->valid()) {
            $cursor = $run->current();
            ++$activityCalls;

            self::assertArrayHasKey($cursor ?? '', $upstream, 'precondition: the walk asked for a known cursor');

            $run->send($upstream[$cursor ?? '']);
        }

        self::assertSame(['a', 'b', 'c', 'd', 'e'], $run->getReturn());
        self::assertSame(3, $activityCalls, 'one activity call per upstream page');
    }

    /**
     * A workflow-shaped driver: it hands each cursor OUT as a yield and receives the
     * fetched page back in, which is what a Temporal activity call looks like from
     * inside workflow code — and the one thing a `PaginatedFetcher` cannot express.
     *
     * The `Page` is reconstructed here rather than marshalled across the boundary,
     * exactly as the recipe prescribes, so its constructor validation runs on the
     * side where the walk needs it to hold.
     *
     * @return \Generator<int, ?string, array{rows: list<string>, next: ?string}, list<string>>
     */
    private static function workflowDriver(): \Generator
    {
        $walk = new Walk(maxPages: 500);
        $collected = [];

        while ($walk->hasNext()) {
            $result = yield $walk->nextCursor();

            foreach ($result['rows'] as $row) {
                $collected[] = $row;
            }

            $walk->advance(new Page($result['rows'], $result['next'], $result['next'] !== null));
        }

        return $collected;
    }

    #[Test]
    public function aWalkSurvivesBeingReconstructedFromACheckpointBetweenRuns(): void
    {
        // What a resumable workflow or a batch job actually does: stop after a page,
        // persist the cursor, start a fresh Walk from it later. The two halves must
        // join up with no gap and no duplicate.
        $pages = [
            'p1' => self::pageWith('p2'),
            'p2' => self::pageWith('p3'),
            'p3' => self::terminalPage(),
        ];

        $firstRun = new Walk(maxPages: 1);
        self::assertNull($firstRun->nextCursor());
        $firstRun->advance($pages['p1']);

        $checkpoint = null;

        try {
            $firstRun->nextCursor();

            self::fail('a budget of one must stop the first run.');
        } catch (PageBudgetExceededException $exception) {
            $checkpoint = $exception->getLastCursor();
        }

        self::assertSame('p2', $checkpoint);

        $secondRun = new Walk($checkpoint, maxPages: 10);
        $requested = self::drive($secondRun, [$pages['p2'], $pages['p3']]);

        self::assertSame(['p2', 'p3'], $requested, 'the resumed walk must start exactly at the checkpoint');
        self::assertSame(2, $secondRun->pagesFetched());
    }
}
