# Zana HTTP

A PSR-7/15/17 HTTP kernel that handles each request in its own container
scope, with middleware, error responses and an emitter. Requires PHP 8.5 or later.

```
composer require mj-zana/http
```

## Usage

```php
// public/index.php
$app = require __DIR__ . '/../bootstrap/app.php';   // Application::configure(...)->withProviders(HttpServiceProvider::class, ...)->build()
Zana\Http\HttpKernel::serve($app);
```

```php
// config/http.php
return [
    'middleware' => [AuthenticationMiddleware::class],   // resolved from the request scope
    'correlation_header' => 'X-Correlation-Id',
];
```

For each request, the kernel:

1. creates a scope (`ScopeMetadata::http()`), so scoped services are fresh
   per request and disposed afterwards;
2. accepts or generates a correlation id, echoes it in the response, and adds
   it to the scope metadata, the request attributes and `mj-zana/logging`'s
   `LogContext`;
3. runs `ErrorMiddleware`, then the configured middleware, then the terminal
   handler registered as `http.handler`. Without one, every request is a 404;
   `mj-zana/routing` provides it.

## Errors

Throw `HttpException` subclasses (`NotFoundHttpException`,
`UnprocessableEntityHttpException($errors)`, ...) or let anything else bubble up:

- **Response format:** clients asking for JSON get an RFC 9457
  `application/problem+json` response; browsers get a minimal HTML page.
- **Server errors** are logged through PSR-3 when a logger is registered.
  Their details are only shown in debug mode.

## Helpers

`ResponseFactory` (`json`, `text`, `html`, `redirect`, `noContent`, `problem`),
the scoped `RequestContext`, `MiddlewarePipeline`, `ResponseEmitter` and
`ServerRequestCreator::fromGlobals()`.

## New in 2.1

- **Error pages.** Register an `ErrorRendererInterface` to draw HTML error
  pages; `mj-zana/view` provides one that uses templates.
- **Security headers.** Add `SecurityHeadersMiddleware` to `http.middleware`:
  a CSP (with `{nonce}`), HSTS on HTTPS, `nosniff`, a referrer policy and
  frame protection, configured by `http.security_headers`.
- **No version banner.** PHP's `X-Powered-By` is removed unless
  `http.expose_php` is true.
- **Safe startup.** In `public/index.php`, `HttpKernel::run($builder)`
  answers 503 with `public/503.html` when the application cannot start,
  and never shows the exception.

## License

MIT — see [LICENSE](LICENSE).
