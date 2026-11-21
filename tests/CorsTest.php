<?php

declare(strict_types=1);

namespace Zana\Http\Tests;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Core\Application;
use Zana\Http\CorsMiddleware;
use Zana\Http\CorsPolicy;
use Zana\Http\Exception\NotFoundHttpException;
use Zana\Http\HttpKernel;
use Zana\Http\HttpServiceProvider;
use Zana\Http\Inspection\CorsCheck;

/**
 * 2.32: CorsMiddleware and http.cors. Each answer is read as a browser reads it: Access-Control-Allow-Origin must name
 * the page's origin, or "*" without credentials, for the page to read the answer.
 */
final class CorsTest extends TestCase
{
    public function testOnlyListedOriginsMayRead(): void
    {
        $policy = CorsPolicy::fromConfig(['origins' => ['https://app.example.com', 'https://*.example.org', 'http://localhost:5173']]);

        foreach (['https://app.example.com', 'HTTPS://APP.EXAMPLE.COM', 'https://app.example.com:443', 'https://a.example.org', 'https://deep.a.example.org', 'http://localhost:5173'] as $origin) {
            $this->assertTrue($policy->allows($origin), "allowed: {$origin}");
        }
        foreach ([
            'http://app.example.com',            // another scheme
            'https://app.example.com:8443',      // another port
            'https://app.example.com.evil.com',  // a suffix
            'https://evilapp.example.com',
            'https://example.org',               // the pattern's domain itself
            'http://a.example.org',              // the pattern, with another scheme
            'https://a.example.org:8443',        // and another port
            'https://evil-example.org',          // ends like it, no dot
            'https://a.example.org.evil.com',
            'https://*.example.org',             // a wildcard sent as an origin
            'null',
            '',
            'https://app.example.com/',          // with a path
            'https://app.example.com/x',
            'https://user@app.example.com',
            "https://app.example.com\r\nX: y",
            'https://app.example.com:99999',
            'javascript://app.example.com',
            'http://localhost:3000',
        ] as $origin) {
            $this->assertFalse($policy->allows($origin), 'refused: ' . json_encode($origin));
        }
    }

    public function testWhatTheConfigurationGetsWrongIsRefusedAndSaid(): void
    {
        // Each problem as "severity: message", compared by the start of its message.
        $starts = function (array $expected, array $config): void {
            $actual = array_map(static fn (array $p): string => "{$p[0]}: {$p[1]}", CorsPolicy::fromConfig($config)->problems());
            $this->assertCount(count($expected), $actual, implode("\n", $actual));
            foreach ($expected as $i => $start) {
                $this->assertStringStartsWith($start, $actual[$i]);
            }
        };

        $wildcard = CorsPolicy::fromConfig(['origins' => ['*'], 'credentials' => true]);
        $this->assertFalse($wildcard->enabled(), '"*" with credentials allows nothing');
        $this->assertFalse($wildcard->allows('https://evil.example'));
        $starts(['error: http.cors.origins is "*" with credentials'], ['origins' => ['*'], 'credentials' => true]);

        $starts([
            'error: "null" in http.cors.origins is not an origin',
            'error: "https://app.example.com/" in http.cors.origins is not an origin',
            'error: "app.example.com" in http.cors.origins is not an origin',
            'error: "https://*.com" in http.cors.origins would match every site under .com',
            'warning: "http://shop.example.com" in http.cors.origins is plain HTTP',
            'error: http.cors.origins lists int',
        ], ['origins' => ['null', 'https://app.example.com/', 'app.example.com', 'https://*.com', 'http://shop.example.com', 42]]);
        $starts(['error: http.cors.methods lists "GET POST"', 'error: http.cors.headers lists "X-Evil: 1"'], ['origins' => ['https://a.example'], 'methods' => ['GET POST'], 'headers' => ['X-Evil: 1']]);

        $this->assertFalse(CorsPolicy::fromConfig([])->enabled(), 'nothing listed: no CORS, as before 2.32');
        $any = CorsPolicy::fromConfig(['origins' => ['*']]);
        $this->assertTrue($any->allows('https://anyone.example'));
        $this->assertFalse($any->allows('https://*.example.org'), 'a wildcard sent as an origin is no origin, even for "*"');
        $this->assertFalse($any->allows('null'));
        $this->assertSame(86_400, CorsPolicy::fromConfig(['max_age' => 10_000_000])->maxAge, 'a day at most');
    }

