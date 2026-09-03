<?php

namespace App\Console\Commands;

use App\Models\HeroVersion;
use App\Services\Images\ImageOptimiserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Backfill: re-encode active HeroVersion PNGs to WebP siblings and point
 * the rows at them. Originals are kept in the bucket — rollback is a URL
 * flip. Run `cache:clear` after: the site-public render cache holds URLs.
 */
class OptimiseImagesCommand extends Command
{
    protected $signature = 'images:optimise
        {--dry-run : Report what would change without writing}
        {--site= : Limit to one site id}
        {--min-bytes=300000 : Skip files already smaller than this}';

    protected $description = 'Re-encode active HeroVersion PNG images as WebP siblings and update the rows';

    public function handle(ImageOptimiserService $optimiser): int
    {
        $disk = Storage::disk('s3');
        $base = rtrim($disk->url(''), '/').'/';

        $rows = HeroVersion::where('is_active', true)
            ->when($this->option('site'), fn ($q, $site) => $q->where('site_id', $site))
            ->where(function ($q) {
                $q->where('url', 'like', '%.png')->orWhere('watermark_url', 'like', '%.png');
            })
            ->get();

        $done = 0;
        $savedBytes = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $updates = [];
            foreach (['url', 'watermark_url'] as $field) {
                $url = $row->{$field};
                if (! is_string($url) || ! str_ends_with(strtolower($url), '.png') || ! str_starts_with($url, $base)) {
                    continue;
                }
                $path = substr($url, strlen($base));

                try {
                    $bytes = $disk->get($path);
                    if ($bytes === null || strlen($bytes) < (int) $this->option('min-bytes')) {
                        $skipped++;

                        continue;
                    }
                    $out = $optimiser->optimise($bytes);
                    $newPath = preg_replace('/\.png$/i', '.webp', $path);
                    if (! $this->option('dry-run')) {
                        $disk->put($newPath, $out['bytes'], 'public');
                        $updates[$field] = $disk->url($newPath);
                    }
                    $savedBytes += max(0, strlen($bytes) - strlen($out['bytes']));
                    $done++;
                    $this->line(sprintf(
                        '%s %s: %s -> %s (%.1fMB -> %.0fKB)',
                        $this->option('dry-run') ? '[dry]' : '[ok]',
                        $row->id, basename($path), basename($newPath),
                        strlen($bytes) / 1048576, strlen($out['bytes']) / 1024,
                    ));
                } catch (Throwable $e) {
                    $failed++;
                    $this->warn("row {$row->id} {$field}: {$e->getMessage()}");
                }
            }
            if ($updates !== [] && ! $this->option('dry-run')) {
                $row->update($updates);
            }
        }

        $this->info(sprintf(
            'images: %d converted, %d skipped, %d failed, %.1fMB saved%s',
            $done, $skipped, $failed, $savedBytes / 1048576,
            $this->option('dry-run') ? ' (dry run — nothing written)' : '. Run cache:clear to serve the new URLs.',
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
