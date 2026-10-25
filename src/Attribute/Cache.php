<?php

declare(strict_types=1);

namespace Zana\Http\Attribute;

use Attribute;

/**
 * Cache-Control for a route handler, applied by CacheHeadersMiddleware:
 *
 *     #[Cache(maxAge: 600, sharedMaxAge: 3600)]   // browsers 10 min, CDNs 1 h
 *     public function about(): View
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class Cache
{
    public function __construct(
        public int $maxAge = 0,
        public ?int $sharedMaxAge = null,
        public bool $public = true,
        public bool $immutable = false,
    ) {
    }

    public function header(): string
    {
        $parts = [$this->public ? 'public' : 'private', "max-age={$this->maxAge}"];
        if ($this->sharedMaxAge !== null && $this->public) {
            $parts[] = "s-maxage={$this->sharedMaxAge}";
        }
        if ($this->immutable) {
            $parts[] = 'immutable';
        }

        return implode(', ', $parts);
    }
}
