<?php

declare(strict_types=1);

namespace CursorWalk\Tests;

use CursorWalk\CursorCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CursorCodecTest extends TestCase
{
    /**
     * @return iterable<string, array{0: ?string, 1: int}>
     */
    public static function roundTripProvider(): iterable
    {
        yield 'null cursor, offset zero' => [null, 0];
        yield 'string cursor, offset zero' => ['page-1', 0];
        yield 'null cursor, large offset' => [null, 1_000_000];
        yield 'string cursor, large offset' => ['page-42', 987_654_321];
    }

    #[Test]
    #[DataProvider('roundTripProvider')]
    public function encodeThenDecodeRoundTripsThePair(?string $pageCursor, int $offset): void
    {
        $codec = new CursorCodec();

        $encoded = $codec->encode($pageCursor, $offset);
        [$decodedCursor, $decodedOffset] = $codec->decode($encoded);

        self::assertSame($pageCursor, $decodedCursor);
        self::assertSame($offset, $decodedOffset);
    }

    /**
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function hostilePageCursorProvider(): iterable
    {
        yield 'pipe delimited content' => ['a|b|c', 1];
        yield 'legacy-looking pipe cursor' => ['cursor|42', 2];
        yield 'base64-looking string' => ['eyJ2IjoxfQ==', 3];
        yield 'json-looking string' => ['{"v":1,"o":5}', 4];
        yield 'nested cursor produced by encode() itself' => [(new CursorCodec())->encode('inner', 3), 5];
        yield 'unicode content' => ["caf\u{e9}-\u{1f680}-\u{4e2d}\u{6587}", 6];
        yield 'newlines and tabs' => ["line1\nline2\tend", 7];

        // No raw-bytes case: json_encode() rejects invalid UTF-8, so such a cursor can never be encoded.
    }

    #[Test]
    #[DataProvider('hostilePageCursorProvider')]
    public function roundTripSurvivesHostilePageCursorContent(string $pageCursor, int $offset): void
    {
        $codec = new CursorCodec();

        $encoded = $codec->encode($pageCursor, $offset);
        [$decodedCursor, $decodedOffset] = $codec->decode($encoded);

        self::assertSame($pageCursor, $decodedCursor);
        self::assertSame($offset, $decodedOffset);
    }

    #[Test]
    public function roundTripPreservesEmptyStringCursorDistinctFromNull(): void
    {
        $codec = new CursorCodec();

        $encodedEmpty = $codec->encode('', 9);
        $encodedNull = $codec->encode(null, 9);

        // '' is a real upstream cursor and null means the first page. assertSame on the full tuple
        // matters: '' == null in PHP, so a loose comparison would pass either way.
        self::assertNotSame($encodedNull, $encodedEmpty);
        self::assertSame(['', 9], $codec->decode($encodedEmpty));
        self::assertSame([null, 9], $codec->decode($encodedNull));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function foreignButValidBase64Provider(): iterable
    {
        yield 'valid base64, plain text (not JSON)' => [base64_encode('offset-3')];
        yield 'valid base64, plain text with space (not JSON)' => [base64_encode('hello world')];
        yield 'valid base64, JSON object missing v and o' => [base64_encode('{"foo":"bar"}')];
        yield 'valid base64, JSON object missing o' => [base64_encode('{"v":1}')];
        yield 'valid base64, JSON object missing v' => [base64_encode('{"o":5}')];
        yield 'valid base64, JSON scalar integer' => [base64_encode('123')];
        yield 'valid base64, JSON scalar string' => [base64_encode('"a string"')];
    }

    #[Test]
    #[DataProvider('foreignButValidBase64Provider')]
    public function decodeTreatsValidBase64WithoutUsableEnvelopeAsForeign(string $input): void
    {
        $codec = new CursorCodec();

        [$cursor, $offset] = $codec->decode($input);

        self::assertSame($input, $cursor);
        self::assertSame(0, $offset);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function foreignUpstreamCursorProvider(): iterable
    {
        yield 'plainly non-base64 string' => ['not a cursor!!'];
        yield 'opaque upstream cursor (relay-style)' => ['Y3Vyc29yOnYyOgo='];
        yield 'github link-header style url' => ['https://api.github.com/repositories/1/tags?page=2'];
        yield 'very long string' => [str_repeat('abcdefghij', 500)];
        yield 'string with characters illegal in base64' => ['not-valid-base64!!@@'];
    }

    #[Test]
    #[DataProvider('foreignUpstreamCursorProvider')]
    public function decodeNeverThrowsOnForeignUpstreamCursorAndReturnsItUnchanged(string $input): void
    {
        $codec = new CursorCodec();

        [$cursor, $offset] = $codec->decode($input);

        self::assertSame($input, $cursor);
        self::assertSame(0, $offset);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function positionalCursorProvider(): iterable
    {
        // Odd-length numerics fail the base64 gate; even-length ones die at the JSON gate.
        // The table covers both parities.
        yield 'single digit page number' => ['2'];
        yield 'single digit, zero' => ['0'];
        yield 'two digits' => ['12'];
        yield 'three digits' => ['100'];
        yield 'four digits' => ['1024'];
        yield 'large row offset' => ['987654'];
    }

    #[Test]
    #[DataProvider('positionalCursorProvider')]
    public function decodePassesPlainIntegerCursorsThroughUntouched(string $input): void
    {
        // PageNumberFetcher and OffsetFetcher emit bare integer cursors. Misreading one as an
        // envelope would resume a page-numbered upstream at the wrong window.
        $codec = new CursorCodec();

        self::assertSame([$input, 0], $codec->decode($input));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function structurallyValidButUnusableEnvelopeProvider(): iterable
    {
        yield 'offset is a string' => [base64_encode('{"v":1,"c":null,"o":"5"}')];
        yield 'offset is a float' => [base64_encode('{"v":1,"c":null,"o":1.5}')];
        yield 'offset is null' => [base64_encode('{"v":1,"c":null,"o":null}')];
        yield 'offset is negative' => [base64_encode('{"v":1,"c":null,"o":-1}')];
        yield 'page cursor is an int' => [base64_encode('{"v":1,"c":7,"o":0}')];
        yield 'page cursor is an array' => [base64_encode('{"v":1,"c":["a"],"o":0}')];
        yield 'page cursor is a bool' => [base64_encode('{"v":1,"c":true,"o":0}')];
    }

    #[Test]
    #[DataProvider('structurallyValidButUnusableEnvelopeProvider')]
    public function decodeFallsBackToForeignForAnEnvelopeItCannotTrust(string $input): void
    {
        // Clients can send any string, and these decode far enough to look like our envelope.
        // Trusting them would surface as a TypeError deep inside Paginator.
        $codec = new CursorCodec();

        self::assertSame([$input, 0], $codec->decode($input));
    }

    #[Test]
    public function decodeAcceptsAZeroOffsetWhichIsTheOnlyNonNegativeEdgeCase(): void
    {
        // Pins the guard against `$offset < 1` or `!$offset`: zero means "this page, nothing skipped".
        $codec = new CursorCodec();

        self::assertSame([null, 0], $codec->decode($codec->encode(null, 0)));
        self::assertSame(['page-2', 0], $codec->decode($codec->encode('page-2', 0)));
    }

    #[Test]
    public function decodeTreatsTheEmptyStringAsTheFirstPage(): void
    {
        // `$args['after'] ?? ''` yields '' for an absent argument; passing it through as ['', 0]
        // would make the resolver recipes call fetchPage('').
        $codec = new CursorCodec();

        self::assertSame([null, 0], $codec->decode(''));
    }

    #[Test]
    public function encodeProducesReadableDebuggableEnvelope(): void
    {
        $codec = new CursorCodec();

        $encoded = $codec->encode('page-9', 12);

        $decodedBytes = base64_decode($encoded, true);
        self::assertIsString($decodedBytes);

        /** @var mixed $envelope */
        $envelope = json_decode($decodedBytes, true);
        self::assertIsArray($envelope);
        self::assertSame(1, $envelope['v']);
        self::assertSame('page-9', $envelope['c']);
        self::assertSame(12, $envelope['o']);
    }

    #[Test]
    public function encodeIsDeterministicForTheSameInput(): void
    {
        $codec = new CursorCodec();

        $first = $codec->encode('same-cursor', 5);
        $second = $codec->encode('same-cursor', 5);

        self::assertSame($first, $second);
    }

    #[Test]
    public function encodeProducesDifferentCursorsForDifferentInputs(): void
    {
        $codec = new CursorCodec();

        $a = $codec->encode('cursor-a', 1);
        $b = $codec->encode('cursor-b', 2);

        self::assertNotSame($a, $b);
    }
}
