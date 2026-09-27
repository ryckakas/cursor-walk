# AGENTS.md

This file provides guidance to coding agents working in this repository. `CLAUDE.md` is a symlink to
it, so edit this file, never the link.

## Commands

```bash
composer check          # cs + stan + complexity + test + offline example — the gate before any commit
composer check:ci       # everything CI runs: validate, cs, stan, complexity, coverage floor, example, mutation

composer test           # phpunit
composer stan           # phpstan analyse (level max, over src + tests + tools)
composer cs             # php-cs-fixer --dry-run --diff (exit 8 = needs fixing)
composer cs:fix         # apply the fixes
composer coverage       # phpunit + Clover + tools/check-coverage.php (floor: 100%)
composer mutation       # infection (floors: minMsi 85 / minCoveredMsi 85)
composer complexity     # bonsai-lint cognitive complexity gate (bonsai-lint.toml + baseline)

zizmor .github          # workflow security, CI's "Workflow security" job; not a composer script
```

Single test or subset:

```bash
vendor/bin/phpunit --filter aPageSizeOfExactlyOneIsTheSmallestLegalValueAndIsAccepted
vendor/bin/phpunit tests/Offset/OffsetFetcherTest.php
vendor/bin/phpunit --filter 'CursorWalk\\Tests\\WalkTest'
```

Pass extra flags through Composer with `--`: `composer stan -- --no-progress`.

### Environment gotchas

- **`composer cs` needs `--sequential` locally** — php-cs-fixer's parallel runner hangs in some
  Windows sandboxes: `composer cs -- --sequential`.
- **`coverage` and `mutation` need a coverage driver.** Without xdebug/pcov they cannot run at all;
  CI is then the only place those two floors are checked. Say so rather than claiming they passed.
- **Line endings are load-bearing.** `.gitattributes` pins `eol=lf` in the working tree because
  php-cs-fixer's PSR-12 ruleset requires LF; a CRLF checkout makes `composer cs` report every file
  as broken while CI passes. Don't "fix" that file.
- **`composer complexity` needs Node.** It runs `npx --yes bonsai-lint@<pinned>`, so `composer
  check` fails with "npx: command not found" on a machine without it. zizmor is a separate install
  (`brew install zizmor`, `cargo install zizmor`, or `uvx zizmor`); run the version `ci.yml` pins.

## Architecture

A zero-runtime-dependency library for the *fetching* half of cursor pagination. PHP 8.2+, PHPStan
level max, generics throughout (`@template-covariant T` on the fetcher/page types).

### The engine, and the one thing it refuses to know

`PaginatedFetcher::fetchPage(?string $cursor): Page` is the whole extension point, and it
deliberately never says what a cursor *means*. That single omission is why `src/Offset/` needed no
engine change: a page number or a row offset is already a legal opaque cursor. Preserve this — any
change that teaches the engine to interpret cursor contents is a design regression.

- **`Paginator`** (stateless, injectable) — the policy: page budget, three methods, nothing else.
  `items()`, `pages()`, `slice()`. `items()` and `slice()` delegate to `pages()` so the guards
  apply uniformly. All walk state lives in the returned generator, so concurrent walks never
  interfere.
- **`Walk`** (stateful, single-use) — the same policy as a caller-driven stepper for code that
  cannot let the library call `fetchPage()`: Temporal workflows (every fetch is an activity
  `yield`), event loops, Fibers. `Paginator::pages()` is a ~6-line driver over it, which is what
  stops the two from drifting.
- **`Page`** — the upstream envelope as a validated VO. Every combination of
  `(items, endCursor, hasNextPage)` is legal *except* `hasNextPage: true` with a null/empty
  `endCursor`. Empty mid-stream pages are valid; trailing cursors on a final page are legal and
  ignored. Stricter upstream policy is the fetcher's job, never the engine's.
- **`CursorCodec`** — `base64(json_encode(['v' => 1, 'c' => pageCursor, 'o' => offset]))` for
  synthetic per-item edge cursors. `decode()` **never throws**: three gates (base64 → JSON →
  envelope carrying both `v` and `o`), each falling back to treating the input as a foreign
  upstream cursor. Client-supplied input reaches it directly, so keep it total.
- **`src/Offset/`** — `PositionalFetcher` (shared page-size and cursor validation; both subclasses
  inherit its range guard) with `PageNumberFetcher` and `OffsetFetcher` on top. `OffsetPage` owns
  the terminal-condition precedence: `totalPages`, else `totalItems`, else "a page shorter than
  `$pageSize` is the last one".
- **`src/Relay/`** — `ConnectionFormatter` builds a Relay connection; `EdgeCursorStrategy` /
  `OffsetEdgeCursorStrategy` mint per-edge cursors.

### Invariants that are easy to break

- **`Paginator::pages()` ordering.** The budget throws *before* the fetch; a page is yielded
  *before* loop detection runs on it. A checkpointing consumer must see the offending page's valid
  items before `PaginationLoopException` fires. Pinned by characterization tests in
  `tests/PaginatorTest.php` — if those are the only tests that break, you changed the contract.
