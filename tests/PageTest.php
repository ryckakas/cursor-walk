<?php

declare(strict_types=1);

namespace CursorWalk\Tests;

use CursorWalk\Exception\MalformedPageException;
use CursorWalk\Page;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase
{
    /**
     * A non-list array (has gaps or string keys) for constructor-validation tests.
     *
     * @return array<int|string, string>
     */
    private static function nonListWithGap(): array
    {
        return [1 => 'a'];
    }

    /**
     * @return array<int|string, string>
     */
    private static function nonListWithStringKeys(): array
    {
        return ['k' => 'v'];
    }

    /**
     * @return iterable<string, array{0: list<mixed>, 1: ?string, 2: bool}>
     */
    public static function invalidConstructorArgsProvider(): iterable
    {
        yield 'hasNextPage=true, endCursor=null' => [[], null, true];
        yield 'hasNextPage=true, endCursor=empty string' => [[], '', true];
    }

    /**
     * @param list<mixed> $items
     */
    #[Test]
    #[DataProvider('invalidConstructorArgsProvider')]
    public function constructorRejectsHasNextPageWithoutUsableCursor(
        array $items,
        ?string $endCursor,
        bool $hasNextPage,
    ): void {
        // spec case 1
        $this->expectException(MalformedPageException::class);

        new Page($items, $endCursor, $hasNextPage);
    }

    #[Test]
    public function constructorRejectsNonListItemsWithGap(): void
    {
        // spec case 1
        $this->expectException(MalformedPageException::class);

        /** @phpstan-ignore argument.type */
        new Page(self::nonListWithGap(), null, false);
    }

    #[Test]
    public function constructorRejectsNonListItemsWithStringKeys(): void
    {
        // spec case 1
        $this->expectException(MalformedPageException::class);

        /** @phpstan-ignore argument.type */
        new Page(self::nonListWithStringKeys(), null, false);
    }

    #[Test]
    public function constructorAllowsTrailingCursorOnFinalPage(): void
    {
        // hasNextPage=false with a non-null endCursor is explicitly legal.
        $page = new Page(['a', 'b'], 'trailing-cursor', false);

        self::assertSame('trailing-cursor', $page->endCursor);
        self::assertFalse($page->hasNextPage);
    }

    #[Test]
    public function constructorAllowsEmptyItemsWithNextPageAndCursor(): void
    {
        // an empty mid-stream page is valid as long as a cursor is present.
        $page = new Page([], 'next-cursor', true);

        self::assertTrue($page->isEmpty());
        self::assertTrue($page->hasNextPage);
        self::assertSame('next-cursor', $page->endCursor);
    }

    #[Test]
    public function constructorAllowsEmptyItemsWithNoNextPage(): void
    {
        $page = new Page([], null, false);

        self::assertTrue($page->isEmpty());
        self::assertFalse($page->hasNextPage);
    }

    #[Test]
    public function constructorStoresTotalCountVerbatimIncludingZero(): void
    {
        $page = new Page([], null, false, 0);

        self::assertSame(0, $page->totalCount);
    }

    #[Test]
    public function constructorDefaultsTotalCountToNull(): void
    {
        $page = new Page(['x'], null, false);

        self::assertNull($page->totalCount);
    }

    #[Test]
    public function emptyPageHasExpectedShape(): void
    {
        // spec case 2
        $page = Page::empty();

        self::assertSame([], $page->items);
        self::assertNull($page->endCursor);
        self::assertFalse($page->hasNextPage);
        self::assertNull($page->totalCount);
        self::assertTrue($page->isEmpty());
        self::assertSame(0, $page->count());
    }

    #[Test]
    public function emptyPageEqualsFreshlyConstructedEquivalent(): void
    {
        // spec case 2
        $page = Page::empty();
        $equivalent = new Page([], null, false);

        self::assertEquals($equivalent, $page);
    }

    #[Test]
    public function countReturnsNumberOfItems(): void
    {
        $page = new Page(['a', 'b', 'c'], null, false);

        self::assertSame(3, $page->count());
        self::assertFalse($page->isEmpty());
    }

    #[Test]
    public function isEmptyIsTrueOnlyWhenCountIsZero(): void
    {
        self::assertTrue((new Page([], null, false))->isEmpty());
        self::assertFalse((new Page([0], null, false))->isEmpty());
    }
}
