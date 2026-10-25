<?php

declare(strict_types=1);

namespace Zana\Http\Tests;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\SimpleCache\CacheInterface;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Container\Contracts\ServiceResolverInterface;
use Zana\Core\Application;
use Zana\Http\Attribute\Cache;
use Zana\Http\BasePath;
use Zana\Http\CacheHeadersMiddleware;
use Zana\Http\Exception\ServiceUnavailableHttpException;
use Zana\Http\Exception\TooManyRequestsHttpException;
use Zana\Http\FormInput;
use Zana\Http\HttpKernel;
use Zana\Http\HttpServiceProvider;
use Zana\Http\IpRange;
use Zana\Http\PageCache;
use Zana\Http\PageCacheMiddleware;
use Zana\Http\RequestContext;
use Zana\Http\TrustedProxiesMiddleware;

final class ContentSiteTest extends TestCase
{
    // ── FE-13 ──────────────────────────────────────────────────────────────

    public function testRetryAfterExceptions(): void
    {
        $limited = new TooManyRequestsHttpException(60, headers: ['RateLimit-Limit' => '5']);
        $this->assertSame([429, ['Retry-After' => '60', 'RateLimit-Limit' => '5']], [$limited->status, $limited->headers]);

        $down = new ServiceUnavailableHttpException(300);
        $this->assertSame([503, ['Retry-After' => '300']], [$down->status, $down->headers]);
        $this->assertSame([], (new ServiceUnavailableHttpException())->headers);
    }

    // ── FE-12 ──────────────────────────────────────────────────────────────

    /**
     * @return iterable<string, array{list<string>, string, bool}>
     */
    public static function ranges(): iterable
    {
        yield 'exact' => [['203.0.113.7'], '203.0.113.7', true];
        yield 'cidr v4' => [['10.0.0.0/8'], '10.20.30.40', true];
        yield 'outside v4' => [['10.0.0.0/8'], '11.0.0.1', false];
        yield 'odd prefix' => [['192.168.0.0/23'], '192.168.1.200', true];
        yield 'odd prefix outside' => [['192.168.0.0/23'], '192.168.2.1', false];
        yield 'cidr v6' => [['2001:db8::/32'], '2001:db8:1::5', true];
        yield 'family mismatch' => [['10.0.0.0/8'], '::1', false];
        yield 'garbage' => [['10.0.0.0/8'], 'not-an-ip', false];
    }

    /**
     * @param list<string> $ranges
     */
    #[DataProvider('ranges')]
    public function testIpRanges(array $ranges, string $ip, bool $expected): void
    {
        $this->assertSame($expected, IpRange::contains($ranges, $ip));
    }

    public function testTheClientBehindATrustedProxyIsFound(): void
    {
        $context = new RequestContext();
        $request = new ServerRequest('GET', 'http://example.com/', [
            'X-Forwarded-For' => '198.51.100.9, 10.0.0.2',   // client, then an inner proxy
            'X-Forwarded-Proto' => 'https',
        ], serverParams: ['REMOTE_ADDR' => '10.0.0.1']);

        (new TrustedProxiesMiddleware(['10.0.0.0/8'], $context))->process($request, new Answer());

        $this->assertSame('198.51.100.9', $context->clientIp());
        $this->assertTrue($context->isSecure());
    }

    public function testForwardedHeadersFromUntrustedClientsAreIgnored(): void
    {
        $context = new RequestContext();
        $request = new ServerRequest('GET', 'http://example.com/', ['X-Forwarded-For' => '1.2.3.4', 'X-Forwarded-Proto' => 'https'], serverParams: ['REMOTE_ADDR' => '198.51.100.9']);

        (new TrustedProxiesMiddleware(['10.0.0.0/8'], $context))->process($request, new Answer());

        $this->assertSame('198.51.100.9', $context->clientIp());
        $this->assertFalse($context->isSecure());
    }

    public function testTheStandardForwardedHeader(): void
    {
        $context = new RequestContext();
        $request = new ServerRequest('GET', 'http://example.com/', ['Forwarded' => 'for="[2001:db8::7]:4711";proto=https, for=10.0.0.3'], serverParams: ['REMOTE_ADDR' => '10.0.0.1']);

        (new TrustedProxiesMiddleware(['10.0.0.0/8'], $context))->process($request, new Answer());

        $this->assertSame('2001:db8::7', $context->clientIp());
    }

