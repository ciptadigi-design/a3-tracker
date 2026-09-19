<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * V1.5.6 - Production queue automation acceptance. A deliberately tiny,
 * side-effect-free job whose only purpose is proving that a real cron-invoked
 * `a3:run-queue` actually drains a real queued job end to end, without
 * re-running the 2639-page Konica extraction (or any other real business
 * mutation) just to exercise cron plumbing.
 *
 * Not reachable from any public HTTP route - only ever dispatched from the
 * `a3:queue-health-check` artisan command, an internal/operator-only surface.
 * Writes nothing but a timestamp under a caller-supplied opaque token; never
 * touches application data, PDFs, or any model.
 */
final class QueueHealthCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public const CACHE_TTL_MINUTES = 60;

    public function __construct(public readonly string $token) {}

    public function handle(): void
    {
        Cache::put(self::cacheKey($this->token), now()->toIso8601String(), now()->addMinutes(self::CACHE_TTL_MINUTES));
    }

    public static function cacheKey(string $token): string
    {
        return 'a3-queue-health-check:'.$token;
    }
}
