<?php

declare(strict_types=1);

namespace Zana\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Builds common responses.
 */
final readonly class ResponseFactory
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public function __construct(
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
    ) {
    }

    /**
     * @param array<string, string> $headers
     */
    public function json(mixed $data, int $status = 200, array $headers = []): ResponseInterface
    {
        return $this->body(json_encode($data, self::JSON_FLAGS), $status, ['Content-Type' => 'application/json; charset=utf-8', ...$headers]);
    }

    /**
     * @param array<string, string> $headers
     */
    public function text(string $text, int $status = 200, array $headers = []): ResponseInterface
    {
        return $this->body($text, $status, ['Content-Type' => 'text/plain; charset=utf-8', ...$headers]);
    }

    /**
     * @param array<string, string> $headers
     */
    public function html(string $html, int $status = 200, array $headers = []): ResponseInterface
    {
        return $this->body($html, $status, ['Content-Type' => 'text/html; charset=utf-8', ...$headers]);
    }

    public function redirect(string $url, int $status = 302): ResponseInterface
    {
        return $this->responses->createResponse($status)->withHeader('Location', $url);
    }

    public function noContent(): ResponseInterface
    {
        return $this->responses->createResponse(204);
    }

    /**
     * An RFC 9457 problem details response.
     *
     * @param array<string, mixed> $extra Additional members, e.g. "errors"
     * @param array<string, string> $headers
     */
    public function problem(int $status, ?string $title = null, ?string $detail = null, array $extra = [], array $headers = []): ResponseInterface
    {
        $response = $this->responses->createResponse($status);
        $problem = ['type' => 'about:blank', 'title' => $title ?? $response->getReasonPhrase(), 'status' => $status];
        if ($detail !== null) {
            $problem['detail'] = $detail;
        }

        return $this->body(
            json_encode([...$problem, ...$extra], self::JSON_FLAGS),
            $status,
            ['Content-Type' => 'application/problem+json; charset=utf-8', ...$headers],
        );
    }

    public function create(int $status = 200): ResponseInterface
    {
        return $this->responses->createResponse($status);
    }

    /**
     * @param array<string, string> $headers
     */
    private function body(string $contents, int $status, array $headers): ResponseInterface
    {
        $response = $this->responses->createResponse($status)->withBody($this->streams->createStream($contents));
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
