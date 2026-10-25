<?php

declare(strict_types=1);

namespace Zana\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Sends a response to the client through PHP's SAPI.
 *
 * PHP's "X-Powered-By: PHP/<version>" banner is removed unless $exposePhp
 * is true (http.expose_php), as shared hosts often leave expose_php on.
 */
final class ResponseEmitter
{
    private const CHUNK_SIZE = 8192;

    public function __construct(private readonly bool $exposePhp = false)
    {
    }

    /**
     * @throws \RuntimeException If headers were already sent
     */
    public function emit(ResponseInterface $response): void
    {
        if (headers_sent($file, $line)) {
            throw new \RuntimeException("Cannot emit the response: headers were already sent in {$file} on line {$line}.");
        }

        if (!$this->exposePhp) {
            header_remove('X-Powered-By');
        }

        $status = $response->getStatusCode();
        header(sprintf('HTTP/%s %d %s', $response->getProtocolVersion(), $status, $response->getReasonPhrase()), true, $status);

        foreach ($response->getHeaders() as $name => $values) {
            $replace = strtolower((string) $name) !== 'set-cookie';
            foreach ($values as $value) {
                header("{$name}: {$value}", $replace, $status);
                $replace = false;
            }
        }

        if (self::hasNoBody($status)) {
            return;
        }

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (!$body->eof()) {
            echo $body->read(self::CHUNK_SIZE);
            if (connection_status() !== CONNECTION_NORMAL) {
                break;
            }
        }
    }

    public static function hasNoBody(int $status): bool
    {
        return ($status >= 100 && $status < 200) || $status === 204 || $status === 304;
    }
}
