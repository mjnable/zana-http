<?php

declare(strict_types=1);

namespace Zana\Http\Exception;

final class UnauthorizedHttpException extends HttpException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(string $message = 'Authentication is required.', array $headers = [], ?\Throwable $previous = null)
    {
        parent::__construct(401, $message, $headers, $previous);
    }
}
