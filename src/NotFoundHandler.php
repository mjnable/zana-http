<?php

declare(strict_types=1);

namespace Zana\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zana\Http\Exception\NotFoundHttpException;

/**
 * The default terminal handler: nothing handles the request.
 */
final class NotFoundHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        throw new NotFoundHttpException("Nothing handles {$request->getMethod()} {$request->getUri()->getPath()}.");
    }
}
