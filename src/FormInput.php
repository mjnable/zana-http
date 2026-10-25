<?php

declare(strict_types=1);

namespace Zana\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The fields of a submitted form: strings trimmed, and blank fields treated
 * as absent, so validation sees null instead of "".
 *
 *     $input = FormInput::from($request);
 *     $command = new SendContactMessage($input->string('name'), $input->optional('company'));
 */
final readonly class FormInput
{
    /**
     * @param array<string, string|array<array-key, mixed>> $fields
     */
    private function __construct(private array $fields)
    {
    }

    /**
     * Reads the parsed body, or decodes an urlencoded body when the server did
     * not parse it (e.g. for PUT).
     */
    public static function from(ServerRequestInterface $request): self
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $body = [];
            if (str_contains(strtolower($request->getHeaderLine('Content-Type')), 'application/x-www-form-urlencoded')) {
                parse_str((string) $request->getBody(), $body);
            }
        }

        return new self(self::clean($body));
    }

    /**
     * @param array<array-key, mixed> $fields
     */
    public static function fromArray(array $fields): self
    {
        return new self(self::clean($fields));
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->fields);
    }

    /**
     * The field as a string; $default when it is absent or not a string.
     */
    public function string(string $name, string $default = ''): string
    {
        $value = $this->fields[$name] ?? null;

        return is_string($value) ? $value : $default;
    }

    public function optional(string $name): ?string
    {
        $value = $this->fields[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * A repeated field (name="tags[]") as a list of strings.
     *
     * @return list<string>
     */
    public function list(string $name): array
    {
        $value = $this->fields[$name] ?? [];

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    public function bool(string $name): bool
    {
        return in_array(strtolower($this->string($name)), ['1', 'on', 'yes', 'true'], true);
    }

    /**
     * The given fields only, without the absent ones: e.g. to re-display a form.
     *
     * @return array<string, string|array<array-key, mixed>>
     */
    public function only(string ...$names): array
    {
        return array_intersect_key($this->fields, array_flip($names));
    }

    /**
     * @return array<string, string|array<array-key, mixed>>
     */
    public function all(): array
    {
        return $this->fields;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<string, string|array<array-key, mixed>>
     */
    private static function clean(array $values): array
    {
        $clean = [];
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $value = self::clean($value);
            } elseif (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
            } else {
                continue;
            }
            $clean[(string) $key] = $value;
        }

        return $clean;
    }
}
