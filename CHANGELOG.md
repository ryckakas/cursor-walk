# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Planned

- ID-anchored edge cursors via an optional `ItemIdExtractor`: encode
  `(pageCursor, lastSeenItemId)` and resume by skipping until just after that ID, making
  cursors resilient to upstream inserts and deletes. Degrades to the current offset mode when
  no extractor is configured; the `CursorCodec` envelope's `v` field already reserves the
  migration path.
- Async / concurrent page fetching with bounded concurrency, as a second driver over
  `CursorWalk\Walk`.
- Backward pagination (`before` / `last`), for upstreams that actually support reverse traversal.

## [0.2.0] - 2026-08-05

Widens what the package can walk, without widening what the engine knows. Every addition either
moves code that already existed or adds a fetcher-side base class; `Paginator`'s semantics are
unchanged and there is still no runtime dependency.

### Added

- `CursorWalk\Offset\PageNumberFetcher` and `CursorWalk\Offset\OffsetFetcher` — base classes for
  upstreams paginated by page number (`?page=2&per_page=50`) or row offset
  (`?offset=100&limit=50`). Implement `fetchAt(int $position, int $pageSize): OffsetPage` and the
  whole engine works over them: a page number is already a legal opaque cursor, so no engine
  change was needed. Cursors are plain integer strings, which `CursorCodec::decode()` passes
  through untouched as foreign cursors.
  - `CursorWalk\Offset\OffsetPage` — the envelope you report from `fetchAt()`: `items`, plus an
    optional `totalItems` and `totalPages`. Owns the terminal-condition precedence — `totalPages`,
    else `totalItems`, else "a page shorter than `$pageSize` is the last one".
  - `CursorWalk\Offset\PositionalFetcher` — the page-size and cursor-parsing plumbing the two
    share. Extend one of the two above, not this.
  - Note that repeated-cursor detection is **inert** for page numbers, since positions never
    repeat; a reported `totalPages`/`totalItems` is the stronger guard where the envelope has one,
    and the page budget is the backstop where it does not. `PageNumberFetcher` cursors are also
    coupled to the page size they were produced with — the constructor therefore takes `$pageSize`
    with no default. Prefer `OffsetFetcher` when the upstream accepts raw offsets.
- `CursorWalk\Walk` — the walk policy (budget accounting, cursor threading, repeated-cursor
  detection, termination) as a stepper the caller drives: `hasNext()`, `nextCursor()`,
  `advance(Page $page)`, `pagesFetched()`. For callers that cannot let the library call
  `fetchPage()` — a Temporal workflow, where every fetch goes through an activity `yield`; an
  event loop; a Fiber. `Paginator` is implemented in terms of it, so the two cannot drift.
  `Paginator` remains the class to use, and the one to inject.
  - Single-use, and enforces strictly alternating `nextCursor()` / `advance()`. A violation is a
    driver bug and raises a bare `\LogicException` — deliberately not a `CursorWalkException`,
    which is reserved for upstream bugs and policy limits. No new exception types.

### Changed

- **`pageInfo.hasPreviousPage` is now reported truthfully** instead of being hardcoded `false`.
  It is `true` exactly when the `$pageStartCursor` passed to `ConnectionFormatter::format()`
  describes a position other than the origin. Previously every page after the first reported
  `false`, which was wrong: the formatter already held the fact, and the Relay spec permits
  reporting it. This is not backward pagination and adds no way to travel backwards. A caller that
  omits `$pageStartCursor` still gets `false`, since the page is then positioned as though it began
  the stream.
- `ConnectionFormatter` takes an optional second constructor argument, a `CursorCodec`, used to
  read `$pageStartCursor`. Defaulted, so `new ConnectionFormatter()` is unchanged.
- `CursorCodec::decode('')` now returns `[null, 0]` (the first page) instead of passing the
  empty string through as `['', 0]`, so the `$codec->decode($args['after'] ?? '')` recipe
  requests the first page when the argument is absent.

### Internal

- `Paginator::pages()` is now a driver over `Walk` rather than an inline loop. The yield/guard
  ordering is preserved exactly — the budget throws before the fetch, and a page is yielded before
  loop detection runs on it — and that ordering is now pinned by characterization tests rather
  than left implicit.

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

- Forward-only in this release: `pageInfo.hasPreviousPage` is always `false`. (Corrected in
  0.2.0 — it was never a structural constraint.)
- Synthetic edge cursors are positional (page cursor plus offset) and therefore valid only
  while the upstream data does not shift between requests. See the "Cursor stability" section
  of the README for the full tradeoff.

[Unreleased]: https://github.com/ryckakas/cursor-walk/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/ryckakas/cursor-walk/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/ryckakas/cursor-walk/releases/tag/v0.1.0
