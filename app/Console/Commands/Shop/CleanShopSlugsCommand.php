<?php

namespace App\Console\Commands\Shop;

use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\Product;
use App\Models\Shop\ShopSlugRedirect;
use App\Models\Site;
use App\Observers\Shop\CatalogObserver;
use App\Services\Shop\CloudflarePurger;
use App\Services\Shop\SnapshotReader;
use App\Services\Site\PublicPageCache;
use App\Support\Shop\ShopSlug;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class CleanShopSlugsCommand extends Command
{
    protected $signature = 'shop:clean-slugs
        {site? : id or domain}
        {--all : every shop-enabled site}
        {--dry-run}';

    protected $description = 'Rename seeder-minted 6-character product slug suffixes to the clean form and record 301s.';

    public function handle(SnapshotReader $snapshots, CloudflarePurger $purger, PublicPageCache $pageCache): int
    {
        if (! $this->option('all') && $this->argument('site') === null) {
            $this->error('Pass a site or --all.');

            return self::FAILURE;
        }

        $sites = $this->sites();
        if ($sites->isEmpty()) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $rows = [];

        foreach ($sites as $site) {
            $changed = $this->cleanSite($site, $dryRun, $rows);
            if ($changed && ! $dryRun) {
                RebuildShopSnapshot::dispatch($site->id);
                $snapshots->invalidate($site->id);
                $purger->purgeShop($site->id);
                $pageCache->invalidate($site);
            }
        }

        if ($rows !== []) {
            $this->table(['site', 'old_slug', 'slug'], $rows);
        } else {
            $this->info('No seeder-minted slugs to clean.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $rows
     */
    private function cleanSite(Site $site, bool $dryRun, array &$rows): bool
    {
        $changed = false;
        $products = Product::query()
            ->where('site_id', $site->id)
            ->where('is_ai_seeded', true)
            ->orderBy('id')
            ->get();

        CatalogObserver::mute();
        try {
            $claimed = [];
            foreach ($products as $product) {
                $base = ShopSlug::stripSeederSuffix($product->slug);
                if ($base === null) {
                    continue;
                }

                $next = ShopSlug::uniqueFromBase($site->id, $base, $product->id, $claimed);
                if ($next === $product->slug) {
                    continue;
                }

                $rows[] = [(string) $site->id, $product->slug, $next];
                $changed = true;
                $claimed[] = $next;

                if ($dryRun) {
                    continue;
                }

                $old = $product->slug;
                $product->update(['slug' => $next]);
                ShopSlugRedirect::query()->updateOrCreate(
                    [
                        'site_id' => $site->id,
                        'kind' => 'product',
                        'old_slug' => $old,
                    ],
                    ['slug' => $next],
                );
            }
        } finally {
            CatalogObserver::unmute();
        }

        return $changed;
    }

    /**
     * @return Collection<int, Site>
     */
    private function sites(): Collection
    {
        if ($this->option('all')) {
            return Site::query()->where('shop_enabled', true)->orderBy('id')->get();
        }

        $site = $this->findSite((string) $this->argument('site'));

        return $site === null ? collect() : collect([$site]);
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
}