- **Exception taxonomy.** `CursorWalkException` is for upstream bugs and policy limits only.
  `Walk` protocol misuse raises a bare `\LogicException`, deliberately *outside* that hierarchy, so
  a caller catching the library's own base class never swallows its own bug.
  `PaginationLoopException` (bug, non-retryable) and `PageBudgetExceededException` (policy,
  resumable via `getLastCursor()`) must stay distinct classes.
- **`Walk` sets `finished` before throwing `PaginationLoopException`**, so a driver that swallows
  the exception cannot keep walking.
- **`hasNext()` is not "the next `nextCursor()` will succeed".** The budget is enforced in
  `nextCursor()`, so an exhausted walk still reports `true` and throws on the draw — a budget that
  ended the loop quietly would be indistinguishable from reaching the end of the stream.
- **`slice()`'s `endCursor` anchors to the last upstream page touched**, not to the slice's own
  start. That is what keeps repeated "load more" round trips O(1) instead of O(n²).
- **Positional cursors are client-supplied.** `PositionalFetcher` range-checks them because the
  position arithmetic overflows to `float` and would surface as a `TypeError` instead of the
  documented `MalformedPageException`. The bound scales with page size
  (`intdiv(PHP_INT_MAX, $pageSize) - 1`), so a cursor legal at one page size is not at another.
- **Report the total that matches your fetcher's unit.** `totalPages` is exact for
  `PageNumberFetcher`, `totalItems` for `OffsetFetcher`. The cross-unit pairing assumes every page
  is full and silently loses rows once an upstream filters server-side.

### Where the contracts live

There is no `docs/` tree. The documented contract is the **class docblocks**, with `README.md` as
the recipe mirror and `CHANGELOG.md` as the behavioural record. When changing behaviour, update the
docblock — it is the source, not commentary. Docblocks here are long on purpose: they carry
invariants, trade-offs, and traps. Inline comments still follow the usual bar (why, not what).

## Testing

Tests are organised by **ZOMBIES** section comments (`// Simple`, `// Zero / One`, `// Many`,
`// Boundaries`, `// Interfaces`, `// Exceptions`) — follow the existing layout in the file you
touch. Fixtures live in `tests/Support/` (`ArrayFetcher`, `ScriptedFetcher`, `LoopingFetcher`,
`InfiniteFetcher`, `PageNumberApiFetcher`, `OffsetApiFetcher`, …); prefer one over a new anonymous
class. Test names are full sentences describing the claim.

`phpunit.xml` sets `failOnWarning`, `failOnRisky` and `failOnDeprecation` — a warning fails the
build, and a test with no assertions is risky and therefore fails.

Coverage floor is **100%** and enforced by `tools/check-coverage.php`, so new branches need tests
or an explicit `@codeCoverageIgnore` with a reason. Mutation floor is **MSI 85**. Most surviving
mutants are equivalent (exception-message concatenation, `>=`→`>` absorbed by `array_slice`,
`$seen[$k] = true`→`false` read via `isset`) and documented as won't-fix in `infection.json5`;
raising the number by asserting exact message wording buys nothing. Treat each escaped mutant as
"if the code did this instead, would I care?" — and when a new logic branch escapes, that is a real
missing test.

PHPStan runs over `tests/` too, at level max, and there is no `phpstan-phpunit` extension — so
PHPUnit assertions do **not** narrow types. Generic helpers need explicit `@return Foo<string>`
annotations; a docblock on a closure assignment will not infer a generator's `TSend`.

## Repository conventions

- `main` is protected: 6 required checks (`PHP 8.2`/`8.3`/`8.4`/`8.5`, `Cognitive complexity`,
  `Workflow security`), `strict`, `enforce_admins: true`, 0 required approvals. Every change —
  including docs-only — goes through a PR. Renaming a CI job silently un-requires it; update
  branch protection in the same change.
- **Workflows are audited like code.** Every `uses:` is pinned to a full commit SHA with the
  release in a trailing comment, every checkout sets `persist-credentials: false`, and the token is
  read-only. A new `uses:` line follows the same form, and zizmor fails CI until it does. There is
  no `.github/zizmor.yml`: fix a finding rather than suppress it, and if one truly must be
  accepted, pin the ignore to its `file:line:col` with the reason beside it.
- **Two tool pins move by hand.** Dependabot (7-day cooldown) bumps Composer packages and action
  SHAs, but not the bonsai-lint version in the `complexity` script or the zizmor version in
  `ci.yml`. A bonsai-lint bump can move scores, so re-run the gate on the whole tree with it.
- **The complexity baseline only shrinks.** `.bonsai-lint-baseline.json` records the functions
  that were already over the threshold of 15 when the gate was adopted. A new or grown finding
  is a refactor, not a baseline entry. After a refactor lowers a recorded score, regenerate the
  file with `npx --yes bonsai-lint@<pinned> --write-baseline .` so it cannot creep back up.
- Dev dependencies are pinned to **exact** versions (no carets), which is what makes
  `composer audit` actionable. `require: php >=8.2` stays a range — it is a compatibility contract.
- Releases are tag-driven; Packagist syncs by webhook. `composer.json` carries no `version` field.
  `.gitattributes` `export-ignore` keeps dev files out of the dist archive; a new dev-only file
  at the root gets a line there too.
- Don't put the Claude session URL in PR descriptions or other published text.
