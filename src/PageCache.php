<?php

declare(strict_types=1);

namespace Zana\Http;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Full responses of public pages, in a PSR-16 cache, invalidated by tag
 * (FE-33). A handler tags its page with the X-Cache-Tags header (removed
 * before sending):
 *
 *     return $view->withHeader(PageCache::TAGS_HEADER, "post:{$id}, nav");
 *
 * and publishing invalidates the tags: $pageCache->invalidate("post:{$id}").
 * Tags are versioned, so invalidating never scans the cache: entries holding
 * an older version are misses.
 *
 * The key holds the query string (2.20, S25): http.page_cache.query "all" keeps all of it,
 * as before, so anyone can add entries by inventing parameters. A list keeps
 * only the parameters that change a page, sorted, so ?page=2&x=1 and ?page=2
 * share one entry. "none" ignores the query, which is right only when no
 * page reads it.
 */
final readonly class PageCache
{
    public const string TAGS_HEADER = 'X-Cache-Tags';

    private const string PREFIX = 'zana.page.';

    /**
     * @param string|list<string> $query "all", "none", or the parameters that change a page
     */
    public function __construct(
        private CacheInterface $cache,
        private int $ttl = 3600,
        private string|array $query = 'all',
    ) {
        if (is_string($query) && !in_array($query, ['all', 'none'], true)) {
            throw new \InvalidArgumentException("http.page_cache.query must be \"all\", \"none\" or a list of parameters; got \"{$query}\".");
        }
    }

    public function get(ServerRequestInterface $request): ?ResponseInterface
    {
        $entry = $this->cache->get($this->key($request));
        if (!is_array($entry) || !isset($entry['status'], $entry['headers'], $entry['body'], $entry['tags'])) {
            return null;
        }
        /** @var array<string, int> $tags */
        $tags = $entry['tags'];
        foreach ($tags as $tag => $version) {
            if ($this->version($tag) !== $version) {
                return null;
            }
        }

        /** @var array<string, list<string>> $headers */
        $headers = $entry['headers'];

        return new Response((int) $entry['status'], $headers, (string) $entry['body']);
    }

    public function put(ServerRequestInterface $request, ResponseInterface $response): void
    {
        $tags = [];
        foreach (self::tags($response) as $tag) {
            $tags[$tag] = $this->version($tag);
        }

        $this->cache->set($this->key($request), [
            'status' => $response->getStatusCode(),
            'headers' => $response->withoutHeader(self::TAGS_HEADER)->getHeaders(),
            'body' => (string) $response->getBody(),
            'tags' => $tags,
        ], $this->ttl);
    }

    /**
     * Invalidate every cached page carrying one of the tags.
     */
    public function invalidate(string ...$tags): void
    {
        foreach ($tags as $tag) {
            $this->cache->set(self::PREFIX . 'tag.' . hash('xxh128', $tag), $this->version($tag) + 1);
        }
    }

    /**
     * @return list<string>
     */
    public static function tags(ResponseInterface $response): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $response->getHeaderLine(self::TAGS_HEADER))), static fn (string $t): bool => $t !== ''));
    }

    private function version(string $tag): int
    {
        $version = $this->cache->get(self::PREFIX . 'tag.' . hash('xxh128', $tag), 0);

        return is_int($version) ? $version : 0;
    }

    private function key(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $query = match (true) {
            $this->query === 'all' => $uri->getQuery(),
            $this->query === 'none' => '',
            is_array($this->query) => self::kept($uri->getQuery(), $this->query),
            default => $uri->getQuery(),
        };

        return self::PREFIX . hash('xxh128', $uri->getScheme() . '://' . $uri->getAuthority() . $uri->getPath() . '?' . $query);
    }

    /**
     * @param array<mixed> $names
     */
    private static function kept(string $query, array $names): string
    {
        parse_str($query, $values);
        $kept = array_intersect_key($values, array_flip(array_filter($names, is_string(...))));
        ksort($kept);

        return http_build_query($kept);
    }
}
