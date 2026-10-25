<?php

declare(strict_types=1);

namespace Zana\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Zana\Configuration\ConfigurationInterface;
use Zana\Container\Contracts\ContainerInterface;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Container\Contracts\ServiceResolverInterface;
use Zana\Core\Environment;
use Zana\Core\ServiceProvider;

/**
 * Registers the PSR-17 factories, the HTTP kernel, error handling, the
 * request context and the optional middleware (security headers, trusted
 * proxies, cache headers, page cache). Reads http.middleware (class names),
 * http.correlation_header, http.security_headers, http.trusted_proxies,
 * http.base_path and http.page_cache.*.
 *
 * ErrorMiddleware is scoped, so a registered ErrorRendererInterface may use
 * request services.
 */
final class HttpServiceProvider extends ServiceProvider
{
    public function register(ServiceCollectionInterface $services): void
    {
        $services->singleton(Psr17Factory::class);
        foreach ([RequestFactoryInterface::class, ResponseFactoryInterface::class, ServerRequestFactoryInterface::class, StreamFactoryInterface::class, UploadedFileFactoryInterface::class, UriFactoryInterface::class] as $interface) {
            $services->alias($interface, Psr17Factory::class);
        }

        $services
            ->singleton(ResponseFactory::class)
            ->scoped(ErrorMiddleware::class, static fn (ServiceResolverInterface $r): ErrorMiddleware => new ErrorMiddleware(
                $r->get(ResponseFactory::class),
                $r->get(Environment::class)->debug,
                $r->has(LoggerInterface::class) ? $r->get(LoggerInterface::class) : null,
                $r->has(ErrorRendererInterface::class) ? $r->get(ErrorRendererInterface::class) : null,
            ))
            ->scoped(SecurityHeadersMiddleware::class, static fn (ServiceResolverInterface $r): SecurityHeadersMiddleware => SecurityHeadersMiddleware::fromConfig(
                $r->get(ConfigurationInterface::class)->array('http.security_headers', []),
                $r->get(RequestContext::class),
            ))
            ->scoped(TrustedProxiesMiddleware::class, static function (ServiceResolverInterface $r): TrustedProxiesMiddleware {
                /** @var list<string> $proxies */
                $proxies = $r->get(ConfigurationInterface::class)->array('http.trusted_proxies', []);

                return new TrustedProxiesMiddleware($proxies, $r->get(RequestContext::class));
            })
            ->singleton(CacheHeadersMiddleware::class)
            ->singleton(PageCache::class, static function (ServiceResolverInterface $r): PageCache {
                if (!$r->has(CacheInterface::class)) {
                    throw new \LogicException('PageCache needs a PSR-16 cache: register mj-zana/cache or another ' . CacheInterface::class . '.');
                }
                $cache = $r->get(CacheInterface::class);
                assert($cache instanceof CacheInterface);

                return new PageCache($cache, $r->get(ConfigurationInterface::class)->int('http.page_cache.ttl', 3600));
            })
            ->singleton(PageCacheMiddleware::class, static function (ServiceResolverInterface $r): PageCacheMiddleware {
                $config = $r->get(ConfigurationInterface::class);
                /** @var list<string> $cookies */
                $cookies = $config->array('http.page_cache.private_cookies', ['zana_session']);
                $sharedMaxAge = $config->has('http.page_cache.shared_max_age') ? $config->int('http.page_cache.shared_max_age') : null;
                $cache = $r->get(PageCache::class);
                assert($cache instanceof PageCache);

                return new PageCacheMiddleware($cache, $cookies, $sharedMaxAge);
            })
            ->scoped(RequestContext::class)
            // Scoped: terminal handlers and controllers depend on per-request services.
            ->tryScoped(HttpKernel::HANDLER, NotFoundHandler::class)
            ->singleton(HttpKernel::class, static function (ServiceResolverInterface $root): HttpKernel {
                assert($root instanceof ContainerInterface);
                $config = $root->get(ConfigurationInterface::class);

                /** @var list<class-string<\Psr\Http\Server\MiddlewareInterface>> $middleware */
                $middleware = $config->array('http.middleware', []);

                return new HttpKernel($root, $middleware, $config->string('http.correlation_header', 'X-Correlation-Id'), BasePath::fromConfig($config));
            });
    }

    public function origin(): string
    {
        return 'package:mj-zana/http';
    }
}
