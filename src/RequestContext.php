<?php

declare(strict_types=1);

namespace Zana\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The request being handled, for services that need it (registered scoped).
 */
final class RequestContext
{
    private ?ServerRequestInterface $request = null;

    public function set(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function has(): bool
    {
        return $this->request !== null;
    }

    /**
     * @throws \LogicException Outside a request
     */
    public function request(): ServerRequestInterface
    {
        return $this->request ?? throw new \LogicException('No request is being handled in this scope.');
    }
}
