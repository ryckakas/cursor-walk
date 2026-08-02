<?php

declare(strict_types=1);

/**
 * cursor-walk: live GitHub REST API example.
 *
 * This script hits the real, public GitHub API (no authentication required,
 * though it is much less rate-limited if you set GITHUB_TOKEN). It is a
 * best-effort demo, NOT a test: it is intentionally excluded from CI (see
 * .github/workflows/ci.yml, which only runs examples/offline-example.php),
 * because live-network scripts should never fail a build over a rate limit
 * or a transient outage.
 *
 * It walks a public repository's tags (`GET /repos/{owner}/{repo}/tags`) and
 * demonstrates the single most common real-world cursor-walk pattern: the
 * opaque cursor IS the upstream's own pagination token, forwarded verbatim.
 * GitHub exposes that token as a `rel="next"` URL in the `Link` response
 * header; TagsFetcher below does nothing more than parse it out and hand it
 * back as `Page::$endCursor`.
 *
 * Usage:
 *   php examples/github-api-example.php [owner] [repo]
 *   GITHUB_TOKEN=ghp_xxx php examples/github-api-example.php torvalds linux
 *
 * Defaults to symfony/symfony if no arguments are given.
 *
 * The script makes at most ~5 HTTP requests total and never throws past its
 * own boundary: any transport failure, non-200 status, rate limit, or
 * malformed response is caught and reported in plain language, then the
 * script exits 0.
 */

require __DIR__ . '/../vendor/autoload.php';

use CursorWalk\Exception\CursorWalkException;
use CursorWalk\Exception\MalformedPageException;
use CursorWalk\Exception\PageBudgetExceededException;
use CursorWalk\Page;
use CursorWalk\PaginatedFetcher;
use CursorWalk\Paginator;
use CursorWalk\Relay\ConnectionFormatter;

/**
 * Raised for anything that stops us from getting a usable HTTP response at
 * all: DNS/connection failures, a non-200 status, and rate limiting.
 *
 * This is deliberately distinct from CursorWalk\Exception\MalformedPageException,
 * which means "we got a response, but could not read it as a page". A
 * fetcher talking to a real upstream generally needs both: one exception
 * type for the upstream being unreachable/unhappy, and the library's own
 * type for the upstream being reachable but lying about its own data shape.
 */
final class GitHubApiUnavailable extends \RuntimeException
{
}

/**
 * Issues one GET request and returns its status, headers, and body.
 *
 * Pure PHP: file_get_contents() over a stream context, with `ignore_errors`
 * so a 4xx/5xx response body is still readable (by default PHP's HTTP
 * stream wrapper discards the body and returns false on non-2xx statuses).
 * $http_response_header is a magic variable PHP populates in the calling
 * scope after an HTTP stream read; there is no OOP handle for it.
 *
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function httpGet(string $url, ?string $token): array
{
    $requestHeaders = [
        // GitHub rejects requests with no User-Agent outright.
        'User-Agent: cursor-walk-example/1.0',
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
    ];

    if ($token !== null) {
        $requestHeaders[] = 'Authorization: Bearer ' . $token;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $requestHeaders),
            'ignore_errors' => true,
            'timeout' => 10,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);

    if ($body === false) {
        $lastError = error_get_last();

        throw new GitHubApiUnavailable(
            'Could not reach ' . $url . ($lastError !== null ? ': ' . $lastError['message'] : ''),
        );
    }

    $status = 0;
    $headers = [];

    /** @var list<string> $http_response_header set by file_get_contents() above */
    foreach ($http_response_header as $index => $line) {
        if ($index === 0 && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
            $status = (int) $matches[1];
            continue;
        }

        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }

    return ['status' => $status, 'headers' => $headers, 'body' => $body];
}

/**
 * Parses an RFC 8288-style `Link` header into rel => URL.
 *
 * Format: `<https://…&page=2>; rel="next", <https://…&page=9>; rel="last"`.
 * Handles multiple comma-separated links and both quoted and unquoted rel
 * values.
 *
 * @return array<string, string>
 */
