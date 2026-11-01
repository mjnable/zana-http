<?php

declare(strict_types=1);

namespace Zana\Http\Inspection;

use Zana\Configuration\ConfigurationInterface;
use Zana\Core\ApplicationInterface;
use Zana\Core\Inspection\ApplicationCheck;
use Zana\Core\Inspection\Finding;
use Zana\Core\Inspection\Severity;

/**
 * Every global middleware (http.middleware) exists, is registered, and can be
 * created in a fresh scope, as each request does (ADR-025). Creating it finds
 * what the container's build cannot: a factory that needs a service nobody
 * registered, such as the user provider of AuthenticationMiddleware.
 */
final readonly class MiddlewareCheck implements ApplicationCheck
{
    public function findings(ApplicationInterface $application): array
    {
        $container = $application->container();
        $middleware = $container->get(ConfigurationInterface::class)->array('http.middleware', []);
        $origins = [];
        foreach ($container->graph()->nodes() as $node) {
            $origins[$node->service] ??= $node->origin;
        }

        $findings = [];
        foreach ($middleware as $class) {
            if (!is_string($class)) {
                continue;
            }
            if (!class_exists($class)) {
                $findings[] = new Finding('http.middleware', Severity::Error, "The global middleware {$class} (http.middleware) does not exist.", hint: 'Check the class name in config/http.php.');
                continue;
            }
            if (!$container->has($class)) {
                $findings[] = new Finding(
                    'http.middleware',
                    Severity::Error,
                    "The global middleware {$class} (http.middleware) is not registered in the container.",
                    hint: "Register it, e.g. \$services->scoped(\\{$class}::class); or install the package that provides it.",
                );
                continue;
            }

            $scope = $container->createScope();
            try {
                $scope->get($class);
            } catch (\Throwable $e) {
                $origin = $origins[$class] ?? null;
                $findings[] = new Finding(
                    'http.middleware',
                    Severity::Error,
                    "The global middleware {$class} cannot be created, so every request fails: " . strtok($e->getMessage(), "\n"),
                    hint: 'Register what it needs' . ($origin === null ? '.' : " (it is registered by {$origin})."),
                );
            } finally {
                $scope->dispose();
            }
        }

        return $findings;
    }
}
