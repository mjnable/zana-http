<?php

declare(strict_types=1);

namespace Zana\Http\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Core\Application;
use Zana\Http\ErrorMiddleware;
use Zana\Http\ErrorRendererInterface;
use Zana\Http\Exception\HttpException;
use Zana\Http\Exception\MethodNotAllowedHttpException;
use Zana\Http\Exception\NotFoundHttpException;
use Zana\Http\HttpKernel;
use Zana\Http\HttpServiceProvider;
use Zana\Http\RequestContext;
use Zana\Http\ResponseFactory;
use Zana\Http\SecurityHeadersMiddleware;

final class WebsiteFeaturesTest extends TestCase
{
    // ── error renderer (FE-04) ─────────────────────────────────────────────

    public function testTheRendererDrawsHtmlErrorPages(): void
    {
        $response = $this->errors(renderer: new BrandedPages())->render(new NotFoundHttpException('No such page.'), self::request());

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('<main>404: No such page.</main>', (string) $response->getBody());
    }

    public function testJsonClientsStillGetProblemDocuments(): void
    {
        $response = $this->errors(renderer: new BrandedPages())->render(new NotFoundHttpException(), self::request(['Accept' => 'application/json']));

        $this->assertSame('application/problem+json; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    public function testExceptionHeadersAreAddedToRenderedPages(): void
    {
        $response = $this->errors(renderer: new BrandedPages())->render(new MethodNotAllowedHttpException(['GET']), self::request());

        $this->assertSame('GET', $response->getHeaderLine('Allow'));
    }

    public function testServerErrorsInDebugKeepTheBuiltInPageWithTheTrace(): void
    {
        $response = $this->errors(debug: true, renderer: new BrandedPages())->render(new \RuntimeException('Disk full'), self::request());

        $this->assertStringContainsString('RuntimeException in', (string) $response->getBody());

        $notFound = $this->errors(debug: true, renderer: new BrandedPages())->render(new NotFoundHttpException('x'), self::request());
        $this->assertSame('<main>404: x</main>', (string) $notFound->getBody(), 'client errors use the renderer in debug too');
    }

    public function testServerErrorsOutsideDebugUseTheRendererWithAGenericDetail(): void
    {
        $response = $this->errors(renderer: new BrandedPages())->render(new \RuntimeException('Disk full'), self::request());

        $this->assertSame('<main>500: An unexpected error occurred.</main>', (string) $response->getBody());
    }

    public function testAFailingRendererIsLoggedAndReplacedByTheBuiltInPage(): void
    {
        $logger = new MemoryLogger();
        $broken = new class () implements ErrorRendererInterface {
            public function render(\Throwable $exception, ServerRequestInterface $request, int $status, ?string $detail): ResponseInterface
            {
                throw new \LogicException('Template missing');
            }
        };

        $response = $this->errors(logger: $logger, renderer: $broken)->render(new NotFoundHttpException('Gone'), self::request());

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('<h1>404 Not Found</h1>', (string) $response->getBody());
        $this->assertSame(['error: The error renderer failed: Template missing'], $logger->lines);
    }

    public function testTheProviderPassesARegisteredRendererToErrorMiddleware(): void
    {
        $kernel = Application::configure(sys_get_temp_dir() . '/zana-http-renderer')
            ->withEnvironment('production')
            ->withProviders(HttpServiceProvider::class)
            ->withServices(static fn (ServiceCollectionInterface $s) => $s->scoped(ErrorRendererInterface::class, BrandedPages::class))
            ->build()
            ->container()
            ->get(HttpKernel::class);
        assert($kernel instanceof HttpKernel);

        $this->assertSame('<main>404: Nothing handles GET /missing.</main>', (string) $kernel->handle(self::request())->getBody());
    }

    // ── security headers (FE-06) ───────────────────────────────────────────

    public function testSafeDefaultsAreAdded(): void
    {
        $response = (new SecurityHeadersMiddleware())->process(self::request(), new Respond(new Response()));

        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        $this->assertSame("default-src 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'; form-action 'self'", $response->getHeaderLine('Content-Security-Policy'));
        $this->assertFalse($response->hasHeader('Strict-Transport-Security'), 'not on plain HTTP');
    }

    public function testHandlerHeadersAreNeverReplaced(): void
    {
        $response = (new SecurityHeadersMiddleware())->process(self::request(), new Respond(new Response(200, ['X-Frame-Options' => 'SAMEORIGIN'])));

        $this->assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
    }

    public function testHeadersAndDirectivesAreConfigurable(): void
    {
        $middleware = SecurityHeadersMiddleware::fromConfig([
            'headers' => ['X-Frame-Options' => null, 'X-Extra' => 'yes'],
            'csp' => ['img-src' => ["'self'", 'data:'], 'object-src' => null],
            'csp_report_only' => true,
        ]);

        $response = $middleware->process(self::request(), new Respond(new Response()));

        $this->assertFalse($response->hasHeader('X-Frame-Options'));
        $this->assertSame('yes', $response->getHeaderLine('X-Extra'));
        $this->assertFalse($response->hasHeader('Content-Security-Policy'));
        $this->assertSame("default-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; img-src 'self' data:", $response->getHeaderLine('Content-Security-Policy-Report-Only'));
    }

    public function testNoncesAreFreshPerRequestAndReachTheHandler(): void
    {
        $context = new RequestContext();
        $middleware = new SecurityHeadersMiddleware(csp: ['script-src' => ["'self'", '{nonce}']], context: $context);

        $handler = new class () implements RequestHandlerInterface {
            /** @var list<string> */
            public array $seen = [];

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $nonce = $request->getAttribute(SecurityHeadersMiddleware::NONCE_ATTRIBUTE);
                assert(is_string($nonce));
                $this->seen[] = $nonce;

                return new Response();
            }
        };

        $first = $middleware->process(self::request(), $handler);
        $second = $middleware->process(self::request(), $handler);
        $seen = $handler->seen;

        $this->assertNotSame($seen[0], $seen[1]);
        $this->assertStringContainsString("script-src 'self' 'nonce-{$seen[0]}'", $first->getHeaderLine('Content-Security-Policy'));
        $this->assertStringContainsString("'nonce-{$seen[1]}'", $second->getHeaderLine('Content-Security-Policy'));
        $this->assertSame($seen[1], $context->request()->getAttribute(SecurityHeadersMiddleware::NONCE_ATTRIBUTE), 'templates read it from the request context');
    }

    public function testHstsOnlyOnHttps(): void
    {
        $secure = (new SecurityHeadersMiddleware())->process(new ServerRequest('GET', 'https://example.com/'), new Respond(new Response()));
        $disabled = (new SecurityHeadersMiddleware(hsts: 0))->process(new ServerRequest('GET', 'https://example.com/'), new Respond(new Response()));

        $this->assertSame('max-age=31536000; includeSubDomains', $secure->getHeaderLine('Strict-Transport-Security'));
        $this->assertFalse($disabled->hasHeader('Strict-Transport-Security'));
    }

    // ── safe startup failure (FE-25) ───────────────────────────────────────

    public function testStartupFailuresAnswer503WithoutDetails(): void
    {
        $response = HttpKernel::startupFailure();

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('300', $response->getHeaderLine('Retry-After'));
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('Service unavailable', (string) $response->getBody());
    }

    public function testStartupFailuresUseTheFallbackPage(): void
    {
        $page = sys_get_temp_dir() . '/zana-http-503-' . bin2hex(random_bytes(4)) . '.html';
        file_put_contents($page, '<p>Back soon</p>');

        try {
            $this->assertSame('<p>Back soon</p>', (string) HttpKernel::startupFailure($page)->getBody());
            $this->assertStringContainsString('Service unavailable', (string) HttpKernel::startupFailure($page . '.missing')->getBody());
        } finally {
            unlink($page);
        }
    }

    #[RunInSeparateProcess]
    public function testRunAnswers503WhenTheBuildFails(): void
    {
        $log = sys_get_temp_dir() . '/zana-http-run-' . bin2hex(random_bytes(4)) . '.log';
        ini_set('error_log', $log);
        $base = sys_get_temp_dir() . '/zana-http-run';
        @mkdir("{$base}/config", 0777, true);
        file_put_contents("{$base}/config/modules.php", '<?php return "not a list";');

        try {
            ob_start();
            HttpKernel::run(Application::configure($base));
            $body = (string) ob_get_clean();

            $this->assertStringContainsString('Service unavailable', $body);
            $this->assertStringNotContainsString('not a list', $body);
            $this->assertStringContainsString('The application failed to start: Zana\Core\ApplicationException', (string) file_get_contents($log));
        } finally {
            @unlink($log);
            @unlink("{$base}/config/modules.php");
        }
    }

    private function errors(bool $debug = false, ?MemoryLogger $logger = null, ?ErrorRendererInterface $renderer = null): ErrorMiddleware
    {
        return new ErrorMiddleware(new ResponseFactory(new Psr17Factory(), new Psr17Factory()), $debug, $logger, $renderer);
    }

    /**
     * @param array<string, string> $headers
     */
    private static function request(array $headers = []): ServerRequest
    {
        return new ServerRequest('GET', '/missing', ['Accept' => 'text/html', ...$headers]);
    }
}

final readonly class BrandedPages implements ErrorRendererInterface
{
    public function render(\Throwable $exception, ServerRequestInterface $request, int $status, ?string $detail): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'text/html'], "<main>{$status}: {$detail}</main>");
    }
}

final readonly class Respond implements RequestHandlerInterface
{
    public function __construct(private ResponseInterface $response)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->response;
    }
}

final class MemoryLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $replacements['{' . $key . '}'] = $value;
            }
        }
        $this->lines[] = "{$level}: " . strtr((string) $message, $replacements);
    }
}
