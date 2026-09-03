<?php

namespace App\Console\Commands;

use App\Models\LayoutPreset;
use App\Models\Site;
use App\Services\Site\PageLayoutRegistry;
use App\Services\Site\PublicPageCache;
use App\Services\Site\ServiceLayoutAssigner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SiteLayoutCommand extends Command
{
    /** @var array<string, string> */
    private const COLUMN_MAP = [
        'service' => 'services_layout',
        'about' => 'about_layout',
        'home' => 'home_layout',
        'chrome' => 'chrome_layout',
    ];

    /** @var array<string, string> */
    private const CONFIG_MAP = [
        'service' => 'site_service_layouts',
        'about' => 'site_about_layouts',
        'home' => 'site_home_layouts',
        'chrome' => 'site_chrome_layouts',
    ];

    protected $signature = 'site:layout
        {site? : Site id or domain}
        {key? : Preset key to set}
        {--kind=service : Page kind: service, about, home, or chrome}
        {--validate : Validate the effective recipe}
        {--assign : Auto-assign from signals and persist}
        {--independent : Restrict --assign to the given --kind only}
        {--flush= : Fan PublicPageCache::invalidate across every site whose layout column for --kind equals this key}';

    protected $description = 'Get, set, validate, auto-assign, or flush cache for a site\'s per-kind layout preset.';

    public function handle(PageLayoutRegistry $registry, PublicPageCache $cache, ServiceLayoutAssigner $assigner): int
    {
        $flushKey = $this->option('flush');
        if (is_string($flushKey) && $flushKey !== '') {
            return $this->flushKey($flushKey, $cache);
        }

        if ($this->option('independent') && ! $this->option('assign')) {
            $this->error('--independent can only be used with --assign.');

            return self::FAILURE;
        }

        $kind = $this->parsedKind();
        if ($kind === null) {
            return self::FAILURE;
        }

        if ($this->option('assign') && $kind === 'chrome') {
            $this->error('--assign is not supported for chrome presets.');

            return self::FAILURE;
        }

        $token = $this->argument('site');
        if (! is_string($token) || $token === '') {
            $this->error('Site id or domain is required unless --flush is used.');

            return self::FAILURE;
        }

        $site = $this->findSite($token);
        if ($site === null) {
            $this->error("Site [{$token}] not found.");

            return self::FAILURE;
        }

        $key = $this->argument('key');

        if ($this->option('assign') && (! is_string($key) || $key === '')) {
            $status = $this->assignFromSignals($site, $registry, $cache, $assigner, $kind);
            if ($status !== self::SUCCESS) {
                return $status;
            }

            if ($this->option('validate')) {
                return $this->validateSite($site->fresh(), $registry, $kind);
            }

            return self::SUCCESS;
        }

        $options = $registry->optionsFor($site, $kind);

        if (is_string($key) && $key !== '') {
            if (! array_key_exists($key, $options)) {
                $this->error("Unknown layout key [{$key}]. Available:");
                $this->printOptions($options);

                return self::FAILURE;
            }

            if ($this->option('validate')) {
                $status = $this->validateProposedKey($site, $key, $registry, $kind);
                if ($status !== self::SUCCESS) {
                    return $status;
                }
            }

            $column = self::COLUMN_MAP[$kind];
            $old = $this->currentKey($site, $kind);
            $site->update([$column => $key]);
            $cache->invalidate($site);
            $this->info("{$old} -> {$key}");
            $site->refresh();
        }

        if ($this->option('validate')) {
            return $this->validateSite($site, $registry, $kind);
        }

        if (! is_string($key) || $key === '') {
            $current = $this->currentKey($site, $kind);
            $this->info(self::COLUMN_MAP[$kind].": {$current}");
            $this->line('Available:');
            $this->printOptions($options);
        }

        return self::SUCCESS;
    }

    private function flushKey(string $key, PublicPageCache $cache): int
    {
        if (! $this->input->hasParameterOption('--kind')) {
            $this->error('--flush requires --kind=service|about|home|chrome.');

            return self::FAILURE;
        }

        $kind = $this->parsedKind();
        if ($kind === null) {
            return self::FAILURE;
        }

        $column = self::COLUMN_MAP[$kind];
        $sites = Site::query()->where($column, $key)->get();

        foreach ($sites as $site) {
            $cache->invalidate($site);
        }

        $this->info("Invalidated {$sites->count()} site(s) for {$column}={$key}.");

        return self::SUCCESS;
    }

    private function assignFromSignals(
        Site $site,
        PageLayoutRegistry $registry,
        PublicPageCache $cache,
        ServiceLayoutAssigner $assigner,
        string $kind,
    ): int {
        $family = $assigner->assignFamily($site);
        $targets = $this->option('independent')
            ? [$kind => $family]
            : $this->familyTargets($family, $site, $registry);

        $writes = [];
        foreach ($targets as $targetKind => $preset) {
            $options = $registry->optionsFor($site, $targetKind);
            if (! array_key_exists($preset, $options)) {
                if ($targetKind === 'home') {
                    $this->error("Family [{$preset}] has no home recipe.");

                    return self::FAILURE;
                }

                $this->error("Unknown layout key [{$preset}] for kind [{$targetKind}]. Available:");
                $this->printOptions($options);

                return self::FAILURE;
            }

            $writes[$targetKind] = $preset;
        }

        $old = [];
        foreach ($writes as $targetKind => $preset) {
            $old[$targetKind] = $this->currentKey($site, $targetKind);
        }

        DB::transaction(function () use ($site, $writes): void {
            $payload = [];
            foreach ($writes as $targetKind => $preset) {
                $payload[self::COLUMN_MAP[$targetKind]] = $preset;
            }
            $site->update($payload);
        });

        $cache->invalidate($site);

        foreach ($writes as $targetKind => $preset) {
            $this->info(self::COLUMN_MAP[$targetKind].": {$old[$targetKind]} -> {$preset}");
        }

        $site->refresh();

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function familyTargets(string $family, Site $site, PageLayoutRegistry $registry): array
    {
        $targets = [
            'service' => $family,
            'about' => $family,
        ];

        if (array_key_exists($family, $registry->optionsFor($site, 'home'))) {
            $targets['home'] = $family;
        }

        return $targets;
    }

    private function parsedKind(): ?string
    {
        $kind = $this->option('kind');
        if (! is_string($kind) || ! array_key_exists($kind, self::COLUMN_MAP)) {
            $this->error('Invalid --kind. Use service, about, home, or chrome.');

            return null;
        }

        return $kind;
    }

    private function currentKey(Site $site, string $kind): string
    {
        $column = self::COLUMN_MAP[$kind];
        $key = $site->{$column} ?? 'classic';
        if ($key instanceof \BackedEnum) {
            $key = $key->value;
        }

        return is_string($key) && $key !== '' ? $key : 'classic';
    }

    private function validateProposedKey(Site $site, string $key, PageLayoutRegistry $registry, string $kind): int
    {
        $probe = (clone $site)->forceFill([self::COLUMN_MAP[$kind] => $key]);

        return $this->validateSite($probe, $registry, $kind);
    }

    private function validateSite(Site $site, PageLayoutRegistry $registry, string $kind): int
    {
        $key = $this->currentKey($site, $kind);
        $recipe = $registry->resolve($site, $kind);

        if ($recipe === null && $key === 'classic') {
            $config = config(self::CONFIG_MAP[$kind].'.classic');
            $recipe = is_array($config) ? $config : null;
        }

        if (! is_array($recipe)) {
            $recipe = $this->storedRecipe($site, $key, $kind);
        }

        if (! is_array($recipe)) {
            $this->error("No effective recipe for ".self::COLUMN_MAP[$kind]." [{$key}].");

            return self::FAILURE;
        }

        $errors = $registry->validate($recipe, $kind);
        if ($errors === []) {
            $this->info("Recipe for [{$key}] is valid.");

            return self::SUCCESS;
        }

        $hard = false;
        foreach ($errors as $error) {
            if (str_starts_with($error, 'Warning:')) {
                $this->warn($error);
            } else {
                $this->error($error);
                $hard = true;
            }
        }

        return $hard ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, array{label: string, description: string|null}>  $options
     */
    private function printOptions(array $options): void
    {
        foreach ($options as $key => $opt) {
            $description = $opt['description'] ?? '';
            $this->line("  {$key}: {$opt['label']}".($description !== '' ? " — {$description}" : ''));
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function storedRecipe(Site $site, string $key, string $kind): ?array
    {
        if ($site->id) {
            $row = LayoutPreset::query()
                ->where('site_id', $site->id)
                ->where('page_kind', $kind)
                ->where('key', $key)
                ->where('status', LayoutPreset::STATUS_ACTIVE)
                ->first();

            if ($row !== null && is_array($row->recipe)) {
                return $row->recipe;
            }
        }

        $config = config(self::CONFIG_MAP[$kind].".{$key}");

        return is_array($config) ? $config : null;
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
