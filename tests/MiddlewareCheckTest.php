<?php

declare(strict_types=1);

namespace Zana\Http\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Container\Contracts\ServiceResolverInterface;
use Zana\Core\Application;
use Zana\Core\Inspection\ApplicationCheck;
use Zana\Core\Inspection\Finding;
use Zana\Http\HttpServiceProvider;
use Zana\Http\Inspection\MiddlewareCheck;
use Zana\Http\SecurityHeadersMiddleware;

/**
 * ADR-025: check finds a global middleware that every request would fail
 * to create, although the container builds.
 */
final class MiddlewareCheckTest extends TestCase
{
    public function testGlobalMiddlewareIsRegisteredAndCanBeCreated(): void
    {
        $app = Application::configure(sys_get_temp_dir() . '/zana-http-middleware-check')
            ->withEnvironment('testing')
            ->withProviders(HttpServiceProvider::class)
            ->withConfiguration(['http.middleware' => [SecurityHeadersMiddleware::class, NeedsAUserSource::class, UnregisteredMiddleware::class, 'App\Missing\Middleware']])
            // Like AuthenticationMiddleware: a factory declaring a service nobody registered (lenient, ADR-020).
            ->withServices(static fn (ServiceCollectionInterface $s) => $s
                ->scoped(NeedsAUserSource::class, static fn (ServiceResolverInterface $r): NeedsAUserSource => new NeedsAUserSource($r->get(UserSource::class)))
                ->uses(UserSource::class))
            ->build();

        $checks = array_values(array_filter($app->container()->tagged(ApplicationCheck::TAG), static fn (object $c): bool => $c instanceof MiddlewareCheck));
        $this->assertCount(1, $checks, 'http contributes the check');
        $findings = $checks[0]->findings($app);

        $this->assertSame(['http.middleware', 'http.middleware', 'http.middleware'], array_map(static fn (Finding $f): string => $f->id, $findings));
        $this->assertStringContainsString('NeedsAUserSource cannot be created, so every request fails', $findings[0]->message);
        $this->assertStringContainsString(UserSource::class, $findings[0]->message, 'the cause is named');
        $this->assertStringContainsString('UnregisteredMiddleware (http.middleware) is not registered', $findings[1]->message);
        $this->assertStringContainsString('App\Missing\Middleware (http.middleware) does not exist', $findings[2]->message);
    }
}

interface UserSource
{
}

final readonly class NeedsAUserSource implements MiddlewareInterface
{
    public function __construct(public UserSource $users)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
}

final class UnregisteredMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
}
