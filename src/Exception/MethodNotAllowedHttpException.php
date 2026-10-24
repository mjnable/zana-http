<?php

declare(strict_types=1);

namespace Zana\Http\Exception;

/**
 * 405, with the Allow header listing the methods the path accepts.
 */
final class MethodNotAllowedHttpException extends HttpException
{
    /**
     * @param list<string> $allowed
     */
    public function __construct(public readonly array $allowed, string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct(
            405,
            $message !== '' ? $message : 'This method is not allowed; use ' . implode(', ', $allowed) . '.',
            ['Allow' => implode(', ', $allowed)],
            $previous,
        );
    }
}
