<?php

namespace App\Services\Site;

use App\Enums\Archetype;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\Site;
use Illuminate\Support\Facades\Log;

/**
 * Post-hoc personality-family assignment for existing sites (`site:layout --assign`).
 *
 * Returns a family key (`editorial`/`showcase`/`precision`) that the
 * artisan command applies per kind: service+about+home together when a
 * home recipe exists for the family. `--independent` restricts that to
 * one kind. About recipes share those keys.
 *
 * This path is allowed to consult actual content. Pipeline (pre-generation)
 * assignment is a later phase and is not wired here.
 *
 * Archetype → weight map (classic is never in the rotation):
 *
 * | Archetypes                                              | Group                      | Weights (imagery-rich)                          |
 * |---------------------------------------------------------|----------------------------|-------------------------------------------------|
 * | emergency_trade, traditional_craftsman, local_service   | trades / civil             | precision 4, editorial 1, showcase 1            |
 * | premium_specialist, retail_venue                        | design / hospitality       | showcase 4, editorial 1, precision 1            |
 * | professional_service, saas_platform                     | professional / developer   | editorial 4, precision 1, showcase 1            |
 * | missing / unrecognised                                  | unknown                    | editorial 1, showcase 1, precision 1            |
 *
 * Imagery signal (post-hoc): dedicated per-page intro imagery — an active
 * `hero_versions` row with `slot='intro'` whose page_type is a service page
 * of the site, or a service page with `hero_source='dedicated'`. Without
 * it, showcase weight drops to 0. If that zeros the list, fall back to
 * editorial+precision uniformly.
 *
 * Tiebreak: crc32(site id + public host) modulo the expanded weighted list,
 * so the same site always draws the same preset.
 */
class ServiceLayoutAssigner
{
    public function __construct(private PageLayoutRegistry $registry) {}

    /**
     * @return 'editorial'|'showcase'|'precision'|'classic'
     */
    public function assignFamily(Site $site): string
    {
        $list = $this->expandedList($this->withoutWarnedRecipes($site, $this->weightsFor($site)));
        if ($list === []) {
            Log::info('layout-assign.all-warned', ['site_id' => $site->id]);

            return 'classic';
        }
        $domain = $site->publicHost() ?? $site->preview_domain ?? $site->custom_domain ?? '';
        $seed = (int) sprintf('%u', crc32((string) $site->id.$domain));
        $key = $list[$seed % count($list)];

        return $key === 'classic' ? 'precision' : $key;
    }

    /**
     * @return 'editorial'|'showcase'|'precision'|'classic'
     */
    public function assign(Site $site): string
    {
        return $this->assignFamily($site);
    }

    /**
     * @return array{editorial: int, showcase: int, precision: int}
     */
    public function weightsFor(Site $site): array
    {
        $weights = $this->archetypeWeights($this->archetypeOf($site));

        if (! $this->hasDedicatedIntroImagery($site)) {
            $weights['showcase'] = 0;
        }

        return $weights;
    }

    /**
     * @return array{editorial: int, showcase: int, precision: int}
     */
    private function archetypeWeights(?Archetype $archetype): array
    {
        return match ($archetype) {
            Archetype::EmergencyTrade,
            Archetype::TraditionalCraftsman,
            Archetype::LocalService => [
                'editorial' => 1,
                'showcase' => 1,
                'precision' => 4,
            ],
            Archetype::PremiumSpecialist,
            Archetype::RetailVenue => [
                'editorial' => 1,
                'showcase' => 4,
                'precision' => 1,
            ],
            Archetype::ProfessionalService,
            Archetype::SaasPlatform => [
                'editorial' => 4,
                'showcase' => 1,
                'precision' => 1,
            ],
            default => [
                'editorial' => 1,
                'showcase' => 1,
                'precision' => 1,
            ],
        };
    }

    private function archetypeOf(Site $site): ?Archetype
    {
        $raw = $site->businessProfile?->profile_data['archetype'] ?? null;
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return Archetype::tryFrom($raw);
    }

    private function hasDedicatedIntroImagery(Site $site): bool
    {
        $serviceTypes = $this->registry->servicePageTypesFor($site);
        if ($serviceTypes === []) {
            return false;
        }

        $hasIntroSlot = HeroVersion::query()
            ->where('site_id', $site->id)
            ->where('slot', 'intro')
            ->where('is_active', true)
            ->whereIn('page_type', $serviceTypes)
            ->exists();

        if ($hasIntroSlot) {
            return true;
        }

        return GeneratedPage::query()
            ->where('site_id', $site->id)
            ->where('hero_source', 'dedicated')
            ->get(['page_type', 'kind'])
            ->contains(fn (GeneratedPage $page): bool => $page->isServicePage());
    }

    /**
     * Zero the weight of any family whose resolved recipe currently warns.
     * Stock recipes are clean; this is the hard gate for site-scoped and
     * future recipes that trip recipeWarnings.
     *
     * @param  array{editorial: int, showcase: int, precision: int}  $weights
     * @return array{editorial: int, showcase: int, precision: int}
     */
    private function withoutWarnedRecipes(Site $site, array $weights): array
    {
        foreach (array_keys($weights) as $key) {
            $warnings = $this->recipeWarningsForKey($site, $key);
            if ($warnings === []) {
                continue;
            }

            Log::info('ServiceLayoutAssigner: skipping recipe with warnings', [
                'site_id' => $site->id,
                'key' => $key,
                'warnings' => $warnings,
            ]);
            $weights[$key] = 0;
        }

        return $weights;
    }

    /**
     * @return list<string>
     */
    private function recipeWarningsForKey(Site $site, string $key): array
    {
        $all = [];
        foreach (['home', 'about', 'service'] as $kind) {
            $recipe = $this->registry->resolveKey($site, $kind, $key);
            if (! is_array($recipe)) {
                continue;
            }

            foreach ($this->registry->recipeWarnings($recipe, $kind) as $warning) {
                $all[] = "{$kind}: {$warning}";
            }
        }

        return $all;
    }

    /**
     * @param  array{editorial: int, showcase: int, precision: int}  $weights
     * @return list<'editorial'|'showcase'|'precision'>
     */
    private function expandedList(array $weights): array
    {
        $list = [];
        foreach (['editorial', 'precision', 'showcase'] as $key) {
            $n = $weights[$key] ?? 0;
            for ($i = 0; $i < $n; $i++) {
                $list[] = $key;
            }
        }

        return $list;
    }
}
