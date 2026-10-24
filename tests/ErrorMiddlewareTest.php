<?php

declare(strict_types=1);

namespace Zana\Http\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;
use Zana\Http\ErrorMiddleware;
use Zana\Http\Exception\BadRequestHttpException;
use Zana\Http\Exception\ForbiddenHttpException;
use Zana\Http\Exception\HttpException;
use Zana\Http\Exception\MethodNotAllowedHttpException;
use Zana\Http\Exception\NotFoundHttpException;
use Zana\Http\Exception\UnauthorizedHttpException;
use Zana\Http\Exception\UnprocessableEntityHttpException;
use Zana\Http\ResponseFactory;

final class ErrorMiddlewareTest extends TestCase
{
    public function testProblemJsonForJsonClients(): void
    {
        $response = $this->render(new NotFoundHttpException('Patient 42 does not exist.'), ['Accept' => 'application/json']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('application/problem+json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame([
            'type' => 'about:blank',
            'title' => 'Not Found',
            'status' => 404,
            'detail' => 'Patient 42 does not exist.',
            'instance' => '/patients/42',
        ], $this->json($response));
    }

    public function testJsonBodiesGetJsonErrors(): void
    {
        $response = $this->render(new BadRequestHttpException(), ['Content-Type' => 'application/json']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Bad request.', $this->json($response)['detail']);
    }

    public function testValidationErrorsAndAllowHeaders(): void
    {
        $invalid = $this->render(new UnprocessableEntityHttpException(['height' => 'Must be positive.', 'weight' => ['Required.']]), ['Accept' => 'application/json']);
        $this->assertSame(['height' => ['Must be positive.'], 'weight' => ['Required.']], $this->json($invalid)['errors']);

        $method = $this->render(new MethodNotAllowedHttpException(['GET', 'POST']));
        $this->assertSame(405, $method->getStatusCode());
        $this->assertSame('GET, POST', $method->getHeaderLine('Allow'));
    }

    public function testServerErrorsHideDetailsOutsideDebugAndAreLogged(): void
    {
        $logger = new class () extends AbstractLogger {
            /** @var list<array{mixed, string, array<mixed>}> */
            public array $logs = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->logs[] = [$level, (string) $message, $context];
            }
        };

        $response = $this->render(new \RuntimeException('secret detail'), ['Accept' => 'application/json'], logger: $logger);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('An unexpected error occurred.', $this->json($response)['detail']);
        $this->assertArrayNotHasKey('exception', $this->json($response));
        $this->assertSame('error', $logger->logs[0][0]);
        $this->assertSame('secret detail', $logger->logs[0][2]['message']);
        $this->assertInstanceOf(\RuntimeException::class, $logger->logs[0][2]['exception']);
    }

    public function testClientErrorsAreNotLogged(): void
    {
        $logger = new class () extends AbstractLogger {
            public int $count = 0;

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->count++;
            }
        };

        $this->render(new ForbiddenHttpException(), logger: $logger);

        $this->assertSame(0, $logger->count);
    }

    public function testDebugShowsTheException(): void
    {
        $response = $this->render(new \RuntimeException('outer', 0, new \LogicException('inner')), ['Accept' => 'application/json'], debug: true);

        $body = $this->json($response);
        $this->assertSame('outer', $body['detail']);
        $this->assertIsArray($body['exception']);
        $this->assertSame(\RuntimeException::class, $body['exception']['class']);
        $this->assertSame('inner', $body['exception']['previous']['message']);
        $this->assertIsList($body['exception']['trace']);
    }

    public function testHtmlForBrowsers(): void
    {
        $production = $this->render(new UnauthorizedHttpException('<b>Sign in</b>', ['WWW-Authenticate' => 'Bearer']), ['Accept' => 'text/html']);

        $this->assertSame(401, $production->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $production->getHeaderLine('Content-Type'));
        $this->assertSame('Bearer', $production->getHeaderLine('WWW-Authenticate'));
        $this->assertStringContainsString('<h1>401 Unauthorized</h1><p>&lt;b&gt;Sign in&lt;/b&gt;</p>', (string) $production->getBody());

        $debug = $this->render(new \RuntimeException('trace me'), debug: true);
        $this->assertStringContainsString('<pre>RuntimeException in ', (string) $debug->getBody());
    }

    public function testEmptyMessagesOnClientErrorsHaveNoDetail(): void
    {
        $response = $this->render(new HttpException(418), ['Accept' => 'application/json']);

        $this->assertSame(418, $response->getStatusCode());
        $this->assertArrayNotHasKey('detail', $this->json($response));
    }

    public function testInvalidStatusesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HttpException(302);
    }

    public function testSuccessfulResponsesPassThrough(): void
    {
        $responses = new ResponseFactory(new Psr17Factory(), new Psr17Factory());
        $handler = new class ($responses) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseFactory $responses)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->responses->text('fine');
            }
        };

        $response = (new ErrorMiddleware($responses))->process(new ServerRequest('GET', '/'), $handler);

        $this->assertSame('fine', (string) $response->getBody());
    }

    /**
     * @param array<string, string> $headers
     */
    private function render(\Throwable $exception, array $headers = [], bool $debug = false, ?\Psr\Log\LoggerInterface $logger = null): ResponseInterface
    {
        $middleware = new ErrorMiddleware(new ResponseFactory(new Psr17Factory(), new Psr17Factory()), $debug, $logger);

        return $middleware->render($exception, new ServerRequest('GET', '/patients/42', $headers));
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
