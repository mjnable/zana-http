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
 * When the request comes from a trusted proxy, X-Forwarded-For gives the
 * client address: the rightmost address that is not itself a trusted proxy.
 * With http.trusted_proxies_header "forwarded", the Forwarded header does
 * instead. Only that one header is read, because proxies pass the other one
 * on as the client sent it (2.19.2). X-Forwarded-Proto and X-Forwarded-Port
 * give the scheme and port, from the entry for the same hop as the client
 * address, not the leftmost one a client can write (2.19.2). Otherwise those
 * headers are ignored, as anyone can send them.
 *
 * The client address is the request attribute "client_ip"
 * (RequestContext::clientIp()); the scheme becomes the request URI's, so
 * HSTS and generated absolute URLs see HTTPS.
 */
final readonly class TrustedProxiesMiddleware implements MiddlewareInterface
{
    public const string CLIENT_IP = 'client_ip';

    public const string X_FORWARDED_FOR = 'x-forwarded-for';

    public const string FORWARDED = 'forwarded';

    /**
     * @param list<string> $proxies
     */
    public function __construct(
        private array $proxies = [],
        private ?RequestContext $context = null,
        private string $header = self::X_FORWARDED_FOR,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $remote = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        $client = $remote;

        if ($remote !== '' && IpRange::contains($this->proxies, $remote)) {
            $chain = self::forwardedFor($request, strtolower($this->header));
            $hops = 0;
            foreach (array_reverse($chain) as $hop) {
                $client = $hop;
                $hops++;
                if (!IpRange::contains($this->proxies, $hop)) {
                    break;
                }
            }

            $proto = strtolower(self::forHop($request->getHeaderLine('X-Forwarded-Proto'), $hops));
            if ($proto === 'https' || $proto === 'http') {
                $request = $request->withUri($request->getUri()->withScheme($proto));
            }
            $port = self::forHop($request->getHeaderLine('X-Forwarded-Port'), $hops);
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
    private static function forwardedFor(ServerRequestInterface $request, string $header): array
    {
        $addresses = [];
        if ($header === self::FORWARDED) {
            foreach (explode(',', $request->getHeaderLine('Forwarded')) as $element) {
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

    /**
     * The value a proxy recorded for the hop the client address came from: proxies that append
     * write one value per hop, from the left; one that overwrites leaves a single value.
     */
    private static function forHop(string $header, int $hops): string
    {
        $values = array_map('trim', explode(',', $header));

        return $values[max(0, count($values) - max(1, $hops))];
    }
}
