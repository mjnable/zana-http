<?php

declare(strict_types=1);

namespace Zana\Http\Exception;

final class BadRequestHttpException extends HttpException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(string $message = 'Bad request.', array $headers = [], ?\Throwable $previous = null)
    {
        parent::__construct(400, $message, $headers, $previous);
    }
}
