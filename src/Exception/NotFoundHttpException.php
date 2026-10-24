<?php

declare(strict_types=1);

namespace Zana\Http\Exception;

final class NotFoundHttpException extends HttpException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(string $message = 'Not found.', array $headers = [], ?\Throwable $previous = null)
    {
        parent::__construct(404, $message, $headers, $previous);
    }
}
