<?php

declare(strict_types=1);

namespace Zana\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders the error page for clients that do not ask for JSON. Register an
 * implementation as this interface to replace the built-in page; the view
 * package provides one that uses templates.
 *
 * ErrorMiddleware still decides the status, logs server errors, adds the
 * headers of an HttpException, and keeps the built-in page with the trace for
 * server errors in debug mode.
 */
interface ErrorRendererInterface
{
    /**
     * @param string|null $detail A message safe to show: the HttpException message, or a generic text for server errors
     */
    public function render(\Throwable $exception, ServerRequestInterface $request, int $status, ?string $detail): ResponseInterface;
}
