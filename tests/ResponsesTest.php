<?php

declare(strict_types=1);

namespace Zana\Http\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zana\Container\ServiceCollection;
use Zana\Http\MiddlewarePipeline;
use Zana\Http\RequestContext;
use Zana\Http\ResponseEmitter;
use Zana\Http\ResponseFactory;

final class ResponsesTest extends TestCase
{
    private ResponseFactory $responses;

    protected function setUp(): void
    {
        $this->responses = new ResponseFactory(new Psr17Factory(), new Psr17Factory());
    }

    public function testJson(): void
    {
        $response = $this->responses->json(['bmi' => 22.0, 'unit' => 'kg/m²', 'url' => 'a/b'], 201, ['X-Id' => '7']);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('7', $response->getHeaderLine('X-Id'));
        $this->assertSame('{"bmi":22.0,"unit":"kg/m²","url":"a/b"}', (string) $response->getBody());
    }

    public function testTextHtmlRedirectAndNoContent(): void
    {
        $this->assertSame('text/plain; charset=utf-8', $this->responses->text('x')->getHeaderLine('Content-Type'));
        $this->assertSame('text/html; charset=utf-8', $this->responses->html('<p>x</p>', 202)->getHeaderLine('Content-Type'));

        $redirect = $this->responses->redirect('/login', 303);
        $this->assertSame([303, '/login'], [$redirect->getStatusCode(), $redirect->getHeaderLine('Location')]);

        $this->assertSame(204, $this->responses->noContent()->getStatusCode());
    }

    public function testProblem(): void
    {
        $problem = $this->responses->problem(409, null, 'Already measured today.', ['code' => 'bmi.duplicate']);

        $this->assertSame(
            ['type' => 'about:blank', 'title' => 'Conflict', 'status' => 409, 'detail' => 'Already measured today.', 'code' => 'bmi.duplicate'],
            json_decode((string) $problem->getBody(), true)
        );
    }

    public function testUnencodableJsonFails(): void
    {
        $this->expectException(\JsonException::class);

        $this->responses->json(['bad' => "\xB1\x31"]);
    }

    public function testRequestContext(): void
    {
        $context = new RequestContext();
        $this->assertFalse($context->has());

        $request = new ServerRequest('GET', '/');
        $context->set($request);
        $this->assertSame($request, $context->request());

        $this->expectException(\LogicException::class);
        (new RequestContext())->request();
    }

    public function testPipelinesAreReusableAndResolveClassNamesLazily(): void
    {
        $container = (new ServiceCollection())->transient(FirstMiddleware::class)->build();
        $fallback = new class ($this->responses) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseFactory $responses)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->responses->text($request->getAttribute('first') === true ? 'through' : 'direct');
            }
        };

        $pipeline = new MiddlewarePipeline([FirstMiddleware::class], $fallback, $container);

        $first = (string) $pipeline->handle(new ServerRequest('GET', '/'))->getBody();
        $second = (string) $pipeline->handle(new ServerRequest('GET', '/'))->getBody();
        $this->assertSame(['through', 'through'], [$first, $second], 'the pipeline is reusable');
        $this->assertSame('direct', (string) (new MiddlewarePipeline([], $fallback))->handle(new ServerRequest('GET', '/'))->getBody());
    }

    public function testPipelinesRejectInvalidMiddleware(): void
    {
        $fallback = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \LogicException('unreachable');
            }
        };

        try {
            (new MiddlewarePipeline([FirstMiddleware::class], $fallback))->handle(new ServerRequest('GET', '/'));
            $this->fail('Class names need a container.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('needs a container', $e->getMessage());
        }

        $container = (new ServiceCollection())->singleton('not-middleware', static fn (): \stdClass => new \stdClass())->build();

        $this->expectExceptionMessage('does not implement ' . MiddlewareInterface::class);
        /** @phpstan-ignore argument.type (an invalid middleware id is under test) */
        (new MiddlewarePipeline(['not-middleware'], $fallback, $container))->handle(new ServerRequest('GET', '/'));
    }

    #[RunInSeparateProcess]
    public function testTheEmitterSendsTheBody(): void
    {
        ob_start();
        (new ResponseEmitter())->emit($this->responses->text('emitted body'));
        $output = (string) ob_get_clean();

        $this->assertSame('emitted body', $output);
    }

    public function testResponsesWithoutBodies(): void
    {
        $this->assertTrue(ResponseEmitter::hasNoBody(204));
        $this->assertTrue(ResponseEmitter::hasNoBody(304));
        $this->assertTrue(ResponseEmitter::hasNoBody(101));
        $this->assertFalse(ResponseEmitter::hasNoBody(200));
    }
}
