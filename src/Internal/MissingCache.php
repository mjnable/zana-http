<?php

declare(strict_types=1);

namespace Zana\Http\Internal;

use Psr\SimpleCache\CacheInterface;

/**
 * Stands in for the PSR-16 cache when the application has none, so the
 * PageCache can be created (check and doctor create every singleton) and
 * fails only when a page is actually cached or invalidated.
 *
 * @internal
 */
final class MissingCache implements CacheInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        throw self::missing();
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        throw self::missing();
    }

    public function delete(string $key): bool
    {
        throw self::missing();
    }

    public function clear(): bool
    {
        throw self::missing();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        throw self::missing();
    }

    /**
     * @param iterable<mixed> $values
     */
    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        throw self::missing();
    }

    public function deleteMultiple(iterable $keys): bool
    {
        throw self::missing();
    }

    public function has(string $key): bool
    {
        throw self::missing();
    }

    private static function missing(): \LogicException
    {
        return new \LogicException('PageCache needs a PSR-16 cache: register mj-zana/cache or another ' . CacheInterface::class . '.');
    }
}
