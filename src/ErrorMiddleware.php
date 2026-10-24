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
 * clients asking for JSON, a minimal HTML page otherwise. Server errors are
 * logged; their details are only shown in debug mode.
 */
final readonly class ErrorMiddleware implements MiddlewareInterface
{
    private const GENERIC_DETAIL = 'An unexpected error occurred.';

    public function __construct(
        private ResponseFactory $responses,
        private bool $debug = false,
        private ?LoggerInterface $logger = null,
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
            $this->logger?->error('{method} {path} failed: {message}', [
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
                'message' => $exception->getMessage(),
                'exception' => $exception,
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

        return $this->responses->html($this->page($status, $detail, $exception), $status, $headers);
    }

    private function page(int $status, ?string $detail, \Throwable $exception): string
    {
        $title = $status . ' ' . $this->responses->create($status)->getReasonPhrase();
        $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $body = '<h1>' . $escape($title) . '</h1>';
        if ($detail !== null) {
            $body .= '<p>' . $escape($detail) . '</p>';
        }
        if ($this->debug) {
            $body .= '<pre>' . $escape($exception::class . ' in ' . $exception->getFile() . ':' . $exception->getLine() . "\n\n" . $exception->getTraceAsString()) . '</pre>';
        }

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $escape($title) . '</title></head><body>' . $body . '</body></html>';
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
