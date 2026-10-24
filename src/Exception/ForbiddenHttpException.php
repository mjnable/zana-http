<?php

declare(strict_types=1);

namespace Zana\Http\Exception;

final class ForbiddenHttpException extends HttpException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(string $message = 'You are not allowed to do this.', array $headers = [], ?\Throwable $previous = null)
    {
        parent::__construct(403, $message, $headers, $previous);
    }
}
