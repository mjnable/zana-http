<?php

declare(strict_types=1);

namespace Zana\Http;

/**
 * Which other sites' pages may read this application's answers (2.32), from
 * http.cors:
 *
 *     'cors' => [
 *         'origins' => ['https://app.example.com', 'https://*.example.com'],
 *         'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
 *         'headers' => ['Content-Type', 'Authorization', 'Idempotency-Key'],
 *         'expose' => [],
 *         'credentials' => false,   // cookies and HTTP authentication from those pages
 *         'max_age' => 600,         // seconds a browser may keep a preflight answer
 *     ],
 *
 * - An origin is a scheme, a host and an optional port, as a browser sends it:
 *   no path, no trailing slash. "https://*.example.com" means any subdomain of
 *   example.com, never example.com itself nor a host that merely ends so.
 * - "*" is any origin, and only without credentials: browsers refuse
 *   credentials for "*", and reflecting every origin with them would let any
 *   site read a signed-in user's answers.
 * - "null" (sandboxed pages, local files, some redirects) is never allowed.
 * - An entry that cannot be read is left out, and problems() says why: the
 *   policy fails closed.
 */
final readonly class CorsPolicy
{
    public const array DEFAULT_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public const array DEFAULT_HEADERS = ['Content-Type', 'Authorization', 'Accept', 'Accept-Language', 'Idempotency-Key', 'X-CSRF-Token'];

    private const string HOST = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*';

    /**
     * @param list<string> $origins Exact origins, normalized
     * @param list<array{scheme: string, suffix: string, port: ?int}> $patterns Subdomain patterns
     * @param list<string> $methods
     * @param list<string> $headers Lowercase
     * @param list<string> $expose
     * @param list<array{0: 'error'|'warning', 1: string}> $problems
     */
    private function __construct(
        private array $origins,
        private array $patterns,
        private bool $any,
        public array $methods,
        public array $headers,
        public array $expose,
        public bool $credentials,
        public int $maxAge,
        private array $problems,
    ) {
    }

    /**
     * @param array<mixed> $config http.cors
     */
    public static function fromConfig(array $config): self
    {
        $problems = [];
        $credentials = ($config['credentials'] ?? false) === true;
        $origins = [];
        $patterns = [];
        $any = false;
        foreach (is_array($config['origins'] ?? null) ? $config['origins'] : [] as $entry) {
            if (!is_string($entry)) {
                $problems[] = ['error', 'http.cors.origins lists ' . get_debug_type($entry) . '; each origin is text, such as "https://app.example.com".'];
                continue;
            }
            if ($entry === '*') {
                if ($credentials) {
                    $problems[] = ['error', 'http.cors.origins is "*" with credentials: browsers refuse it, and reflecting every origin would let any site read a signed-in user\'s answers. No origin is allowed until it lists the sites.'];
                } else {
                    $any = true;
                }
                continue;
            }
            $parsed = self::parse($entry);
            if ($parsed === null) {
                $problems[] = ['error', "\"{$entry}\" in http.cors.origins is not an origin: write a scheme, a host and an optional port, such as https://app.example.com, with no path and no trailing slash."];
                continue;
            }
            if ($parsed['pattern'] && substr_count($parsed['host'], '.') < 1) {
                $problems[] = ['error', "\"{$entry}\" in http.cors.origins would match every site under .{$parsed['host']}: a pattern names a domain, such as https://*.example.com."];
                continue;
            }
            if ($parsed['scheme'] === 'http' && !in_array($parsed['host'], ['localhost', '127.0.0.1'], true) && !str_ends_with($parsed['host'], '.localhost')) {
                $problems[] = ['warning', "\"{$entry}\" in http.cors.origins is plain HTTP: a page anyone on the network can change may then read this application's answers."];
            }
            if ($parsed['pattern']) {
                $patterns[] = ['scheme' => $parsed['scheme'], 'suffix' => '.' . $parsed['host'], 'port' => $parsed['port']];
            } else {
                $origins[] = self::origin($parsed['scheme'], $parsed['host'], $parsed['port']);
            }
        }

        $methods = [];
        foreach (is_array($config['methods'] ?? null) ? $config['methods'] : self::DEFAULT_METHODS as $method) {
            if (is_string($method) && preg_match('/^[A-Za-z]+$/', $method) === 1) {
                $methods[] = strtoupper($method);
            } else {
                $problems[] = ['error', 'http.cors.methods lists ' . (is_string($method) ? "\"{$method}\"" : get_debug_type($method)) . ', which is not a method.'];
            }
        }
        $headers = [];
        foreach (is_array($config['headers'] ?? null) ? $config['headers'] : self::DEFAULT_HEADERS as $header) {
            if (is_string($header) && preg_match('/^[A-Za-z0-9-]+$/', $header) === 1) {
                $headers[] = strtolower($header);
            } else {
                $problems[] = ['error', 'http.cors.headers lists ' . (is_string($header) ? "\"{$header}\"" : get_debug_type($header)) . ', which is not a header name.'];
            }
        }
        $expose = [];
        foreach (is_array($config['expose'] ?? null) ? $config['expose'] : [] as $header) {
            if (is_string($header) && preg_match('/^[A-Za-z0-9-]+$/', $header) === 1) {
                $expose[] = $header;
            } else {
                $problems[] = ['error', 'http.cors.expose lists ' . (is_string($header) ? "\"{$header}\"" : get_debug_type($header)) . ', which is not a header name.'];
            }
        }
        $maxAge = is_int($config['max_age'] ?? null) ? max(0, min(86_400, $config['max_age'])) : 600;

        return new self(array_values(array_unique($origins)), $patterns, $any, array_values(array_unique($methods)), array_values(array_unique($headers)), $expose, $credentials, $maxAge, $problems);
    }

    /**
     * Whether a policy is set at all: no origin listed means no CORS headers, as before 2.32.
     */
    public function enabled(): bool
    {
        return $this->any || $this->origins !== [] || $this->patterns !== [];
    }

    public function anyOrigin(): bool
    {
        return $this->any;
    }

    /**
     * Whether a page from this origin may read the answers. The header is compared as the browser sends it,
     * scheme and host in lowercase.
     */
    public function allows(string $origin): bool
    {
        $parsed = self::parse($origin);
        if ($parsed === null || $parsed['pattern']) {
            return false;   // also "null", and an origin with a path or a wildcard
        }
        if ($this->any) {
            return true;
        }
        if (in_array(self::origin($parsed['scheme'], $parsed['host'], $parsed['port']), $this->origins, true)) {
            return true;
        }
        foreach ($this->patterns as $pattern) {
            // The suffix starts with a dot, so the domain itself, and a host that only ends like it, never match.
            if ($parsed['scheme'] === $pattern['scheme'] && $parsed['port'] === $pattern['port'] && str_ends_with($parsed['host'], $pattern['suffix'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{0: 'error'|'warning', 1: string}>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * @return array{scheme: string, host: string, port: ?int, pattern: bool}|null
     */
    private static function parse(string $origin): ?array
    {
        if (preg_match('#^(https?)://(\*\.)?(' . self::HOST . ')(?::([0-9]{1,5}))?$#i', $origin, $m) !== 1) {
            return null;
        }
        $scheme = strtolower($m[1]);
        $port = isset($m[4]) ? (int) $m[4] : null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            return null;
        }
        if ($port === ($scheme === 'https' ? 443 : 80)) {
            $port = null;   // as a browser sends it: the default port is left out
        }

        return ['scheme' => $scheme, 'host' => strtolower($m[3]), 'port' => $port, 'pattern' => $m[2] !== ''];
    }

    private static function origin(string $scheme, string $host, ?int $port): string
    {
        return "{$scheme}://{$host}" . ($port === null ? '' : ":{$port}");
    }
}
