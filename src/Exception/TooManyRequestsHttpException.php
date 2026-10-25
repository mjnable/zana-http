<?php

declare(strict_types=1);

namespace Zana\Http\Exception;

/**
 * 429, with Retry-After when the wait is known.
 */
final class TooManyRequestsHttpException extends HttpException
{
    /**
     * @param int|null $retryAfter Seconds before the client may try again
     * @param array<string, string> $headers Added to the response, e.g. RateLimit-* headers
     */
    public function __construct(public readonly ?int $retryAfter = null, string $message = '', array $headers = [], ?\Throwable $previous = null)
    {
        parent::__construct(
            429,
            $message !== '' ? $message : 'Too many requests; please try again later.',
            $retryAfter === null ? $headers : ['Retry-After' => (string) max(0, $retryAfter), ...$headers],
            $previous,
        );
    }
}
