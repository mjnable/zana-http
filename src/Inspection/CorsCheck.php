<?php

declare(strict_types=1);

namespace Zana\Http\Inspection;

use Zana\Configuration\ConfigurationInterface;
use Zana\Core\ApplicationInterface;
use Zana\Core\Inspection\ApplicationCheck;
use Zana\Core\Inspection\Finding;
use Zana\Core\Inspection\Severity;
use Zana\Http\CorsMiddleware;
use Zana\Http\CorsPolicy;

/**
 * http.cors (2.32): what the policy refuses or warns about ("*" with
 * credentials, an entry that is not an origin, a pattern over a whole top
 * level domain, plain HTTP), and a policy that no request goes through,
 * because CorsMiddleware is not in http.middleware.
 */
final readonly class CorsCheck implements ApplicationCheck
{
    public function findings(ApplicationInterface $application): array
    {
        $config = $application->container()->get(ConfigurationInterface::class);
        assert($config instanceof ConfigurationInterface);
        if (!$config->has('http.cors')) {
            return [];
        }
        $policy = CorsPolicy::fromConfig($config->array('http.cors', []));

        $findings = [];
        foreach ($policy->problems() as [$severity, $message]) {
            $findings[] = new Finding('http.cors', $severity === 'error' ? Severity::Error : Severity::Warning, $message, hint: 'List the sites whose pages call this application, each as https://host.');
        }
        if ($policy->enabled() && !in_array(CorsMiddleware::class, $config->array('http.middleware', []), true)) {
            $findings[] = new Finding('http.cors', Severity::Warning, 'http.cors lists origins, but CorsMiddleware is not in http.middleware, so preflight requests reach the router and get no answer the browser accepts.', hint: 'Add Zana\Http\CorsMiddleware to http.middleware, before the middleware that may refuse a request.');
        }

        return $findings;
    }
}
