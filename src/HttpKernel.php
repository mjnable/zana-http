<?php

declare(strict_types=1);

namespace Zana\Http;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zana\Container\Contracts\ContainerInterface;
use Zana\Container\ScopeMetadata;
use Zana\Core\ApplicationBuilder;
use Zana\Core\ApplicationInterface;
use Zana\Core\Correlation;
use Zana\Core\ScopeKeys;

/**
 * Handles each request in its own container scope: ErrorMiddleware, then the
 * configured middleware, then the terminal handler registered as "http.handler".
 */
final readonly class HttpKernel implements RequestHandlerInterface
{
    public const HANDLER = 'http.handler';

    private const UNAVAILABLE_PAGE = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex">'
        . '<title>Service unavailable</title></head><body><h1>Service unavailable</h1>'
        . '<p>The site is temporarily unavailable. Please try again in a few minutes.</p></body></html>';

    /**
     * @param list<MiddlewareInterface|class-string<MiddlewareInterface>> $middleware
     */
    public function __construct(
        private ContainerInterface $container,
        private array $middleware = [],
        private string $correlationHeader = 'X-Correlation-Id',
    ) {
    }

    /**
     * Build the application, then serve the current PHP request. If the build
     * fails (missing configuration, an invalid container, a fatal deployment
     * check), the exception goes to PHP's error log and the client gets a 503
     * with the fallback page; the exception is never shown, as debug settings
     * cannot be trusted before the build succeeds.
     *
     *     HttpKernel::run(Application::configure(dirname(__DIR__))->withContainerCache(...));
     *
     * @param string|null $fallbackPage An HTML file; default <base>/public/503.html, else a built-in page
     */
    public static function run(ApplicationBuilder $builder, ?string $fallbackPage = null): void
    {
        try {
            $application = $builder->build();
        } catch (\Throwable $e) {
            error_log(sprintf('The application failed to start: %s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
            (new ResponseEmitter())->emit(self::startupFailure($fallbackPage ?? $builder->basePath() . '/public/503.html'));

            return;
        }

        self::serve($application);
    }

    /**
     * The 503 response sent when the application cannot start.
     */
    public static function startupFailure(?string $fallbackPage = null): ResponseInterface
    {
        $page = $fallbackPage !== null && is_file($fallbackPage) ? file_get_contents($fallbackPage) : false;

        return new Response(503, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Retry-After' => '300',
            'Cache-Control' => 'no-store',
        ], $page !== false ? $page : self::UNAVAILABLE_PAGE);
    }

    /**
     * Handle the current PHP request: build it from globals, emit the response, terminate the application.
     */
    public static function serve(ApplicationInterface $application): void
    {
        try {
            $kernel = $application->container()->get(self::class);
            assert($kernel instanceof self);
            (new ResponseEmitter($application->config()->bool('http.expose_php', false)))->emit($kernel->handle(ServerRequestCreator::fromGlobals()));
        } finally {
            $application->terminate();
        }
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $requestId = Correlation::generate();
        $incoming = $request->getHeaderLine($this->correlationHeader);
        $correlationId = Correlation::isValid($incoming) ? $incoming : $requestId;

        $scope = $this->container->createScope(
            ScopeMetadata::http($requestId)
                ->withAttribute(ScopeKeys::REQUEST_ID, $requestId)
                ->withAttribute(ScopeKeys::CORRELATION_ID, $correlationId)
        );
        $restoreLogContext = Correlation::applyToLogContext($scope, $correlationId);

        try {
            $request = $request
                ->withAttribute(ScopeKeys::CORRELATION_ID, $correlationId)
                ->withAttribute(ScopeKeys::REQUEST_ID, $requestId);

            $context = $scope->get(RequestContext::class);
            assert($context instanceof RequestContext);
            $context->set($request);

            $handler = $scope->get(self::HANDLER);
            if (!$handler instanceof RequestHandlerInterface) {
                throw new \LogicException('The service "' . self::HANDLER . '" must implement ' . RequestHandlerInterface::class . '.');
            }

            $errors = $scope->get(ErrorMiddleware::class);
            assert($errors instanceof ErrorMiddleware);

            $response = (new MiddlewarePipeline([$errors, ...$this->middleware], $handler, $scope))->handle($request);

            return $response->withHeader($this->correlationHeader, $correlationId);
        } finally {
            $restoreLogContext();
            $scope->dispose();
        }
    }
}
