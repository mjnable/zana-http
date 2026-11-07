<?php

declare(strict_types=1);

namespace Zana\Http\Inspection;

use Zana\Configuration\ConfigurationInterface;
use Zana\Core\ApplicationInterface;
use Zana\Core\Inspection\ApplicationCheck;
use Zana\Core\Inspection\Finding;
use Zana\Core\Inspection\Severity;
use Zana\Http\IpRange;
use Zana\Http\TrustedProxiesMiddleware;

/**
 * http.trusted_proxies holds only addresses and ranges that can be read, and
 * http.trusted_proxies_header names a header that is read (2.19.2). A range
 * that cannot be read matches nothing, so the proxy's own address would count
 * as every visitor's.
 */
final readonly class TrustedProxiesCheck implements ApplicationCheck
{
    public function findings(ApplicationInterface $application): array
    {
        $config = $application->container()->get(ConfigurationInterface::class);
        assert($config instanceof ConfigurationInterface);
        $findings = [];

        foreach (IpRange::invalid(array_values($config->array('http.trusted_proxies', []))) as $range) {
            $findings[] = new Finding('http.trusted_proxies', Severity::Error, "\"{$range}\" in http.trusted_proxies is not an address or a range, so it matches nothing.", hint: 'Write an address, such as 203.0.113.7, or a range, such as 10.0.0.0/8 or 2001:db8::/32.');
        }
        $header = strtolower($config->string('http.trusted_proxies_header', TrustedProxiesMiddleware::X_FORWARDED_FOR));
        if (!in_array($header, [TrustedProxiesMiddleware::X_FORWARDED_FOR, TrustedProxiesMiddleware::FORWARDED], true)) {
            $findings[] = new Finding('http.trusted_proxies', Severity::Error, "http.trusted_proxies_header is \"{$header}\"; it must be \"x-forwarded-for\" or \"forwarded\".", hint: 'Use the header your proxy sets. Most set X-Forwarded-For.');
        }

        return $findings;
    }
}