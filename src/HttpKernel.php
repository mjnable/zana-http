<?php

declare(strict_types=1);

namespace Zana\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zana\Container\Contracts\ContainerInterface;
use Zana\Container\ScopeMetadata;
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
     * Handle the current PHP request: build it from globals, emit the response, terminate the application.
     */
    public static function serve(ApplicationInterface $application): void
    {
        try {
            $kernel = $application->container()->get(self::class);
            assert($kernel instanceof self);
            (new ResponseEmitter())->emit($kernel->handle(ServerRequestCreator::fromGlobals()));
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
