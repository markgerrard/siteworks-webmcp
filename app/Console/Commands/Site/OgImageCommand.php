<?php

namespace App\Console\Commands\Site;

use App\Models\Site;
use App\Services\Site\BrandImageService;
use App\Services\Site\PublicPageCache;
use Illuminate\Console\Command;

class OgImageCommand extends Command
{
    protected $signature = 'site:og-image
        {site? : Site id, preview_domain or custom_domain}
        {--all : Generate for every site}
        {--force : Regenerate even when a card already exists}';

    protected $description = 'Generate (or regenerate) the 1200×630 and 1200×1200 Open Graph share cards for a site.';

    public function handle(BrandImageService $brandImages, PublicPageCache $pageCache): int
    {
        $all = (bool) $this->option('all');
        $token = $this->argument('site');

        if ($all === false && (! is_string($token) || $token === '')) {
            $this->error('Pass a site id/domain or --all.');

            return self::FAILURE;
        }

        if ($all && is_string($token) && $token !== '') {
            $this->error('Pass either a site or --all, not both.');

            return self::FAILURE;
        }

        $sites = $all
            ? Site::query()->orderBy('id')->cursor()
            : collect([$this->findSite($token)]);

        $generated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($sites as $site) {
            if (! $site instanceof Site) {
                $this->error('Site not found.');

                return self::FAILURE;
            }

            if (! $this->option('force') && filled($site->brand_og_url) && filled($site->brand_og_square_url)) {
                $this->line("skipped {$site->id} {$site->business_name}");
                $skipped++;

                continue;
            }

            $ogUrl = $brandImages->generateOgImage($site);
            $squareUrl = $brandImages->generateOgSquareImage($site);
            if ($ogUrl === null) {
                $this->warn("failed {$site->id} {$site->business_name}");
                $failed++;

                continue;
            }

            $site->update(array_filter([
                'brand_og_url' => $ogUrl,
                'brand_og_square_url' => $squareUrl,
            ]));
            $pageCache->invalidate($site);
            $this->info("generated {$site->id} {$site->business_name}");
            $generated++;
        }

        $this->line("generated={$generated} skipped={$skipped} failed={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function findSite(string $token): ?Site
    {
        if (ctype_digit($token)) {
            return Site::query()->find((int) $token);
        }

        return Site::query()
            ->where(function ($query) use ($token): void {
                $query->where('custom_domain', $token)
                    ->orWhere('preview_domain', $token);
            })
            ->first();
    }
};