    // ── FE-11 ──────────────────────────────────────────────────────────────

    public function testTheBasePathIsRemovedBeforeRouting(): void
    {
        $kernel = $this->kernel(['http.base_path' => '/shop/'], static fn (ServiceCollectionInterface $s) => $s
            ->replace(HttpKernel::HANDLER, static fn (ServiceResolverInterface $r): RequestHandlerInterface => new class (self::context($r)) implements RequestHandlerInterface {
                public function __construct(private readonly RequestContext $context)
                {
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200, [], $request->getUri()->getPath() . ' under ' . $this->context->basePath());
                }
            }));

        $this->assertSame('/cart under /shop', (string) $kernel->handle(new ServerRequest('GET', '/shop/cart'))->getBody());
        $this->assertSame('/ under /shop', (string) $kernel->handle(new ServerRequest('GET', '/shop'))->getBody());
        $this->assertSame('/shopping under ', (string) $kernel->handle(new ServerRequest('GET', '/shopping'))->getBody(), 'not a prefix match');
    }

    public function testBasePathNormalization(): void
    {
        $this->assertSame(['', '', '/shop', '/a/b'], [BasePath::normalize('/'), BasePath::normalize('.'), BasePath::normalize('shop/'), BasePath::normalize('\\a\\b')]);
    }

    // ── FE-15 ──────────────────────────────────────────────────────────────

    public function testFormInputTrimsAndDropsBlankFields(): void
    {
        $input = FormInput::from((new ServerRequest('POST', '/'))->withParsedBody([
            'name' => '  Ada ', 'company' => '   ', 'tags' => ['a', ' ', 'b'], 'agree' => 'on', 'count' => 3,
        ]));

        $this->assertSame('Ada', $input->string('name'));
        $this->assertFalse($input->has('company'));
        $this->assertNull($input->optional('company'));
        $this->assertSame('none', $input->string('company', 'none'));
        $this->assertSame(['a', 'b'], $input->list('tags'));
        $this->assertTrue($input->bool('agree'));
        $this->assertSame(['name' => 'Ada'], $input->only('name', 'company'));
    }

    public function testUnparsedUrlencodedBodies(): void
    {
        $request = new ServerRequest('PUT', '/', ['Content-Type' => 'application/x-www-form-urlencoded'], 'name=Ada+L&x=');

        $this->assertSame(['name' => 'Ada L'], FormInput::from($request)->all());
    }

    // ── FE-20 ──────────────────────────────────────────────────────────────

    public function testCacheAttributesETagsAndNotModified(): void
    {
        $middleware = new CacheHeadersMiddleware();
        $request = (new ServerRequest('GET', '/about'))->withAttribute('_handler', [CachedPage::class, 'show']);

        $first = $middleware->process($request, new Answer(new Response(200, [], 'About us')));
        $this->assertSame('public, max-age=600, s-maxage=3600', $first->getHeaderLine('Cache-Control'));
        $etag = $first->getHeaderLine('ETag');
        $this->assertStringStartsWith('W/"', $etag);

        $again = $middleware->process($request->withHeader('If-None-Match', $etag), new Answer(new Response(200, [], 'About us')));
        $this->assertSame(304, $again->getStatusCode());
        $this->assertSame('', (string) $again->getBody());

        $changed = $middleware->process($request->withHeader('If-None-Match', $etag), new Answer(new Response(200, [], 'About us, updated')));
        $this->assertSame(200, $changed->getStatusCode());
    }

    public function testCookiesAndPostsAreNotMadeCacheable(): void
    {
        $middleware = new CacheHeadersMiddleware();
        $request = (new ServerRequest('GET', '/about'))->withAttribute('_handler', [CachedPage::class, 'show']);

        $this->assertFalse($middleware->process($request, new Answer(new Response(200, ['Set-Cookie' => 'a=b'])))->hasHeader('Cache-Control'));
        $this->assertFalse($middleware->process($request->withMethod('POST'), new Answer(new Response()))->hasHeader('ETag'));
    }

    // ── FE-33 ──────────────────────────────────────────────────────────────

    public function testPublicPagesAreServedFromTheCacheUntilTheirTagsChange(): void
    {
        $cache = new PageCache(new MemoryCache());
        $middleware = new PageCacheMiddleware($cache, sharedMaxAge: 600);
        $handler = new Counter();
        $request = new ServerRequest('GET', 'https://example.com/en/news/1');

        $miss = $middleware->process($request, $handler);
        $hit = $middleware->process($request, $handler);

        $this->assertSame(['MISS', 'HIT'], [$miss->getHeaderLine('X-Page-Cache'), $hit->getHeaderLine('X-Page-Cache')]);
        $this->assertSame('page 1', (string) $hit->getBody());
        $this->assertFalse($hit->hasHeader(PageCache::TAGS_HEADER), 'tags are never sent');
        $this->assertSame('public, max-age=0, s-maxage=600', $hit->getHeaderLine('Cache-Control'));

        $cache->invalidate('nav');
        $this->assertSame('page 2', (string) $middleware->process($request, $handler)->getBody(), 'invalidated');
        $this->assertSame('page 2', (string) $middleware->process($request, $handler)->getBody());
    }

    public function testPrivateRequestsAndResponsesAreNeverCached(): void
    {
        $middleware = new PageCacheMiddleware(new PageCache(new MemoryCache()));
        $handler = new Counter();
        $page = new ServerRequest('GET', 'https://example.com/');

        $middleware->process($page->withCookieParams(['zana_session' => 'x']), $handler);
        $middleware->process($page->withHeader('Authorization', 'Bearer t'), $handler);
        $middleware->process($page->withMethod('POST'), $handler);
        $this->assertSame(3, $handler->calls);

        foreach ([['Set-Cookie' => 'a=b'], ['Cache-Control' => 'private'], ['Vary' => 'Accept-Language']] as $headers) {
            $once = new Counter($headers);
            $middleware->process($page->withUri($page->getUri()->withPath('/' . md5(serialize($headers)))), $once);
            $middleware->process($page->withUri($page->getUri()->withPath('/' . md5(serialize($headers)))), $once);
            $this->assertSame(2, $once->calls, json_encode($headers, JSON_THROW_ON_ERROR));
        }
    }

    public function testTheProviderWiresThePageCacheToTheApplicationCache(): void
    {
        $kernel = $this->kernel(['http.middleware' => [PageCacheMiddleware::class]], static fn (ServiceCollectionInterface $s) => $s
            ->instance(CacheInterface::class, new MemoryCache())
            ->singleton(Counter::class)
            ->replace(HttpKernel::HANDLER, static fn (ServiceResolverInterface $r): mixed => $r->get(Counter::class)));

        $kernel->handle(new ServerRequest('GET', 'https://example.com/'));
        $this->assertSame('HIT', $kernel->handle(new ServerRequest('GET', 'https://example.com/'))->getHeaderLine('X-Page-Cache'));
    }

    private static function context(ServiceResolverInterface $resolver): RequestContext
    {
        $context = $resolver->get(RequestContext::class);
        assert($context instanceof RequestContext);

        return $context;
    }

    /**
     * @param array<string, mixed> $config
     * @param \Closure(ServiceCollectionInterface): mixed $services
     */
    private function kernel(array $config, \Closure $services): HttpKernel
    {
        $kernel = Application::configure(sys_get_temp_dir() . '/zana-http-content')
            ->withEnvironment('testing')
            ->withConfiguration($config)
            ->withProviders(HttpServiceProvider::class)
            ->withServices($services)
            ->build()
            ->container()
            ->get(HttpKernel::class);
        assert($kernel instanceof HttpKernel);

        return $kernel;
    }
}

final class CachedPage
{
    #[Cache(maxAge: 600, sharedMaxAge: 3600)]
    public function show(): string
    {
        return 'About us';
    }
}

final readonly class Answer implements RequestHandlerInterface
{
    public function __construct(private ResponseInterface $response = new Response())
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->response;
    }
}

final class Counter implements RequestHandlerInterface
{
    public int $calls = 0;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(private readonly array $headers = [])
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->calls++;

        return new Response(200, [PageCache::TAGS_HEADER => 'post:1, nav', ...$this->headers], "page {$this->calls}");
    }
}

final class MemoryCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $items = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->items[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->items = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->get($key, $default);
        }
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->items);
    }
}
