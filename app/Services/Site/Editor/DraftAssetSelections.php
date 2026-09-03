<?php

namespace App\Services\Site\Editor;

use App\Models\HeroVersion;
use App\Models\HeroVideoVersion;
use App\Models\LogoConcept;
use App\Models\Site;
use App\Models\Site\SiteDraftAssetSelection;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class DraftAssetSelections
{
    /**
     * @param  array<string, mixed>|null  $placement
     */
    public function setHero(
        Site $site,
        string $pageType,
        string $slot,
        HeroVersion $version,
        ?int $userId,
        ?array $placement = null,
    ): void {
        if ((int) $version->site_id !== (int) $site->id) {
            throw new InvalidArgumentException('The hero version does not belong to this site.');
        }

        SiteDraftAssetSelection::query()->updateOrCreate(
            [
                'site_id' => $site->id,
                'family' => 'hero',
                'page_type' => $pageType,
                'slot' => $slot,
            ],
            [
                'version_id' => $version->id,
                'placement' => $placement,
                'created_by_user_id' => $userId,
            ],
        );
    }

    public function setHeroVideo(Site $site, ?HeroVideoVersion $version, string $mode, ?int $userId): void
    {
        if (! in_array($mode, ['on', 'off'], true)) {
            throw new InvalidArgumentException('The hero video mode must be on or off.');
        }

        if ($mode === 'on' && $version === null) {
            throw new InvalidArgumentException('A hero video version is required when mode is on.');
        }

        if ($version !== null && (int) $version->site_id !== (int) $site->id) {
            throw new InvalidArgumentException('The hero video version does not belong to this site.');
        }

        SiteDraftAssetSelection::query()->updateOrCreate(
            [
                'site_id' => $site->id,
                'family' => 'hero_video',
                'page_type' => 'home',
                'slot' => 'hero',
            ],
            [
                'version_id' => $mode === 'on' ? $version?->id : null,
                'mode' => $mode,
                'created_by_user_id' => $userId,
            ],
        );
    }

    public function setLogo(Site $site, LogoConcept $concept, ?int $userId): void
    {
        if ((int) $concept->site_id !== (int) $site->id) {
            throw new InvalidArgumentException('The logo concept does not belong to this site.');
        }

        SiteDraftAssetSelection::query()->updateOrCreate(
            [
                'site_id' => $site->id,
                'family' => 'logo',
                'page_type' => '',
                'slot' => '',
            ],
            [
                'version_id' => $concept->id,
                'created_by_user_id' => $userId,
            ],
        );
    }

    public function heroFor(Site $site, string $pageType, string $slot): ?HeroVersion
    {
        $selection = $this->selectionFor($site, 'hero', $pageType, $slot);

        return $selection === null
            ? null
            : HeroVersion::query()
                ->where('site_id', $site->id)
                ->find($selection->version_id);
    }

    /**
     * @return array{mode: 'on'|'off', version: HeroVideoVersion|null}|null
     */
    public function heroVideoFor(Site $site): ?array
    {
        $selection = $this->selectionFor($site, 'hero_video', 'home', 'hero');

        if ($selection === null) {
            return null;
        }

        $version = $selection->version_id === null
            ? null
            : HeroVideoVersion::query()
                ->where('site_id', $site->id)
                ->find($selection->version_id);

        return [
            'mode' => $selection->mode,
            'version' => $version,
        ];
    }

    public function logoFor(Site $site): ?LogoConcept
    {
        $selection = $this->selectionFor($site, 'logo', '', '');

        return $selection === null
            ? null
            : LogoConcept::query()
                ->where('site_id', $site->id)
                ->find($selection->version_id);
    }

    /**
     * @return Collection<int, SiteDraftAssetSelection>
     */
    public function all(Site $site): Collection
    {
        return SiteDraftAssetSelection::query()
            ->where('site_id', $site->id)
            ->orderBy('id')
            ->get();
    }

    public function any(Site $site): bool
    {
        return SiteDraftAssetSelection::query()
            ->where('site_id', $site->id)
            ->exists();
    }

    public function clear(Site $site): void
    {
        SiteDraftAssetSelection::query()
            ->where('site_id', $site->id)
            ->delete();
    }

    public function clearMatching(Site $site, string $family, ?string $pageType, ?string $slot): void
    {
        SiteDraftAssetSelection::query()
            ->where('site_id', $site->id)
            ->where('family', $family)
            ->where('page_type', $pageType ?? '')
            ->where('slot', $slot ?? '')
            ->delete();
    }

    public function clearHeroVideo(Site $site): void
    {
        $this->clearMatching($site, 'hero_video', 'home', 'hero');
    }

    private function selectionFor(Site $site, string $family, string $pageType, string $slot): ?SiteDraftAssetSelection
    {
        return SiteDraftAssetSelection::query()
            ->where('site_id', $site->id)
            ->where('family', $family)
            ->where('page_type', $pageType)
            ->where('slot', $slot)
            ->first();
    }
}
