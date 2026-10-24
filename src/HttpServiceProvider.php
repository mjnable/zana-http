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
use Zana\Configuration\ConfigurationInterface;
use Zana\Container\Contracts\ContainerInterface;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Container\Contracts\ServiceResolverInterface;
use Zana\Core\Environment;
use Zana\Core\ServiceProvider;

/**
 * Registers the PSR-17 factories, the HTTP kernel, error handling and the
 * request context. Reads http.middleware (class names) and http.correlation_header.
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
            ->singleton(ErrorMiddleware::class, static fn (ServiceResolverInterface $r): ErrorMiddleware => new ErrorMiddleware(
                $r->get(ResponseFactory::class),
                $r->get(Environment::class)->debug,
                $r->has(LoggerInterface::class) ? $r->get(LoggerInterface::class) : null,
            ))
            ->scoped(RequestContext::class)
            // Scoped: terminal handlers and controllers depend on per-request services.
            ->tryScoped(HttpKernel::HANDLER, NotFoundHandler::class)
            ->singleton(HttpKernel::class, static function (ServiceResolverInterface $root): HttpKernel {
                assert($root instanceof ContainerInterface);
                $config = $root->get(ConfigurationInterface::class);

                /** @var list<class-string<\Psr\Http\Server\MiddlewareInterface>> $middleware */
                $middleware = $config->array('http.middleware', []);

                return new HttpKernel($root, $middleware, $config->string('http.correlation_header', 'X-Correlation-Id'));
            });
    }

    public function origin(): string
    {
        return 'package:mj-zana/http';
    }
}
