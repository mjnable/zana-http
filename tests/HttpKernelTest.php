<?php

declare(strict_types=1);

namespace Zana\Http\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Core\Application;
use Zana\Http\HttpKernel;
use Zana\Http\HttpServiceProvider;

final class HttpKernelTest extends TestCase
{
    protected function setUp(): void
    {
        Journal::$entries = [];
    }

    public function testRequestsRunThroughErrorHandlingMiddlewareAndHandler(): void
    {
        $response = $this->kernel()->handle(new ServerRequest('GET', '/patients'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('yes', $response->getHeaderLine('X-First'));
        $this->assertSame(['first:in', 'second', 'handler', 'first:out'], array_slice(Journal::$entries, 0, 4));
        $this->assertSame(['path' => '/patients', 'first' => true, 'context_path' => '/patients'], array_intersect_key($this->json($response), ['path' => 1, 'first' => 1, 'context_path' => 1]));
    }

    public function testEachRequestHasItsOwnScopeDisposedAfterwards(): void
    {
        $kernel = $this->kernel();

        $first = $this->json($kernel->handle(new ServerRequest('GET', '/a')));
        $second = $this->json($kernel->handle(new ServerRequest('GET', '/b')));

        $this->assertNotSame($first['counter'], $second['counter']);
        $this->assertContains("disposed:{$first['counter']}", Journal::$entries);
        $this->assertContains("disposed:{$second['counter']}", Journal::$entries);
    }

    public function testValidCorrelationIdsAreKeptAndEchoed(): void
    {
        $response = $this->kernel()->handle(new ServerRequest('GET', '/', ['X-Correlation-Id' => 'abc-123.x_y']));

        $this->assertSame('abc-123.x_y', $response->getHeaderLine('X-Correlation-Id'));
        $this->assertSame('abc-123.x_y', $this->json($response)['correlation']);
    }

    public function testInvalidCorrelationIdsAreReplaced(): void
    {
        $response = $this->kernel()->handle(new ServerRequest('GET', '/', ['X-Correlation-Id' => 'bad id; drop table']));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $response->getHeaderLine('X-Correlation-Id'));
        $this->assertSame($response->getHeaderLine('X-Correlation-Id'), $this->json($response)['correlation']);
    }

    public function testTheCorrelationHeaderIsConfigurable(): void
    {
        $response = $this->kernel(['correlation_header' => 'X-Request-Trace'])->handle(new ServerRequest('GET', '/', ['X-Request-Trace' => 'trace-1']));

        $this->assertSame('trace-1', $response->getHeaderLine('X-Request-Trace'));
    }

    public function testTheCorrelationIdReachesTheLogContextForTheRequestOnly(): void
    {
        $kernel = $this->kernel([], static fn (ServiceCollectionInterface $s) => $s->singleton('Zana\\Logging\\LogContext', LogContextSpy::class));

        $kernel->handle(new ServerRequest('GET', '/', ['X-Correlation-Id' => 'c-1']));

        $this->assertContains('log-context:correlation_id=c-1', Journal::$entries);
        $this->assertContains('log-context:-correlation_id', Journal::$entries);
    }

    public function testWithoutAHandlerEverythingIsNotFound(): void
    {
        $kernel = Application::configure(sys_get_temp_dir() . '/zana-http-app')->withProviders(HttpServiceProvider::class)->build()->container()->get(HttpKernel::class);

        $response = $kernel->handle(new ServerRequest('GET', '/anything', ['Accept' => 'application/json']));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Nothing handles GET /anything.', $this->json($response)['detail']);
    }

    public function testExceptionsBecomeErrorResponses(): void
    {
        $response = $this->kernel()->handle(new ServerRequest('GET', '/boom', ['Accept' => 'application/json']));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('An unexpected error occurred.', $this->json($response)['detail'], 'production hides the message');
        $this->assertNotEmpty($response->getHeaderLine('X-Correlation-Id'), 'error responses carry the correlation id');
    }

    /**
     * @param array<string, mixed> $http
     * @param (\Closure(ServiceCollectionInterface): mixed)|null $services
     */
    private function kernel(array $http = [], ?\Closure $services = null): HttpKernel
    {
        $base = sys_get_temp_dir() . '/zana-http-' . bin2hex(random_bytes(4));
        mkdir("{$base}/config", 0777, true);
        file_put_contents("{$base}/config/http.php", '<?php return ' . var_export([
            'middleware' => [FirstMiddleware::class, SecondMiddleware::class],
            ...$http,
        ], true) . ';');

        $builder = Application::configure($base)
            ->withEnvironment('production')
            ->withProviders(HttpServiceProvider::class)
            ->withServices(static fn (ServiceCollectionInterface $s) => $s
                ->scoped(RequestCounter::class)
                ->transient(FirstMiddleware::class)
                ->transient(SecondMiddleware::class)
                ->replace(HttpKernel::HANDLER, EchoHandler::class));

        if ($services !== null) {
            $builder->withServices($services);
        }

        $kernel = $builder->build()->container()->get(HttpKernel::class);

        unlink("{$base}/config/http.php");
        rmdir("{$base}/config");
        rmdir($base);

        return $kernel;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
