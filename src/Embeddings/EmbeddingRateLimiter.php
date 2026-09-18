<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Embeddings;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Registers the named limiter the embedding jobs run behind, so a bulk ingest
 * cannot walk into the provider's rate limit and burn its retry budget.
 */
final class EmbeddingRateLimiter
{
    public const NAME = 'fai-embeddings';

    public static function register(): void
    {
        $requests = (int) config('filament-ai.queue.rate_limit.requests', 500);
        $perSeconds = (int) config('filament-ai.queue.rate_limit.per_seconds', 60);

        RateLimiter::for(self::NAME, static fn (): Limit => Limit::perSecond($requests, $perSeconds)
            ->by(self::NAME));
    }
}
