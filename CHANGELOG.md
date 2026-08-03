# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- `CursorCodec::decode('')` now returns `[null, 0]` (the first page) instead of passing the
  empty string through as `['', 0]`, so the `$codec->decode($args['after'] ?? '')` recipe
  requests the first page when the argument is absent.

### Planned

- ID-anchored edge cursors via an optional `ItemIdExtractor`: encode
  `(pageCursor, lastSeenItemId)` and resume by skipping until just after that ID, making
  cursors resilient to upstream inserts and deletes. Degrades to the current offset mode when
  no extractor is configured; the `CursorCodec` envelope's `v` field already reserves the
  migration path.
- Backward pagination (`before` / `last`) and a meaningful `pageInfo.hasPreviousPage`.
- Async / concurrent page fetching with bounded concurrency.

## [0.1.0] - 2026-08-02

Initial release. Forward-only cursor pagination for PHP 8.2+, with zero runtime dependencies.

### Added

- `CursorWalk\Page` — immutable, final value object holding `items`, `endCursor`,
  `hasNextPage` and an optional `totalCount`, plus `isEmpty()`, `count()` and the
  `Page::empty()` named constructor for a terminal empty page. Validates on construction that
  `items` is a list and that `hasNextPage: true` is never paired with a null or empty cursor.
  Empty mid-stream pages are explicitly legal.
- `CursorWalk\PaginatedFetcher` — the single integration point: `fetchPage(?string $cursor): Page`,
  where a `null` cursor requests the first page.
- `CursorWalk\Paginator` — the stateless walk engine with a three-method API:
  - `items()` — lazy item-by-item iteration across pages, with optional `$pageCursor` start
    position and first-page `$skip`; one upstream fetch per page, made only when iteration
    crosses a page boundary.
  - `pages()` — lazy whole-page iteration with an optional `$startCursor`, for per-page
    transaction, retry and checkpoint boundaries.
  - `slice()` — eager assembly of exactly `$first` items across as many upstream pages as
    needed, returning a `Page` whose `endCursor` encodes the real end position (including
    mid-upstream-page) and whose `hasNextPage` is accurate.
- Bounded-by-default page budget: `new Paginator()` stops after 10,000 pages;
  `new Paginator(maxPages: null)` disables the limit.
- Repeated-cursor loop detection.
- `CursorWalk\CursorCodec` — round-trippable synthetic edge cursors encoded as
  `base64(json_encode(['v' => 1, 'c' => $pageCursor, 'o' => $offset]))`. `decode()` never
  throws: values failing any of the three gates (valid base64, valid JSON, required `v`/`o`
  keys) are treated as foreign upstream cursors and returned as `[$value, 0]`.
- `CursorWalk\Exception\CursorWalkException` — base exception, extends `\RuntimeException`.
- `CursorWalk\Exception\MalformedPageException` — unusable upstream page; named constructors
  `missingCursor()` and `invalidEnvelope()`, carrying `getCursor()` and `getRawContext()` for
  debugging.
- `CursorWalk\Exception\PaginationLoopException` — a repeated cursor, signalling an upstream or
  fetcher bug that would never terminate.
- `CursorWalk\Exception\PageBudgetExceededException` — policy, not a bug: exposes
  `getMaxPages()` and `getLastCursor()` so a bounded walk can be resumed rather than restarted.
- `CursorWalk\Relay\ConnectionFormatter` — optional, dependency-free formatting of a `Page`
  into a Relay connection array (`edges` with `node`/`cursor`, `pageInfo` with `endCursor`,
  `hasNextPage`, `startCursor` and `hasPreviousPage`, plus `totalCount` as a sibling key when
  the page carries one), with an optional node mapper.
- `CursorWalk\Relay\EdgeCursorStrategy` and the default `OffsetEdgeCursorStrategy`, which
  derives per-edge cursors from `CursorCodec`; inject your own to use item-level cursors when
  the upstream provides them.
- Examples: an offline example runnable with no network access (executed in CI as a smoke
  test) and a best-effort live GitHub REST API example.

### Notes

- Forward-only in this release: `pageInfo.hasPreviousPage` is always `false`.
- Synthetic edge cursors are positional (page cursor plus offset) and therefore valid only
  while the upstream data does not shift between requests. See the "Cursor stability" section
  of the README for the full tradeoff.

[Unreleased]: https://github.com/ryckakas/cursor-walk/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/ryckakas/cursor-walk/releases/tag/v0.1.0
