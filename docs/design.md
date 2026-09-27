# Design notes

Why cursor-walk behaves the way it does, what it gives up to do so, and where it sits next to other
packages. For how to use it, see the [README](../README.md) and the [recipes](recipes.md).

- [The guards: bug versus policy](#the-guards-bug-versus-policy)
- [Cursor stability](#cursor-stability)
- [What it deliberately does not do](#what-it-deliberately-does-not-do)
- [Where it fits among other packages](#where-it-fits-among-other-packages)

## The guards: bug versus policy

Two independent guards stop a walk, and they throw different exception classes on purpose.

`PaginationLoopException` fires when a cursor repeats. That is only ever a defect, in the upstream
or in how the fetcher extracts its cursor, and it would loop until the process is killed. It is
neither retryable nor resumable, so it gets a class you can alert on.

`PageBudgetExceededException` fires when `maxPages` is exhausted. That is not a defect but the
limit you set doing its job. It carries `getLastCursor()` and `getMaxPages()` so the caller can
continue in the next unit of work. One exception for both would make every caller parse a message
to choose between "resume" and "page the on-call engineer".

Loop detection alone is not enough, which is why the budget is on by default. An upstream that
returns a fresh cursor with `hasNextPage: true` forever never repeats a cursor and never ends. The
default of 10,000 pages is high enough that no legitimate interactive workload reaches it, and low
enough that a runaway ends in seconds rather than taking down the process.

`maxPages: null` turns the budget off, and has one more cost. Loop detection keeps every cursor it
has seen, about 100 bytes per page. Under the default budget that caps out at a few megabytes; on an
unbounded multi-million-page backfill it does not. Run walks of that size as bounded chunks resumed
from `pages()` checkpoints instead.

## Cursor stability

**A synthetic edge cursor names a position, not an item.** This is the most important trade-off in
the package, so it is written down here rather than discovered in production.

Most upstreams give one cursor per page, but a Relay client sends back one edge's cursor as
`after`. `OffsetEdgeCursorStrategy` bridges the gap with `(page cursor, offset within page)`,
encoded by `CursorCodec` as base64-wrapped JSON with a version field. `slice()` and `items()`
resume by re-fetching that page and skipping the offset.

So an edge cursor is exact only while the upstream data holds still between two requests. If three
rows are inserted before its page in the meantime, the next page repeats three; if three are
deleted, it skips three. That is the drift of offset pagination, scoped to a single page instead of
the whole dataset.

- **Good fit:** interactive paging, where "next" follows seconds later on a slow-moving list.
- **Poor fit:** cursors stored for later (in a database, an emailed link, a job that runs tomorrow)
  over data that changes quickly.
- **Best, where available:** item-level cursors from the upstream, returned through your own
  [`EdgeCursorStrategy`](recipes.md#edge-cursors). `decode()` passes them through untouched, so
  the round trip needs no further wiring.
- **Also stable:** page cursors from `pages()` and `$page->endCursor` are the upstream's own,
  unmodified. A checkpointed batch job is exactly as stable as the upstream makes it.

The default makes Relay pagination work against upstreams with page cursors only, with its failure
mode named out loud. ID-anchored edge cursors are the planned fix; see the [roadmap](../ROADMAP.md).

## What it deliberately does not do

**Backward pagination.** Only `after`-style forward pagination exists. `before` / `last` is not a
mirror image of forward paging: it needs a reverse cursor or a stable total order, and most APIs
that hand out opaque forward cursors expose neither. Emulating it means buffering, or walking
forward from the start to find the window, which gives up the memory guarantee that is the point of
the package.

`pageInfo.hasPreviousPage` is still reported truthfully (since 0.2.0). A start position other than
the origin proves that something precedes the window, and the Relay spec allows saying so. That
adds no way to travel backwards.

**HTTP.** The fetcher owns transport. That keeps `require` at `php` alone, and it means your retry
middleware, token refresh, connection pool, timeouts, tracing and test doubles keep working
untouched. A paginator that also owned HTTP would be worse at both jobs and would force a client
choice on every consumer. A database cursor, an SDK or a local file implements the same one-method
interface with no adapter.

**Caching, fan-out across sources, telemetry hooks.** Fan-out needs relevance tiers, per-source
weights, interleaving and failure isolation, none of which generalise, so a generic merger would
either hardcode one arbitrary policy or grow a configuration surface larger than this package.
Telemetry already has two seams: log inside `fetchPage()`, or log per page while consuming
`pages()`.

## Where it fits among other packages

cursor-walk stops at plain PHP arrays, so it adds to the PHP GraphQL stack rather than competing
with it.

**[webonyx/graphql-php](https://github.com/webonyx/graphql-php).** `ConnectionFormatter::format()`
returns `edges`, `pageInfo` and an optional `totalCount`, which is what webonyx's default field
resolver walks. Return the array straight from a resolver on a connection type: no adapter, no
wrapper object, and no webonyx dependency in this package.

**[ivome/graphql-relay-php](https://github.com/ivome/graphql-relay-php).** It covers the schema
half of Relay: the `Connection`, `Edge` and `PageInfo` types, and `connectionFromArray()` for data
you already hold. That function paginates an in-memory array, so using it against a paginated
upstream means fetching everything first. Pair them: its types in your schema, cursor-walk for the
data, formatted by `ConnectionFormatter` or handed over as `slice(...)->items` to
`connectionFromArraySlice()`.

**[bwaidelich/relay-pagination](https://github.com/bwaidelich/relay-pagination).** It solves the
neighbouring problem, and where it fits it is the better tool. It serves one Relay connection page
at a time from a data source you control (an array, a callback, Doctrine), and it supports backward
pagination today. If your resolver is backed by your own database, reach for it first. cursor-walk
picks up where your control ends: the upstream is already paginated, with opaque cursors you did
not mint and cannot re-derive.

| | relay-pagination | cursor-walk |
| --- | --- | --- |
| Data source | Yours: array, callback, Doctrine | Somebody else's paginated API |
| Unit of work | Serve one connection page | Walk many upstream pages lazily |
| Backward pagination | Yes | No, forward only |
| Exact-N `first` | One page in, one page out | `slice()` spans as many fetches as it takes |
| Runaway upstream | Not applicable | Loop detection, page budget, resume cursors |
| Malformed page | Not applicable | `MalformedPageException` with cursor and raw payload |

**No GraphQL at all.** `items()` and `pages()` are plain generators, so a queue worker, a CSV
export or an ETL job uses them the same way. The Relay formatter lives in `CursorWalk\Relay\` and
is entirely optional: it depends on the core, never the reverse.
