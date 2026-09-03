<?php

namespace App\Console\Commands;

use App\Models\LayoutPreset;
use App\Models\Site;
use App\Services\Site\PageLayoutRegistry;
use Illuminate\Console\Command;

/**
 * Promote a site's layout to a stock preset. The default mode is read-only:
 * validate the donor Tier-1 row and print the config stub. The config
 * edit itself stays a reviewed git commit — this command NEVER writes
 * config files. `--finalize` retires the donor after an
 * equality gate proves the config recipe matches.
 */
class SiteLayoutPromoteCommand extends Command
{
    protected $signature = 'site:layout-promote
        {site : Site id owning the donor Tier-1 row}
        {key : layout_presets.key to promote}
        {--kind=service : Page kind: home, service, about, projects, project_detail, or chrome}
        {--finalize : Verify the committed config recipe matches the donor row, then retire the row}';

    protected $description = 'Promote a site-scoped Tier-1 layout preset to a stock config preset: emit the config stub, then --finalize to retire the donor row.';

    public function handle(PageLayoutRegistry $registry): int
    {
        $kind = (string) $this->option('kind');
        $configName = $registry->configNameFor($kind);
        if ($configName === null) {
            $this->error('Invalid --kind. Use home, service, about, projects, project_detail, or chrome.');

            return self::FAILURE;
        }

        $site = Site::find((int) $this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }
        $key = (string) $this->argument('key');

        $row = LayoutPreset::query()
            ->where('site_id', $site->id)
            ->where('page_kind', $kind)
            ->where('key', $key)
            ->where('status', LayoutPreset::STATUS_ACTIVE)
            ->first();

        if ($row === null) {
            $retired = LayoutPreset::query()
                ->where('site_id', $site->id)
                ->where('page_kind', $kind)
                ->where('key', $key)
                ->where('status', LayoutPreset::STATUS_RETIRED)
                ->first();
            if ($retired !== null) {
                // "Already promoted" must re-prove the cutover invariant, not
                // just config presence: a drifted or unusable config after
                // retire means live renders no longer match what the donor
                // served.
                $config = config("{$configName}.{$key}");
                if (is_array($config) && $registry->isUsable($config, $kind)
                    && $this->normalize($config) === $this->normalize($this->configStub($retired))) {
                    $this->info("[{$key}] is already promoted: config recipe matches the retired donor.");

                    return self::SUCCESS;
                }
                $this->error("Donor row [{$key}] is retired but the config recipe is missing, unusable, or drifted from it — restore config parity (re-emit the stub from the retired row) before treating this as promoted.");

                return self::FAILURE;
            }
            $this->error("No active Tier-1 row [{$key}] (kind [{$kind}]) on site {$site->id}.");

            return self::FAILURE;
        }

        $stub = $this->configStub($row);
        if (! $registry->isUsable($stub, $kind)) {
            $this->error("Recipe fails hard validation for kind [{$kind}]:");
            foreach ($registry->hardErrors($stub, $kind) as $error) {
                $this->line("  - {$error}");
            }

            return self::FAILURE;
        }
        foreach ($registry->validate($stub, $kind) as $warning) {
            $this->warn($warning);
        }
        foreach ($registry->recipeWarnings($stub, $kind) as $warning) {
            $this->warn($warning);
        }

        if (! $this->option('finalize')) {
            $this->line("// Paste into config/{$configName}.php:");
            // Line-by-line so Laravel's expectsOutputToContain (per doWrite)
            // can see each fragment of the stub independently.
            foreach (explode("\n", "'{$key}' => ".$this->exportArray($stub, 1).',') as $line) {
                $this->line($line);
            }
            $this->info('Review + commit the config change, then re-run with --finalize to retire the donor row.');

            return self::SUCCESS;
        }

        if ($this->laravel->configurationIsCached()) {
            $this->error('Config cache detected — run `php artisan config:clear` first so --finalize compares the committed file, not a stale cache.');

            return self::FAILURE;
        }

        $config = config("{$configName}.{$key}");
        if (! is_array($config) || ! $registry->isUsable($config, $kind)) {
            $this->error("Config key [{$key}] missing or unusable in {$configName} — paste the stub, commit, then re-run --finalize.");

            return self::FAILURE;
        }
        if ($this->normalize($config) !== $this->normalize($stub)) {
            $this->error('Config recipe does not match the donor row — renders would change, nothing retired. Fix the config to match, or re-emit the stub.');

            return self::FAILURE;
        }

        // Promotion makes the key resolve GLOBALLY: any other site whose
        // layout column or page override already uses this key starts
        // resolving the new stock recipe. Surface that before retiring.
        $foreignPages = \App\Models\GeneratedPage::query()
            ->where('layout_preset_key', $key)
            ->where('site_id', '!=', $site->id)
            ->count();
        $foreignSites = Site::query()
            ->where('id', '!=', $site->id)
            ->where(fn ($q) => $q->where('services_layout', $key)
                ->orWhere('about_layout', $key)
                ->orWhere('home_layout', $key)
                ->orWhere('chrome_layout', $key))
            ->count();
        if ($foreignPages > 0 || $foreignSites > 0) {
            $this->warn("Key [{$key}] is referenced elsewhere: {$foreignPages} page override(s), {$foreignSites} site layout column(s) on other sites — those now resolve the promoted stock recipe.");
        }

        // Retire = the D8 cutover. Identical render by construction: the
        // equality gate just proved resolveKey() serves the same recipe
        // from config that it served from the row. The model's saved-hook
        // invalidates PublicPageCache for the donor site.
        $row->update(['status' => LayoutPreset::STATUS_RETIRED]);
        $this->info("Promoted [{$key}]: donor row retired; site {$site->id} resolves the stock config recipe.");

        return self::SUCCESS;
    }

    /**
     * The row hydrated to config shape — identical content to what
     * PageLayoutRegistry::resolveKey() serves from the row today
     * (hydrateFromRow folds label/description into the recipe).
     *
     * @return array<string, mixed>
     */
    private function configStub(LayoutPreset $row): array
    {
        $recipe = is_array($row->recipe) ? $row->recipe : [];
        unset($recipe['label'], $recipe['description']);

        return array_merge([
            'label' => $row->label,
            'description' => $row->description,
        ], $recipe);
    }

    /**
     * Short-syntax PHP array literal, 4-space indent, matching the house
     * style of the config/site_*_layouts.php files (compact [] for empty
     * arrays, lowercase null — var_export would emit multi-line and NULL).
     */
    private function exportArray(array $value, int $depth): string
    {
        if ($value === []) {
            return '[]';
        }
        $indent = str_repeat('    ', $depth);
        $inner = str_repeat('    ', $depth + 1);
        $isList = array_is_list($value);
        $lines = [];
        foreach ($value as $k => $v) {
            $prefix = $isList ? '' : var_export((string) $k, true).' => ';
            $rendered = match (true) {
                is_array($v) => $this->exportArray($v, $depth + 1),
                $v === null => 'null',
                default => var_export($v, true),
            };
            $lines[] = $inner.$prefix.$rendered.',';
        }

        return "[\n".implode("\n", $lines)."\n".$indent.']';
    }

    /**
     * Recursive ksort so strict comparison ignores key ORDER but not key
     * set, value types, or list ordering (lists keep 0..n keys).
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function normalize(array $value): array
    {
        ksort($value);
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = $this->normalize($v);
            }
        }

        return $value;
    }
}
