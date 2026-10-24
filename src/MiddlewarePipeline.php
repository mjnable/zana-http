<?php

declare(strict_types=1);

namespace Zana\Http;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs middleware in order, then the fallback handler. Middleware given as
 * class names are resolved from the resolver when first reached.
 */
final readonly class MiddlewarePipeline implements RequestHandlerInterface
{
    /**
     * @param list<MiddlewareInterface|class-string<MiddlewareInterface>> $middleware
     */
    public function __construct(
        private array $middleware,
        private RequestHandlerInterface $fallback,
        private ?ContainerInterface $resolver = null,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->at(0)->handle($request);
    }

    private function at(int $index): RequestHandlerInterface
    {
        if (!isset($this->middleware[$index])) {
            return $this->fallback;
        }

        return new class ($this, $index) implements RequestHandlerInterface {
            public function __construct(private readonly MiddlewarePipeline $pipeline, private readonly int $index)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->pipeline->process($this->index, $request);
            }
        };
    }

    /**
     * @internal Called by the per-position handlers.
     */
    public function process(int $index, ServerRequestInterface $request): ResponseInterface
    {
        return $this->resolve($this->middleware[$index])->process($request, $this->at($index + 1));
    }

    /**
     * @param MiddlewareInterface|class-string<MiddlewareInterface> $middleware
     */
    private function resolve(MiddlewareInterface|string $middleware): MiddlewareInterface
    {
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        if ($this->resolver === null) {
            throw new \LogicException("Middleware {$middleware} needs a container to be resolved.");
        }

        $instance = $this->resolver->get($middleware);
        if (!$instance instanceof MiddlewareInterface) {
            throw new \LogicException("{$middleware} does not implement " . MiddlewareInterface::class . '.');
        }

        return $instance;
    }
}
