<?php

declare(strict_types=1);

namespace Zana\Http\Exception;

/**
 * 422, with field errors rendered as "errors" in problem responses.
 */
final class UnprocessableEntityHttpException extends HttpException
{
    /**
     * @param array<string, array<array-key, string>|string> $errors path => message(s)
     */
    public function __construct(public readonly array $errors, string $message = 'The request contains invalid data.', ?\Throwable $previous = null)
    {
        parent::__construct(422, $message, [], $previous);
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return array_map(static fn (array|string $messages): array => is_string($messages) ? [$messages] : array_values($messages), $this->errors);
    }
}