function parseLinkHeader(string $header): array
{
    $links = [];

    foreach (explode(',', $header) as $part) {
        $part = trim($part);

        if (preg_match('/^<([^>]+)>\s*;\s*rel\s*=\s*"?([^",;\s]+)"?/', $part, $matches) === 1) {
            $links[$matches[2]] = $matches[1];
        }
    }

    return $links;
}

/**
 * Fetches a repository's tags, one upstream page at a time.
 *
 * The teaching point: the cursor this fetcher hands back is not something WE
 * construct — it is GitHub's own `rel="next"` Link URL, passed straight
 * through as an opaque string. `fetchPage(null)` requests the caller-given
 * starting URL; every other call requests exactly the cursor it was given,
 * verbatim, with no re-derivation of query parameters. Wrapping an upstream's
 * native pagination token as an opaque cursor-walk cursor like this is the
 * general pattern for adapting almost any paginated REST API.
 *
 * @implements PaginatedFetcher<array{name: string, commit: string}>
 */
final class TagsFetcher implements PaginatedFetcher
{
    public function __construct(
        private readonly string $initialUrl,
        private readonly ?string $token,
    ) {
    }

    public function fetchPage(?string $cursor): Page
    {
        $url = $cursor ?? $this->initialUrl;
        $response = httpGet($url, $this->token);

        $this->guardAgainstUpstreamFailure($response, $cursor);

        /** @var mixed $data */
        $data = json_decode($response['body'], true);

        if (!is_array($data) || !array_is_list($data)) {
            throw MalformedPageException::invalidEnvelope(
                'expected the response body to be a JSON list of tag objects',
                $cursor,
                substr($response['body'], 0, 500),
            );
        }

        $items = [];
        foreach ($data as $tag) {
            $items[] = $this->mapTag($tag, $cursor);
        }

        $links = parseLinkHeader($response['headers']['link'] ?? '');
        $nextUrl = $links['next'] ?? null;

        return new Page($items, $nextUrl, $nextUrl !== null);
    }

    /**
     * @return array{name: string, commit: string}
     */
    private function mapTag(mixed $tag, ?string $cursor): array
    {
        if (!is_array($tag)) {
            throw MalformedPageException::invalidEnvelope('tag entry is not a JSON object', $cursor, $tag);
        }

        $name = $tag['name'] ?? null;
        $commit = $tag['commit'] ?? null;
        $sha = is_array($commit) ? ($commit['sha'] ?? null) : null;

        if (!is_string($name) || !is_string($sha) || $sha === '') {
            throw MalformedPageException::invalidEnvelope(
                'tag object is missing a string "name" or "commit.sha"',
                $cursor,
                $tag,
            );
        }

        return ['name' => $name, 'commit' => substr($sha, 0, 7)];
    }

    /**
     * @param array{status: int, headers: array<string, string>, body: string} $response
     */
    private function guardAgainstUpstreamFailure(array $response, ?string $cursor): void
    {
        $remaining = $response['headers']['x-ratelimit-remaining'] ?? null;
        $rateLimited = $response['status'] === 403 || $response['status'] === 429 || $remaining === '0';

        if ($rateLimited) {
            $resetHeader = $response['headers']['x-ratelimit-reset'] ?? null;
            $resetMessage = '';

            if ($resetHeader !== null && ctype_digit($resetHeader)) {
                // Formatted in the runtime's configured timezone (date.timezone in php.ini).
                $resetMessage = ' Rate limit resets at ' . date('Y-m-d H:i:s T', (int) $resetHeader) . '.';
            }

            throw new GitHubApiUnavailable(
                'GitHub API rate limit reached (HTTP ' . $response['status'] . ').' . $resetMessage
                . ' Set GITHUB_TOKEN to raise the limit from 60 to 5,000 requests/hour.',
            );
        }

        if ($response['status'] !== 200) {
            throw new GitHubApiUnavailable(sprintf(
                'GitHub API returned HTTP %d for %s',
                $response['status'],
                $cursor ?? $this->initialUrl,
            ));
        }
    }
}

