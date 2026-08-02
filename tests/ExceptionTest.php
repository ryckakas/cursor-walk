<?php

declare(strict_types=1);

namespace CursorWalk\Tests;

use CursorWalk\Exception\CursorWalkException;
use CursorWalk\Exception\MalformedPageException;
use CursorWalk\Exception\PageBudgetExceededException;
use CursorWalk\Exception\PaginationLoopException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExceptionTest extends TestCase
{
    #[Test]
    public function missingCursorProducesMalformedPageException(): void
    {
        $exception = MalformedPageException::missingCursor();

        self::assertInstanceOf(MalformedPageException::class, $exception);
        self::assertInstanceOf(CursorWalkException::class, $exception);
        self::assertInstanceOf(\RuntimeException::class, $exception);
        self::assertNotSame('', $exception->getMessage());
    }

    #[Test]
    public function invalidEnvelopeWithOnlyReasonHasNullCursorAndRawContext(): void
    {
        $exception = MalformedPageException::invalidEnvelope('some reason');

        self::assertStringContainsString('some reason', $exception->getMessage());
        self::assertNull($exception->getCursor());
        self::assertNull($exception->getRawContext());
    }

    #[Test]
    public function invalidEnvelopeCarriesCursorAndRawContext(): void
    {
        $exception = MalformedPageException::invalidEnvelope('reason', 'the-cursor', ['raw' => 'payload']);

        self::assertStringContainsString('reason', $exception->getMessage());
        self::assertSame('the-cursor', $exception->getCursor());
        self::assertSame(['raw' => 'payload'], $exception->getRawContext());
    }

    #[Test]
    public function repeatedCursorProducesPaginationLoopException(): void
    {
        $exception = PaginationLoopException::repeatedCursor('abc');

        self::assertInstanceOf(PaginationLoopException::class, $exception);
        self::assertInstanceOf(CursorWalkException::class, $exception);
        self::assertStringContainsString('abc', $exception->getMessage());
    }

    #[Test]
    public function exceededProducesPageBudgetExceededExceptionWithNullLastCursorByDefault(): void
    {
        $exception = PageBudgetExceededException::exceeded(10);

        self::assertInstanceOf(PageBudgetExceededException::class, $exception);
        self::assertInstanceOf(CursorWalkException::class, $exception);
        self::assertSame(10, $exception->getMaxPages());
        self::assertNull($exception->getLastCursor());
        self::assertStringContainsString('10', $exception->getMessage());
    }

    // INTEGRATION NOTE: the spec does not fix how PageBudgetExceededException
    // attaches a "last cursor" to the exceeded() factory. This call assumes the
    // factory signature is widened to exceeded(int $maxPages, ?string $lastCursor = null).
    // If the src author instead exposes a `withLastCursor()` wither, a different
    // constructor argument order, or another mechanism entirely, only this one
    // call site (and its two assertions below) needs adjusting.
    #[Test]
    public function exceededCarriesLastCursorWhenProvided(): void
    {
        $exception = PageBudgetExceededException::exceeded(maxPages: 5, lastCursor: 'cur-5');

        self::assertSame(5, $exception->getMaxPages());
        self::assertSame('cur-5', $exception->getLastCursor());
    }

    #[Test]
    public function malformedPageExceptionIsCatchableAsCursorWalkException(): void
    {
        try {
            throw MalformedPageException::missingCursor();
        } catch (CursorWalkException $exception) {
            self::assertInstanceOf(MalformedPageException::class, $exception);
        }
    }

    #[Test]
    public function paginationLoopExceptionIsCatchableAsCursorWalkException(): void
    {
        try {
            throw PaginationLoopException::repeatedCursor('xyz');
        } catch (CursorWalkException $exception) {
            self::assertInstanceOf(PaginationLoopException::class, $exception);
        }
    }

    #[Test]
    public function pageBudgetExceededExceptionIsCatchableAsCursorWalkException(): void
    {
        try {
            throw PageBudgetExceededException::exceeded(3);
        } catch (CursorWalkException $exception) {
            self::assertInstanceOf(PageBudgetExceededException::class, $exception);
        }
    }
}
