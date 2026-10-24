<?php

declare(strict_types=1);

namespace Zana\Http\Tests;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zana\Container\Contracts\Disposable;
use Zana\Container\ScopeMetadata;
use Zana\Http\Exception\MethodNotAllowedHttpException;
use Zana\Http\Exception\UnprocessableEntityHttpException;
use Zana\Http\RequestContext;
use Zana\Http\ResponseFactory;

final class Journal
{
    /** @var list<string> */
    public static array $entries = [];
}

/**
 * Scoped: one per request; records its disposal.
 */
final class RequestCounter implements Disposable
{
    private static int $created = 0;

    public readonly int $number;

    public function __construct(public readonly ScopeMetadata $scope)
    {
        $this->number = ++self::$created;
    }

    public function dispose(): void
    {
        Journal::$entries[] = "disposed:{$this->number}";
    }
}

final class FirstMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        Journal::$entries[] = 'first:in';
        $response = $handler->handle($request->withAttribute('first', true));
        Journal::$entries[] = 'first:out';

        return $response->withHeader('X-First', 'yes');
    }
}

final class SecondMiddleware implements MiddlewareInterface
{
    public function __construct(public readonly RequestCounter $counter)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        Journal::$entries[] = 'second';

        return $handler->handle($request);
    }
}

final class EchoHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ResponseFactory $responses,
        private readonly RequestCounter $counter,
        private readonly RequestContext $context,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        Journal::$entries[] = 'handler';

        return match ($request->getUri()->getPath()) {
            '/boom' => throw new \RuntimeException('database password is hunter2'),
            '/invalid' => throw new UnprocessableEntityHttpException(['height' => 'Must be positive.', 'weight' => ['Required.', 'Must be a number.']]),
            '/method' => throw new MethodNotAllowedHttpException(['GET', 'POST']),
            default => $this->responses->json([
                'path' => $request->getUri()->getPath(),
                'first' => $request->getAttribute('first'),
                'counter' => $this->counter->number,
                'correlation' => $this->counter->scope->attribute('correlation_id'),
                'context_path' => $this->context->request()->getUri()->getPath(),
            ]),
        };
    }
}

final class LogContextSpy
{
    /** @var array<string, scalar|null> */
    private array $values = [];

    public function set(string $key, string|int|float|bool|null $value): void
    {
        $this->values[$key] = $value;
        Journal::$entries[] = "log-context:{$key}={$value}";
    }

    public function remove(string $key): void
    {
        unset($this->values[$key]);
        Journal::$entries[] = "log-context:-{$key}";
    }

    /**
     * @return array<string, scalar|null>
     */
    public function all(): array
    {
        return $this->values;
    }
}
