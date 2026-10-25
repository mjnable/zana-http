<?php

declare(strict_types=1);

namespace Zana\Http\Exception;

/**
 * 503, with Retry-After when the wait is known, e.g. during maintenance.
 */
final class ServiceUnavailableHttpException extends HttpException
{
    /**
     * @param int|null $retryAfter Seconds before the client may try again
     */
    public function __construct(public readonly ?int $retryAfter = null, string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct(
            503,
            $message !== '' ? $message : 'The service is temporarily unavailable.',
            $retryAfter === null ? [] : ['Retry-After' => (string) max(0, $retryAfter)],
            $previous,
        );
    }
}
