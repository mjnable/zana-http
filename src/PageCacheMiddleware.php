<?php

declare(strict_types=1);

namespace Zana\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves anonymous GET and HEAD requests from the PageCache, and stores the
 * pages that may be shared (FE-33). A page is stored only when:
 *
 * - the request has no Authorization header and none of the private cookies
 *   (http.page_cache.private_cookies, default the session cookie);
 * - the response is 200, sets no cookie, has no Vary other than
 *   Accept-Encoding, and is not marked private or no-store.
 *
 * With http.page_cache.shared_max_age, stored pages also get
 * Cache-Control: public, s-maxage=… for a CDN, unless they have Cache-Control.
 * Responses carry X-Page-Cache: HIT or MISS.
 */
final readonly class PageCacheMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $privateCookies
     */
    public function __construct(
        private PageCache $cache,
        private array $privateCookies = ['zana_session'],
        private ?int $sharedMaxAge = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->cacheableRequest($request)) {
            return self::withoutTags($handler->handle($request));
        }

        $cached = $this->cache->get($request);
        if ($cached !== null) {
            return $cached->withHeader('X-Page-Cache', 'HIT');
        }

        $response = $handler->handle($request);
        if (!self::shareable($response)) {
            return self::withoutTags($response);
        }

        if ($this->sharedMaxAge !== null && !$response->hasHeader('Cache-Control')) {
            $response = $response->withHeader('Cache-Control', "public, max-age=0, s-maxage={$this->sharedMaxAge}");
        }
        $this->cache->put($request, $response);

        return self::withoutTags($response)->withHeader('X-Page-Cache', 'MISS');
    }

    private function cacheableRequest(ServerRequestInterface $request): bool
    {
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true) || $request->hasHeader('Authorization')) {
            return false;
        }
        $cookies = $request->getCookieParams();
        if ($cookies === []) {
            parse_str(strtr($request->getHeaderLine('Cookie'), ['; ' => '&', ';' => '&']), $cookies);
        }
        foreach ($this->privateCookies as $name) {
            if (array_key_exists($name, $cookies)) {
                return false;
            }
        }

        return true;
    }

    private static function shareable(ResponseInterface $response): bool
    {
        if ($response->getStatusCode() !== 200 || $response->hasHeader('Set-Cookie')) {
            return false;
        }
        $control = strtolower($response->getHeaderLine('Cache-Control'));
        if (str_contains($control, 'private') || str_contains($control, 'no-store')) {
            return false;
        }
        foreach (explode(',', strtolower($response->getHeaderLine('Vary'))) as $vary) {
            $vary = trim($vary);
            if ($vary !== '' && $vary !== 'accept-encoding') {
                return false;
            }
        }

        return true;
    }

    private static function withoutTags(ResponseInterface $response): ResponseInterface
    {
        return $response->withoutHeader(PageCache::TAGS_HEADER);
    }
}
