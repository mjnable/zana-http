<?php

declare(strict_types=1);

namespace Zana\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Finds the client's real address and scheme behind trusted proxies (a CDN,
 * a load balancer). Configured by http.trusted_proxies (addresses and CIDR
 * ranges); put it first in http.middleware.
 *
 * When the request comes from a trusted proxy, X-Forwarded-For (or Forwarded)
 * gives the client address: the rightmost address that is not itself a
 * trusted proxy. X-Forwarded-Proto and X-Forwarded-Port give the scheme and
 * port. Otherwise those headers are ignored, as anyone can send them.
 *
 * The client address is the request attribute "client_ip"
 * (RequestContext::clientIp()); the scheme becomes the request URI's, so
 * HSTS and generated absolute URLs see HTTPS.
 */
final readonly class TrustedProxiesMiddleware implements MiddlewareInterface
{
    public const string CLIENT_IP = 'client_ip';

    /**
     * @param list<string> $proxies
     */
    public function __construct(
        private array $proxies = [],
        private ?RequestContext $context = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $remote = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        $client = $remote;

        if ($remote !== '' && IpRange::contains($this->proxies, $remote)) {
            $chain = self::forwardedFor($request);
            foreach (array_reverse($chain) as $hop) {
                $client = $hop;
                if (!IpRange::contains($this->proxies, $hop)) {
                    break;
                }
            }

            $proto = strtolower(trim(explode(',', $request->getHeaderLine('X-Forwarded-Proto'))[0]));
            if ($proto === 'https' || $proto === 'http') {
                $request = $request->withUri($request->getUri()->withScheme($proto));
            }
            $port = trim(explode(',', $request->getHeaderLine('X-Forwarded-Port'))[0]);
            if (ctype_digit($port)) {
                $request = $request->withUri($request->getUri()->withPort((int) $port));
            }
        }

        $request = $request->withAttribute(self::CLIENT_IP, $client);
        $this->context?->set($request);

        return $handler->handle($request);
    }

    /**
     * @return list<string> addresses, client first
     */
    private static function forwardedFor(ServerRequestInterface $request): array
    {
        $addresses = [];
        $forwarded = $request->getHeaderLine('Forwarded');
        if ($forwarded !== '') {
            foreach (explode(',', $forwarded) as $element) {
                if (preg_match('/for="?\[?([0-9a-fA-F:.]+)\]?(?::\d+)?"?/i', $element, $match) === 1) {
                    $addresses[] = $match[1];
                }
            }
        } else {
            foreach (explode(',', $request->getHeaderLine('X-Forwarded-For')) as $address) {
                $address = trim($address);
                if ($address !== '') {
                    $addresses[] = $address;
                }
            }
        }

        return array_values(array_filter($addresses, static fn (string $a): bool => filter_var($a, FILTER_VALIDATE_IP) !== false));
    }
}
