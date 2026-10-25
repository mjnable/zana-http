<?php

declare(strict_types=1);

namespace Zana\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adds security headers to every response, without replacing headers the
 * handler set: a Content-Security-Policy, HSTS on HTTPS requests, and safe
 * defaults for the others. Configured by http.security_headers:
 *
 *     'security_headers' => [
 *         'csp' => ['script-src' => ["'self'", '{nonce}'], 'img-src' => ["'self'", 'data:']],
 *         'headers' => ['X-Frame-Options' => null],   // null removes a default
 *         'hsts' => 31536000,
 *     ],
 *
 * "{nonce}" becomes a fresh per-request nonce, available to templates as the
 * request attribute "csp_nonce".
 */
final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public const string NONCE_ATTRIBUTE = 'csp_nonce';

    public const array DEFAULT_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'X-Frame-Options' => 'DENY',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
    ];

    public const array DEFAULT_CSP = [
        'default-src' => "'self'",
        'frame-ancestors' => "'none'",
        'base-uri' => "'self'",
        'object-src' => "'none'",
        'form-action' => "'self'",
    ];

    /** @var array<string, string> */
    private array $headers;

    /** @var array<string, list<string>> */
    private array $csp;

    /**
     * @param array<string, string|null> $headers Merged over DEFAULT_HEADERS; null removes one
     * @param array<string, string|list<string>|null> $csp Directives merged over DEFAULT_CSP; null removes one
     * @param int $hsts Strict-Transport-Security max-age for HTTPS requests; 0 disables it
     */
    public function __construct(
        array $headers = [],
        array $csp = [],
        private int $hsts = 31536000,
        private bool $cspReportOnly = false,
        private ?RequestContext $context = null,
    ) {
        $this->headers = array_filter([...self::DEFAULT_HEADERS, ...$headers], static fn (?string $v): bool => $v !== null);

        $directives = [];
        foreach ([...self::DEFAULT_CSP, ...$csp] as $directive => $sources) {
            if ($sources !== null) {
                $directives[$directive] = is_array($sources) ? $sources : (preg_split('/\s+/', trim($sources)) ?: []);
            }
        }
        $this->csp = $directives;
    }

    /**
     * @param array<string, mixed> $config The http.security_headers section
     */
    public static function fromConfig(array $config, ?RequestContext $context = null): self
    {
        /** @var array<string, string|null> $headers */
        $headers = is_array($config['headers'] ?? null) ? $config['headers'] : [];
        /** @var array<string, string|list<string>|null> $csp */
        $csp = is_array($config['csp'] ?? null) ? $config['csp'] : [];

        return new self(
            $headers,
            $csp,
            is_int($config['hsts'] ?? null) ? $config['hsts'] : 31536000,
            ($config['csp_report_only'] ?? false) === true,
            $context,
        );
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $nonce = null;
        if ($this->usesNonce()) {
            $nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
            $request = $request->withAttribute(self::NONCE_ATTRIBUTE, $nonce);
            $this->context?->set($request);
        }

        $response = $handler->handle($request);

        $headers = $this->headers;
        if ($this->csp !== []) {
            $headers[$this->cspReportOnly ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy'] = $this->policy($nonce);
        }
        if ($this->hsts > 0 && $request->getUri()->getScheme() === 'https') {
            $headers['Strict-Transport-Security'] = "max-age={$this->hsts}; includeSubDomains";
        }

        foreach ($headers as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }

    private function usesNonce(): bool
    {
        foreach ($this->csp as $sources) {
            if (in_array('{nonce}', $sources, true)) {
                return true;
            }
        }

        return false;
    }

    private function policy(?string $nonce): string
    {
        $parts = [];
        foreach ($this->csp as $directive => $sources) {
            $sources = array_map(static fn (string $s): string => $s === '{nonce}' ? "'nonce-{$nonce}'" : $s, $sources);
            $parts[] = trim($directive . ' ' . implode(' ', $sources));
        }

        return implode('; ', $parts);
    }
}
