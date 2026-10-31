<?php

declare(strict_types=1);

namespace Zana\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Zana\Http\Exception\HttpException;
use Zana\Http\Exception\UnprocessableEntityHttpException;

/**
 * Turns any exception into an error response: RFC 9457 problem JSON for
 * clients asking for JSON, an HTML page otherwise. Server errors are logged;
 * their details are only shown in debug mode.
 *
 * Without a PSR-3 logger, server errors go to PHP's error log (error_log(),
 * the "error_log" file on most shared hosting), so a production 500 always
 * leaves its reason somewhere (FE-43): one line with the exception chain's
 * classes, messages, files and lines, never the trace, whose arguments can
 * hold secrets.
 *
 * The HTML page comes from the ErrorRendererInterface when one is given,
 * except for server errors in debug mode, which keep the built-in page with
 * the trace. A failing renderer is logged and replaced by the built-in page.
 */
final readonly class ErrorMiddleware implements MiddlewareInterface
{
    private const GENERIC_DETAIL = 'An unexpected error occurred.';

    public function __construct(
        private ResponseFactory $responses,
        private bool $debug = false,
        private ?LoggerInterface $logger = null,
        private ?ErrorRendererInterface $renderer = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (\Throwable $e) {
            return $this->render($e, $request);
        }
    }

    public function render(\Throwable $exception, ServerRequestInterface $request): ResponseInterface
    {
        $status = $exception instanceof HttpException ? $exception->status : 500;
        $headers = $exception instanceof HttpException ? $exception->headers : [];

        if ($status >= 500) {
            $this->report($request->getMethod() . ' ' . $request->getUri()->getPath() . ' failed', $exception, [
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
            ]);
        }

        $detail = match (true) {
            $this->debug => $exception->getMessage(),
            $exception instanceof HttpException && $exception->getMessage() !== '' => $exception->getMessage(),
            $status >= 500 => self::GENERIC_DETAIL,
            default => null,
        };

        if (self::wantsJson($request)) {
            $extra = ['instance' => $request->getUri()->getPath()];
            if ($exception instanceof UnprocessableEntityHttpException) {
                $extra['errors'] = $exception->errors();
            }
            if ($this->debug) {
                $extra['exception'] = self::describe($exception);
            }

            return $this->responses->problem($status, null, $detail, $extra, $headers);
        }

        if ($this->renderer !== null && !($this->debug && $status >= 500)) {
            try {
                $response = $this->renderer->render($exception, $request, $status, $detail);
                foreach ($headers as $name => $value) {
                    if (!$response->hasHeader($name)) {
                        $response = $response->withHeader($name, $value);
                    }
                }

                return $response;
            } catch (\Throwable $failure) {
                $this->report('The error renderer failed', $failure);
            }
        }

        $page = HtmlErrorPage::render($status, $this->responses->create($status)->getReasonPhrase(), $detail, $this->debug ? $exception : null);

        return $this->responses->html($page, $status, $headers);
    }

    /**
     * Log a server error with the logger, else on one line of PHP's error log.
     *
     * @param array<string, string> $context
     */
    private function report(string $what, \Throwable $exception, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->error('{what}: {message}', ['what' => $what, ...$context, 'message' => $exception->getMessage(), 'exception' => $exception]);

            return;
        }

        $chain = [];
        for ($e = $exception; $e !== null; $e = $e->getPrevious()) {
            $chain[] = sprintf('%s: %s at %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine());
        }
        error_log((string) preg_replace('/\s*\R\s*/', ' ', '[zana] ' . $what . ': ' . implode('; caused by ', $chain)));
    }

    private static function wantsJson(ServerRequestInterface $request): bool
    {
        $accept = strtolower($request->getHeaderLine('Accept'));
        $contentType = strtolower($request->getHeaderLine('Content-Type'));

        return str_contains($accept, 'json') || str_contains($contentType, 'json');
    }

    /**
     * @return array<string, mixed>
     */
    private static function describe(\Throwable $exception): array
    {
        $description = [
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => array_map(
                static fn (array $frame): string => ($frame['file'] ?? '[internal]') . ':' . ($frame['line'] ?? 0) . ' ' . ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'] . '()',
                $exception->getTrace()
            ),
        ];

        if ($exception->getPrevious() !== null) {
            $description['previous'] = self::describe($exception->getPrevious());
        }

        return $description;
    }
}
