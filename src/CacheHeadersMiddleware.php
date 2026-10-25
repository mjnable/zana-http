<?php

declare(strict_types=1);

namespace Zana\Http;

use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zana\Http\Attribute\Cache;
use Zana\Http\Internal\HandlerAttributes;

/**
 * HTTP caching for GET and HEAD (FE-20):
 *
 * - the handler's #[Cache] attribute becomes Cache-Control, unless the
 *   response already has one (route middleware only: it reads the handler);
 * - successful responses get a weak ETag from their body;
 * - a matching If-None-Match gets 304 Not Modified, without a body.
 *
 * Responses that set a cookie are never made cacheable.
 */
final readonly class CacheHeadersMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true) || $response->getStatusCode() !== 200) {
            return $response;
        }

        $cache = HandlerAttributes::first($request->getAttribute('_handler'), Cache::class);
        if ($cache !== null && !$response->hasHeader('Cache-Control') && !$response->hasHeader('Set-Cookie')) {
            $response = $response->withHeader('Cache-Control', $cache->header());
        }

        if (!$response->hasHeader('ETag')) {
            $response = $response->withHeader('ETag', 'W/"' . hash('xxh128', (string) $response->getBody()) . '"');
        }

        if (self::matches($request->getHeaderLine('If-None-Match'), $response->getHeaderLine('ETag'))) {
            return $response->withStatus(304)->withBody(Stream::create(''))->withoutHeader('Content-Length');
        }

        return $response;
    }

    private static function matches(string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === '') {
            return false;
        }
        $normalize = static fn (string $tag): string => preg_replace('/^W\//', '', trim($tag)) ?? $tag;
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            if (trim($candidate) === '*' || $normalize($candidate) === $normalize($etag)) {
                return true;
            }
        }

        return false;
    }
}
