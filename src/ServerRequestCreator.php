<?php

declare(strict_types=1);

namespace Zana\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Builds the PSR-7 request for the current PHP request.
 */
final class ServerRequestCreator
{
    public static function fromGlobals(): ServerRequestInterface
    {
        $factory = new Psr17Factory();

        return (new \Nyholm\Psr7Server\ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();
    }
}
