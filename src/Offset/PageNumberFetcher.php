<?php

declare(strict_types=1);

namespace CursorWalk\Offset;

use CursorWalk\Page;

/**
 * Base class for an upstream paginated by PAGE NUMBER — `?page=2&per_page=50`,
 * the most common shape in B2B REST APIs.
 *
 * Implement one method and the engine's whole surface — `items()`, `pages()`,
 * `slice()`, `ConnectionFormatter` — works over a page-numbered API unchanged:
 *
 * ```php
 * /** @extends PageNumberFetcher<array<string, mixed>> *\/
 * final class ContactsFetcher extends PageNumberFetcher
 * {
 *     public function __construct(private readonly HttpClient $http)
 *     {
 *         parent::__construct(pageSize: 50);
 *     }
 *
 *     protected function fetchAt(int $position, int $pageSize): OffsetPage
 *     {
 *         $body = $this->http->getJson('/contacts', ['page' => $position, 'per_page' => $pageSize]);
 *
 *         return new OffsetPage($body['data'], totalPages: $body['meta']['totalPages'] ?? null);
 *     }
 * }
 * ```
 *
 * A 0-indexed upstream (Spring Data and friends) needs no separate class and no
 * constructor flag — request `$position - 1` inside `fetchAt()` and leave the
 * walk 1-based.
 *
 * ## Wire format: page numbers ARE opaque cursors
 *
 * {@see \CursorWalk\PaginatedFetcher::fetchPage()} never says what a cursor
 * means, so `"2"` is a perfectly legal one and nothing in the engine changes to
 * accept it. The format is a bare integer string rather than an encoded envelope
 * on purpose: {@see \CursorWalk\CursorCodec::decode()} passes plain numerics
 * through untouched as foreign cursors, so edge-cursor round-tripping in a
 * GraphQL resolver keeps working, whereas a second base64-JSON envelope is
 * exactly what could collide with the position codec.
 *
 * ## The page size is part of the cursor here — read this
 *
 * `"3"` only means anything relative to the page size it was produced with:
 * change `$pageSize` and every cursor already in flight addresses a different
 * window. That is why the constructor takes it with NO default — the coupling has
 * to be a deliberate, fixed choice.
 *
 * **Never derive `$pageSize` from a per-request Relay `first`.** A resolver that
 * does silently resumes returned cursors at the wrong offset the moment a client
 * asks for a different page size. {@see \CursorWalk\Paginator::slice()} is what
 * reconciles a fixed upstream chunk size with a variable `first`: keep the
 * fetcher's page size constant and let `slice()` cut the window.
 *
 * The asymmetry is worth knowing: {@see OffsetFetcher} cursors are row offsets
 * and carry no such coupling, so prefer it whenever the upstream accepts raw
 * offsets.
 *
 * The same coupling reaches the terminal condition, so **report `totalPages` from
 * this fetcher whenever the envelope has it** — it is the signal that matches this
 * fetcher's unit. {@see OffsetPage::hasMoreAfter()} states what the alternatives
 * cost, and why the match stops being a preference and becomes a requirement once
 * mid-stream pages can come back short.
 *
 * ## Repeated-cursor detection is INERT here — read this
 *
 * Page numbers increase monotonically and therefore never repeat, so
 * {@see \CursorWalk\Exception\PaginationLoopException} can never fire for this
 * fetcher. What replaces it is stronger, not weaker: when the envelope reports
 * `totalPages` or `totalItems`, {@see OffsetPage::hasMoreAfter()} catches a
 * runaway envelope on the very page that produced it, rather than one page later
 * the way cursor-repeat detection would. When it reports NEITHER, the only thing
 * standing between you and an upstream that claims a full page forever is the
 * page budget — so keep `Paginator`'s budget on, and size it deliberately.
 *
 * @template-covariant T
 *
 * @extends PositionalFetcher<T>
 */
abstract class PageNumberFetcher extends PositionalFetcher
{
    /**
     * The first page of a page-numbered upstream.
     */
    private const FIRST_PAGE = 1;

    /**
     * Fetch the rows of one page.
     *
     * @param int $position 1-based page number to request
     * @param int $pageSize rows to request, always the constructor's `$pageSize`
     *
     * @return OffsetPage<T>
     */
    abstract protected function fetchAt(int $position, int $pageSize): OffsetPage;

    /**
     * Sealed: the engine contract is satisfied here, in terms of
     * {@see self::fetchAt()}.
     *
     * @return Page<T>
     */
    final public function fetchPage(?string $cursor): Page
    {
        $pageNumber = $this->positionFrom($cursor, self::FIRST_PAGE);
        $result = $this->fetchAt($pageNumber, $this->pageSize);

        $itemsThrough = ($pageNumber - 1) * $this->pageSize + \count($result->items);
        $hasNextPage = $result->hasMoreAfter($pageNumber, $itemsThrough, $this->pageSize);

        return new Page(
            $result->items,
            $hasNextPage ? (string) ($pageNumber + 1) : null,
            $hasNextPage,
            $result->totalItems,
        );
    }
}