// ---------------------------------------------------------------------------
// Demo
// ---------------------------------------------------------------------------

$owner = $argv[1] ?? (getenv('CURSOR_WALK_EXAMPLE_OWNER') ?: 'symfony');
$repo = $argv[2] ?? (getenv('CURSOR_WALK_EXAMPLE_REPO') ?: 'symfony');

$envToken = getenv('GITHUB_TOKEN');
$token = ($envToken !== false && $envToken !== '') ? $envToken : null;

printf("cursor-walk live example — GitHub tags for %s/%s\n", $owner, $repo);
printf(
    "Auth: %s. (Never printing the token itself.)\n\n",
    $token !== null ? 'authenticated (5,000 req/h)' : 'unauthenticated (60 req/h)',
);

$initialUrl = sprintf('https://api.github.com/repos/%s/%s/tags?per_page=5', rawurlencode($owner), rawurlencode($repo));

try {
    // == a. items() bounded by a small maxPages, streaming as it goes ========
    printf("== a. Paginator(maxPages: 2)->items(): streaming tags ==\n");

    $boundedPaginator = new Paginator(maxPages: 2);
    $fetcherA = new TagsFetcher($initialUrl, $token);

    try {
        foreach ($boundedPaginator->items($fetcherA) as $tag) {
            printf("  %s (%s)\n", $tag['name'], $tag['commit']);
        }
    } catch (PageBudgetExceededException $e) {
        // Expected and informative, not an error: it proves the walk really
        // is bounded at maxPages upstream requests, however many tags exist.
        printf(
            "  ...stopped after the %d-page budget (this is the safety guard working as intended).\n",
            $e->getMaxPages(),
        );
    }

    // == b. slice() + Relay\ConnectionFormatter: the GraphQL resolver flow ===
    printf("\n== b. slice(7) + Relay\\ConnectionFormatter ==\n");

    $fetcherB = new TagsFetcher($initialUrl, $token);
    $slicePaginator = new Paginator(maxPages: 2);
    $slicePage = $slicePaginator->slice($fetcherB, 7);

    $connection = (new ConnectionFormatter())->format(
        $slicePage,
        static fn (array $tag): array => ['name' => $tag['name'], 'commit' => $tag['commit']],
    );

    echo json_encode($connection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

    // == c. pages(): per-page checkpointing with the Link URL as checkpoint ==
    printf("\n== c. pages(): checkpointing on the upstream's own next-page URL ==\n");

    $fetcherC = new TagsFetcher($initialUrl, $token);

    foreach ((new Paginator())->pages($fetcherC) as $page) {
        printf("  fetched %d tag(s); hasNextPage=%s\n", $page->count(), $page->hasNextPage ? 'true' : 'false');

        if ($page->hasNextPage) {
            // This IS the literal GitHub "next page" URL — persist it exactly
            // as-is (a row, a cache key, a queue message) to resume later.
            printf("  checkpoint (verbatim GitHub URL): %s\n", $page->endCursor);
        }

        break; // One page is enough to show the shape; see (a) for streaming all of it.
    }

    printf("\nDone. Total HTTP requests made: a handful, always bounded — see the comments above.\n");
} catch (GitHubApiUnavailable $e) {
    printf("\nThe GitHub API isn't available for this demo right now:\n  %s\n", $e->getMessage());
    printf("This is a best-effort, network-dependent example — exiting cleanly.\n");
    exit(0);
} catch (CursorWalkException $e) {
    printf("\ncursor-walk could not process the GitHub response:\n  %s\n", $e->getMessage());

    if ($e instanceof MalformedPageException) {
        printf("  cursor at failure: %s\n", $e->getCursor() ?? '(first page)');
    }

    printf("This is a best-effort, network-dependent example — exiting cleanly.\n");
    exit(0);
} catch (\Throwable $e) {
    printf("\nUnexpected error talking to the GitHub API: %s\n", $e->getMessage());
    printf("This is a best-effort, network-dependent example — exiting cleanly.\n");
    exit(0);
}

exit(0);
