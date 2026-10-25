<?php

declare(strict_types=1);

namespace Zana\Http;

use Zana\Configuration\ConfigurationInterface;

/**
 * The path the application is served under when it runs from a sub-folder,
 * e.g. http://localhost/shop. HttpKernel removes it from the request path
 * before routing (the request attribute "base_path" keeps it), and the URL
 * generator adds it back.
 */
final class BasePath
{
    public const string ATTRIBUTE = 'base_path';

    /**
     * http.base_path when it is set, otherwise the folder of the front
     * controller (SCRIPT_NAME), or "" on the command line.
     */
    public static function fromConfig(ConfigurationInterface $config): string
    {
        if ($config->has('http.base_path')) {
            return self::normalize($config->string('http.base_path'));
        }
        if (PHP_SAPI === 'cli') {
            return '';
        }
        $script = $_SERVER['SCRIPT_NAME'] ?? '';

        return is_string($script) ? self::normalize(dirname(str_replace('\\', '/', $script))) : '';
    }

    public static function normalize(string $path): string
    {
        $path = '/' . trim(str_replace('\\', '/', $path), '/');

        return $path === '/' || $path === '/.' ? '' : $path;
    }

    /**
     * The path below the base path, or null when $path is not under it.
     */
    public static function strip(string $base, string $path): ?string
    {
        if ($base === '') {
            return $path;
        }
        if ($path === $base) {
            return '/';
        }

        return str_starts_with($path, $base . '/') ? substr($path, strlen($base)) : null;
    }
}
