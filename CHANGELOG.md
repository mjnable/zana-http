# Changelog

All Zana packages share one version number and are released together. A release that did
not change this package is not listed.

## 2.3.0 — 2026-10-26

### Changed

- Tests and tooling (`tests/`, `phpunit.xml.dist`, `phpstan.neon.dist`) are no longer in Composer
  archives, so they stay out of production `vendor/` folders (FE-17).

## 2.2.0 — 2026-10-26

### Added

- `TrustedProxiesMiddleware` and `IpRange`: the client address and scheme behind trusted proxies
  (`http.trusted_proxies`); `RequestContext::clientIp()` and `isSecure()` (FE-12).
- `BasePath`: running from a sub-folder; `HttpKernel` strips it before routing, and
  `RequestContext::basePath()` gives it (`http.base_path`, FE-11).
- `TooManyRequestsHttpException` (429, `Retry-After`) and `ServiceUnavailableHttpException` (503)
  (FE-13).
- `FormInput`: trimmed form fields, blank fields absent (FE-15).
- `#[Cache]` and `CacheHeadersMiddleware`: `Cache-Control`, weak ETags and 304 responses (FE-20).
- `PageCache` and `PageCacheMiddleware`: full public pages in the PSR-16 cache, invalidated by tag
  (`http.page_cache.*`, FE-33).

### Changed

- `HttpKernel` takes the base path as a fourth constructor argument (optional).
- Requires `psr/simple-cache`.

## 2.1.0 — 2026-10-26

### Added

- `ErrorRendererInterface` for HTML error pages; `HtmlErrorPage` is the built-in page (FE-04).
- `SecurityHeadersMiddleware`: CSP with per-request nonces, HSTS on HTTPS, and safe defaults,
  configured by `http.security_headers` (FE-06).
- `HttpKernel::run()`: builds and serves; a failed build answers 503 with a static page and logs the
  reason (FE-25).

### Changed

- `ResponseEmitter` removes PHP's `X-Powered-By` banner unless `http.expose_php` is true (FE-06).
- `ErrorMiddleware` is registered scoped, so error renderers can use request services.

## 2.0.0 — 2026-10-26

The final 2.0 release. No changes since 2.0.0-rc.1.

## 2.0.0-beta.1 — 2026-10-25

### Changed

- `HttpKernel` uses `Correlation`.

## 2.0.0-alpha.3 — 2026-10-24

First release of the 2.0 line. A PSR-7/15/17 HTTP kernel that handles each request in its own container scope, with middleware, error responses and an emitter. Requires PHP 8.5 or later.

Documentation: [the http guide](https://github.com/mjnable/zana-docs/blob/main/guides/http.md).
