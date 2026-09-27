<?php

declare(strict_types=1);

/**
 * cursor-walk live example: walks a GitHub repository's tags through the public REST API.
 * CI never runs it, because it needs the network. It makes at most 5 requests.
 * Run it with: [GITHUB_TOKEN=...] php examples/github-api-example.php [owner] [repo]
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
 * Separate from MalformedPageException on purpose: this one means no usable response arrived,
 * that one means a response arrived but is not a page.
 */
final class GitHubApiUnavailable extends \RuntimeException
{
}

/**
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
            // Without it PHP's HTTP wrapper returns false on non-2xx and drops the error body.
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
 * @return array<string, string> rel => URL
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
 * The cursor is GitHub's own `rel="next"` Link URL, requested verbatim and never rebuilt.
 * Forwarding an upstream's native token as the opaque cursor adapts almost any paginated REST API.
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

// == a. items() bounded by a small maxPages, streaming as it goes ============
function demoStreaming(string $initialUrl, ?string $token): void
{
    printf("== a. Paginator(maxPages: 2)->items(): streaming tags ==\n");

    $boundedPaginator = new Paginator(maxPages: 2);
    $fetcherA = new TagsFetcher($initialUrl, $token);

    try {
        foreach ($boundedPaginator->items($fetcherA) as $tag) {
            printf("  %s (%s)\n", $tag['name'], $tag['commit']);
        }
    } catch (PageBudgetExceededException $e) {
        printf(
            "  ...stopped after the %d-page budget (this is the safety guard working as intended).\n",
            $e->getMaxPages(),
        );
    }
}

// == b. slice() + Relay\ConnectionFormatter: the GraphQL resolver flow =======
function demoSlice(string $initialUrl, ?string $token): void
{
    printf("\n== b. slice(7) + Relay\\ConnectionFormatter ==\n");

    $fetcherB = new TagsFetcher($initialUrl, $token);
    $slicePaginator = new Paginator(maxPages: 2);
    $slicePage = $slicePaginator->slice($fetcherB, 7);

    $connection = (new ConnectionFormatter())->format(
        $slicePage,
        static fn (array $tag): array => ['name' => $tag['name'], 'commit' => $tag['commit']],
    );

    echo json_encode($connection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
}

// == c. pages(): per-page checkpointing with the Link URL as checkpoint ======
function demoCheckpoint(string $initialUrl, ?string $token): void
{
    printf("\n== c. pages(): checkpointing on the upstream's own next-page URL ==\n");

    $fetcherC = new TagsFetcher($initialUrl, $token);

    foreach ((new Paginator())->pages($fetcherC) as $page) {
        printf("  fetched %d tag(s); hasNextPage=%s\n", $page->count(), $page->hasNextPage ? 'true' : 'false');

        if ($page->hasNextPage) {
            printf("  checkpoint (verbatim GitHub URL): %s\n", $page->endCursor);
        }

        break; // One page shows the shape; without the break this unbounded walk would fetch every tag.
    }
}

/** Exits 0: a live-network example must never fail its caller over an outage or a rate limit. */
function exitCleanly(string $report): never
{
    printf("\n%s\n", $report);
    printf("This is a best-effort, network-dependent example — exiting cleanly.\n");
    exit(0);
}

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
    demoStreaming($initialUrl, $token);
    demoSlice($initialUrl, $token);
    demoCheckpoint($initialUrl, $token);

    printf("\nDone. Total HTTP requests made: a handful, always bounded — see the comments above.\n");
} catch (GitHubApiUnavailable $e) {
    exitCleanly("The GitHub API isn't available for this demo right now:\n  " . $e->getMessage());
} catch (MalformedPageException $e) {
    exitCleanly(
        "cursor-walk could not process the GitHub response:\n  " . $e->getMessage()
        . "\n  cursor at failure: " . ($e->getCursor() ?? '(first page)'),
    );
} catch (CursorWalkException $e) {
    exitCleanly("cursor-walk could not process the GitHub response:\n  " . $e->getMessage());
} catch (\Throwable $e) {
    exitCleanly('Unexpected error talking to the GitHub API: ' . $e->getMessage());
}

exit(0);