    public function testAListedOriginReadsTheAnswerAndOthersDoNot(): void
    {
        $kernel = $this->kernel(['origins' => ['https://app.example.com'], 'expose' => ['X-Total-Count']]);

        $allowed = $kernel->handle(self::get('https://app.example.com'));
        $this->assertSame(200, $allowed->getStatusCode());
        $this->assertSame('https://app.example.com', $allowed->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('X-Total-Count', $allowed->getHeaderLine('Access-Control-Expose-Headers'));
        $this->assertFalse($allowed->hasHeader('Access-Control-Allow-Credentials'));
        $this->assertSame('Origin', $allowed->getHeaderLine('Vary'));

        $other = $kernel->handle(self::get('https://evil.example'));
        $this->assertSame(200, $other->getStatusCode(), 'the request runs, as without CORS');
        $this->assertFalse($other->hasHeader('Access-Control-Allow-Origin'), 'but the page cannot read it');
        $this->assertSame('Origin', $other->getHeaderLine('Vary'), 'and no cache gives it to the listed site');

        $none = $kernel->handle(new ServerRequest('GET', 'https://api.example.com/orders'));
        $this->assertFalse($none->hasHeader('Access-Control-Allow-Origin'));
        $this->assertSame('Origin', $none->getHeaderLine('Vary'));
    }

    public function testPreflightsAreAnsweredBeforeRoutingAndOnlyForWhatIsListed(): void
    {
        $kernel = $this->kernel(['origins' => ['https://app.example.com'], 'methods' => ['GET', 'POST'], 'headers' => ['Content-Type', 'Authorization'], 'max_age' => 300]);

        $ok = $kernel->handle(self::preflight('https://app.example.com', 'POST', 'content-type, Authorization'));
        $this->assertSame(204, $ok->getStatusCode(), 'the router, which knows no OPTIONS, is never asked');
        $this->assertSame('https://app.example.com', $ok->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('GET, POST', $ok->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertSame('content-type, authorization', $ok->getHeaderLine('Access-Control-Allow-Headers'));
        $this->assertSame('300', $ok->getHeaderLine('Access-Control-Max-Age'));
        $this->assertSame('Origin, Access-Control-Request-Method, Access-Control-Request-Headers', $ok->getHeaderLine('Vary'));

        foreach ([
            'another origin' => self::preflight('https://evil.example', 'POST'),
            'a method not listed' => self::preflight('https://app.example.com', 'DELETE'),
            'a header not listed' => self::preflight('https://app.example.com', 'POST', 'Content-Type, X-Admin'),
            'the null origin' => self::preflight('null', 'GET'),
        ] as $case => $request) {
            $refused = $kernel->handle($request);
            $this->assertSame([403, false], [$refused->getStatusCode(), $refused->hasHeader('Access-Control-Allow-Origin')], $case);
        }

        $plain = $kernel->handle(new ServerRequest('OPTIONS', 'https://api.example.com/orders', ['Origin' => 'https://app.example.com']));
        $this->assertSame('handled', (string) $plain->getBody(), 'an OPTIONS that is no preflight goes to the application');
    }

    public function testCredentialsEchoTheOriginAndAnyOriginIsStarWithout(): void
    {
        $credentials = $this->kernel(['origins' => ['https://app.example.com'], 'credentials' => true])->handle(self::get('https://app.example.com'));
        $this->assertSame(['https://app.example.com', 'true'], [$credentials->getHeaderLine('Access-Control-Allow-Origin'), $credentials->getHeaderLine('Access-Control-Allow-Credentials')]);

        $any = $this->kernel(['origins' => ['*']])->handle(self::get('https://anyone.example'));
        $this->assertSame('*', $any->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertFalse($any->hasHeader('Vary'), 'the same answer for every origin');
        $this->assertFalse($any->hasHeader('Access-Control-Allow-Credentials'));

        $starWithCredentials = $this->kernel(['origins' => ['*'], 'credentials' => true])->handle(self::get('https://evil.example'));
        $this->assertFalse($starWithCredentials->hasHeader('Access-Control-Allow-Origin'), 'never reflected with credentials');
    }

    public function testAnErrorCanBeReadByTheListedPage(): void
    {
        $response = $this->kernel(['origins' => ['https://app.example.com']])->handle(self::get('https://app.example.com', '/missing'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('https://app.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'), 'so the front end can show the problem JSON');
    }

    public function testWithoutAPolicyNothingChanges(): void
    {
        $response = $this->kernel([])->handle(self::preflight('https://app.example.com', 'POST'));

        $this->assertSame('handled', (string) $response->getBody(), 'the preflight goes to the application, as before 2.32');
        $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        $this->assertFalse($response->hasHeader('Vary'));
    }

    public function testCheckSaysWhatIsWrong(): void
    {
        $findings = static fn (array $config): array => array_map(
            static fn ($f): string => "{$f->severity->value}: " . substr($f->message, 0, 50),
            (new CorsCheck())->findings(Application::configure(sys_get_temp_dir() . '/zana-http-cors')->withEnvironment('testing')->withConfiguration($config)->withProviders(HttpServiceProvider::class)->build()),
        );

        $this->assertSame([], $findings([]));
        $this->assertSame([], $findings(['http.cors' => ['origins' => ['https://app.example.com']], 'http.middleware' => [CorsMiddleware::class]]));
        $this->assertSame(['warning: http.cors lists origins, but CorsMiddleware is not'], $findings(['http.cors' => ['origins' => ['https://app.example.com']]]));
        $this->assertSame(['error: http.cors.origins is "*" with credentials: browser'], $findings(['http.cors' => ['origins' => ['*'], 'credentials' => true], 'http.middleware' => [CorsMiddleware::class]]));
    }

    /**
     * @param array<string, mixed> $cors
     */
    private function kernel(array $cors): HttpKernel
    {
        $kernel = Application::configure(sys_get_temp_dir() . '/zana-http-cors')
            ->withEnvironment('testing')
            ->withConfiguration(['http.middleware' => [CorsMiddleware::class], 'http.json_errors' => true, ...($cors === [] ? [] : ['http.cors' => $cors])])
            ->withProviders(HttpServiceProvider::class)
            ->withServices(static fn (ServiceCollectionInterface $s) => $s->instance(HttpKernel::HANDLER, new Api()))
            ->build()
            ->container()
            ->get(HttpKernel::class);
        assert($kernel instanceof HttpKernel);

        return $kernel;
    }

    private static function get(string $origin, string $path = '/orders'): ServerRequestInterface
    {
        return new ServerRequest('GET', "https://api.example.com{$path}", ['Origin' => $origin]);
    }

    private static function preflight(string $origin, string $method, string $headers = ''): ServerRequestInterface
    {
        return new ServerRequest('OPTIONS', 'https://api.example.com/orders', ['Origin' => $origin, 'Access-Control-Request-Method' => $method, ...($headers === '' ? [] : ['Access-Control-Request-Headers' => $headers])]);
    }
}

/** The application behind the middleware: answers, or throws a 404 for /missing. */
final class Api implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getUri()->getPath() === '/missing') {
            throw new NotFoundHttpException('No such order.');
        }

        return new Response(200, [], 'handled');
    }
}
