# Roadmap

What is likely to come next, and why. Nothing here has a date; the order is the order things would
be built in. What already shipped is in the [changelog](CHANGELOG.md).

## v2: ID-anchored edge cursors

The planned answer to the [cursor stability](docs/design.md#cursor-stability) trade-off. An
optional `ItemIdExtractor` on the edge cursor strategy pulls a stable identifier out of each item.
The cursor then encodes `(pageCursor, lastSeenItemId)` instead of `(pageCursor, offset)`, and
resuming becomes "fetch that page, skip until just after that ID" rather than "skip N items". That
survives inserts and deletes anywhere in the page.

It degrades cleanly: with no extractor configured, the strategy stays in today's offset mode. The
`CursorCodec` envelope already carries a `v` field for this migration, so cursors issued by v1 keep
decoding after the upgrade.

## Async and concurrent fetching

A prefetching driver over `Walk`, so `slice()` can fetch the next upstream page while the current
one is being mapped. The split between policy (`Walk`) and driver (`Paginator`) in 0.2.0 was the
prerequisite: this is now a second driver, not a re-architecture. Concurrency stays bounded, and
laziness stays the default.

## Backward pagination

`before` / `last`, for upstreams that actually support reverse traversal. It ranks below async
because most opaque-cursor upstreams cannot support it at all, and
[bwaidelich/relay-pagination](https://github.com/bwaidelich/relay-pagination) already covers the
case where you control the data. The truthful `hasPreviousPage` in 0.2.0 removed the most visible
symptom without opening this door.

## Out of scope

A built-in HTTP client, caching, fan-out across sources, and telemetry hooks.
[What it deliberately does not do](docs/design.md#what-it-deliberately-does-not-do) explains why.
