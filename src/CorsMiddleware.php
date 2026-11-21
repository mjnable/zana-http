<?php

declare(strict_types=1);

namespace Zana\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Lets the pages of the origins in http.cors read this application's answers
 * (2.32): a browser front end on another site calling an API. Add it to
 * http.middleware, so a preflight is answered before routing.
 *
 * - A request without Origin, or from an origin the policy does not list,
 *   gets no CORS header: the browser keeps the answer from the page. The
 *   request itself still runs, as it would without CORS; CSRF protection,
 *   not CORS, stops a cross-site form.
 * - A preflight (OPTIONS with Access-Control-Request-Method) from a listed
 *   origin, for a listed method and listed headers, is answered 204 here;
 *   otherwise 403, so a browser does not send the request.
 * - The origin is echoed, never "*" with credentials, and every answer says
 *   Vary: Origin, so a cache never gives one site's answer to another.
 * - Errors are rendered inside it, so a front end can read a problem JSON too.
 *
 * With no origin listed, it does nothing, as before 2.32.
 */
final readonly class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CorsPolicy $policy,
        private ResponseFactory $responses,
        private ?ErrorMiddleware $errors = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->policy->enabled()) {
            return $handler->handle($request);
        }
        $origin = $request->getHeaderLine('Origin');
        $preflight = strtoupper($request->getMethod()) === 'OPTIONS' && $request->hasHeader('Access-Control-Request-Method');

        if ($origin === '' || !$this->policy->allows($origin)) {
            return $preflight && $origin !== ''
                ? $this->vary($this->responses->create(403))
                : $this->vary($this->handle($request, $handler));
        }

        if ($preflight) {
            $method = strtoupper(trim($request->getHeaderLine('Access-Control-Request-Method')));
            $asked = array_values(array_filter(array_map(static fn (string $h): string => strtolower(trim($h)), explode(',', $request->getHeaderLine('Access-Control-Request-Headers'))), static fn (string $h): bool => $h !== ''));
            if (!in_array($method, $this->policy->methods, true) || array_diff($asked, $this->policy->headers) !== []) {
                return $this->vary($this->responses->create(403));
            }
            $response = $this->allow($this->responses->create(204), $origin)
                ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->policy->methods))
                ->withHeader('Access-Control-Max-Age', (string) $this->policy->maxAge);
            if ($asked !== []) {
                $response = $response->withHeader('Access-Control-Allow-Headers', implode(', ', $asked));
            }

            return $this->vary($response, 'Access-Control-Request-Method', 'Access-Control-Request-Headers');
        }

        $response = $this->allow($this->handle($request, $handler), $origin);
        if ($this->policy->expose !== []) {
            $response = $response->withHeader('Access-Control-Expose-Headers', implode(', ', $this->policy->expose));
        }

        return $this->vary($response);
    }

    private function handle(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->errors === null ? $handler->handle($request) : $this->errors->process($request, $handler);
    }

    private function allow(ResponseInterface $response, string $origin): ResponseInterface
    {
        // A policy of any origin never has credentials: CorsPolicy refuses "*" with them.
        $response = $response->withHeader('Access-Control-Allow-Origin', $this->policy->anyOrigin() ? '*' : $origin);

        return $this->policy->credentials ? $response->withHeader('Access-Control-Allow-Credentials', 'true') : $response;
    }

    private function vary(ResponseInterface $response, string ...$more): ResponseInterface
    {
        if ($this->policy->anyOrigin() && !$this->policy->credentials && $more === []) {
            return $response;   // "*" is the same answer for every origin
        }
        $have = array_map('strtolower', array_map('trim', explode(',', $response->getHeaderLine('Vary'))));
        foreach (['Origin', ...$more] as $name) {
            if (!in_array(strtolower($name), $have, true)) {
                $response = $response->withAddedHeader('Vary', $name);
            }
        }

        return $response;
    }
}
