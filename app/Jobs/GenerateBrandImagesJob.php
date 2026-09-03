<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Site\BrandImageService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Regenerates the server-side favicon + OG PNGs whenever a
 * new design brief lands. Isolated from DesignBriefJob so Imagick
 * failures / Spaces hiccups don't mask brief-generation problems, and
 * each is independently retryable.
 */
class GenerateBrandImagesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    // Brand assets are non-critical and the work is cheap (Imagick +
    // one Spaces put each). One retry is plenty — sustained failure
    // usually means an Imagick config issue, not a transient blip.
    public int $tries = 2;

    public array $backoff = [30];

    public int $timeout = 60;

    public function __construct(public Site $site) {}

    public function uniqueId(): string
    {
        return (string) $this->site->id;
    }

    public function handle(BrandImageService $brandImages): void
    {
        try {
            $brandImages->regenerateBoth($this->site->fresh() ?? $this->site);
        } catch (Throwable $exception) {
            Log::warning('GenerateBrandImagesJob failed', [
                'site_id' => $this->site->id,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
