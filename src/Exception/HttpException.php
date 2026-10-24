<?php

declare(strict_types=1);

namespace Zana\Http\Exception;

/**
 * An error with an HTTP status, rendered by ErrorMiddleware. The message
 * becomes the response detail, so keep it safe to show to clients.
 */
class HttpException extends \RuntimeException
{
    /**
     * @param array<string, string> $headers Added to the error response
     */
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        if ($status < 400 || $status > 599) {
            throw new \InvalidArgumentException("An HTTP error status must be between 400 and 599; got {$status}.");
        }

        parent::__construct($message, 0, $previous);
    }
}
