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

    /**
     * The client's address: found behind trusted proxies by
     * TrustedProxiesMiddleware, otherwise REMOTE_ADDR. Empty outside HTTP.
     */
    public function clientIp(): string
    {
        $request = $this->request();
        $ip = $request->getAttribute(TrustedProxiesMiddleware::CLIENT_IP) ?? $request->getServerParams()['REMOTE_ADDR'] ?? '';

        return is_string($ip) ? $ip : '';
    }

    public function isSecure(): bool
    {
        return $this->request()->getUri()->getScheme() === 'https';
    }

    /**
     * The path the application is served under, e.g. "/shop", or "" at the root.
     */
    public function basePath(): string
    {
        $base = $this->request()->getAttribute(BasePath::ATTRIBUTE, '');

        return is_string($base) ? $base : '';
    }
}
