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
 */
final readonly class PageCache
{
    public const string TAGS_HEADER = 'X-Cache-Tags';

    private const string PREFIX = 'zana.page.';

    public function __construct(
        private CacheInterface $cache,
        private int $ttl = 3600,
    ) {
    }

    public function get(ServerRequestInterface $request): ?ResponseInterface
    {
        $entry = $this->cache->get(self::key($request));
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

        $this->cache->set(self::key($request), [
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

    private static function key(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();

        return self::PREFIX . hash('xxh128', $uri->getScheme() . '://' . $uri->getAuthority() . $uri->getPath() . '?' . $uri->getQuery());
    }
}
