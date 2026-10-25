<?php

declare(strict_types=1);

namespace Zana\Http\Internal;

use Closure;

/**
 * Reads attributes on the matched route handler (the "_handler" request
 * attribute routing sets): the method's first, then the class's.
 *
 * @internal
 */
final class HandlerAttributes
{
    /**
     * @template T of object
     * @param class-string<T> $attribute
     * @return T|null
     */
    public static function first(mixed $handler, string $attribute): ?object
    {
        $targets = match (true) {
            $handler instanceof Closure => [new \ReflectionFunction($handler)],
            is_string($handler) && class_exists($handler) => self::methodThenClass($handler, '__invoke'),
            is_array($handler) && is_string($handler[0] ?? null) && is_string($handler[1] ?? null) && class_exists($handler[0]) => self::methodThenClass($handler[0], $handler[1]),
            default => [],
        };

        foreach ($targets as $target) {
            foreach ($target->getAttributes($attribute, \ReflectionAttribute::IS_INSTANCEOF) as $found) {
                return $found->newInstance();
            }
        }

        return null;
    }

    /**
     * @param class-string $class
     * @return list<\ReflectionClass<object>|\ReflectionMethod>
     */
    private static function methodThenClass(string $class, string $method): array
    {
        $reflection = new \ReflectionClass($class);

        return $reflection->hasMethod($method) ? [$reflection->getMethod($method), $reflection] : [$reflection];
    }
}
