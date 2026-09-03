<?php

namespace App\Services\Site;

use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\HeroVideoVersion;
use App\Models\Site;
use App\Models\Site\SiteDraftAssetSelection;
use Illuminate\Support\Facades\Storage;

final class HeroResolution
{
    public function for(Site $site, GeneratedPage $page, bool $useDraftAssets, bool $resolveVideo = true): HeroState
    {
        [$imageVersion, $imageUrl, $placement, $imageReason] = $this->resolveImage($site, $page, $useDraftAssets);

        [$videoVersion, $videoUrl] = $resolveVideo
            ? $this->resolveVideo($site, $page, $useDraftAssets)
            : [null, null];
        $scene = $this->resolveScene($site, $page, $useDraftAssets);

        // Public renders rank video over a configured scene only on the
        // legacy `enabled && path` predicate (HeroSceneService::resolve).
        // resolveVideo() still falls back to the canonical S3 key when
        // path is null, so treating `$videoUrl !== null` as the winner
        // here would report video for a published site that actually
        // renders the scene. Drafted resolution may use the resolver
        // URL: that is the state the draft is expressing. When there is
        // no scene, a canonical video_url still wins so the no-scene
        // public path (deriveLegacyScene) stays video.
        $videoBeatsScene = $useDraftAssets
            ? $videoUrl !== null
            : ($videoUrl !== null && $site->home_hero_video_enabled && $site->home_hero_video_path);

        if ($videoBeatsScene) {
            $mode = 'video';
            $reason = 'video_mode_active';
        } elseif ($scene !== null) {
            $mode = 'scene';
            $reason = 'scene_active';
        } elseif ($videoUrl !== null) {
            $mode = 'video';
            $reason = 'video_mode_active';
        } elseif ($imageUrl !== null) {
            $mode = 'image';
            $reason = $imageReason;
        } else {
            $mode = 'none';
            $reason = $imageReason;
        }

        return new HeroState(
            mode: $mode,
            image_version_id: $imageVersion?->id,
            image_url: $imageUrl,
            video_version_id: $videoVersion?->id,
            video_url: $videoUrl,
            placement: $placement,
            scene: $scene,
            reason: $reason,
        );
    }

    /**
     * @return array{0: HeroVersion|null, 1: string|null, 2: array<string, mixed>, 3: string}
     */
    private function resolveImage(Site $site, GeneratedPage $page, bool $useDraftAssets): array
    {
        $pageType = (string) $page->page_type;

        if ($useDraftAssets) {
            $draftImage = $this->resolveDraftImage($site, $pageType);
            if ($draftImage !== null) {
                return $draftImage;
            }
        }

        if ($pageType === 'home' || $page->hero_source === 'dedicated') {
            $version = $this->activeImage($site, $pageType);
            if ($version !== null) {
                return [
                    $version,
                    $this->renderedImageUrl($site, $version->url, $version->watermark_url),
                    $version->placement ?? [],
                    'hero_version_active',
                ];
            }
        }

        if ($pageType !== 'home') {
            if ($useDraftAssets) {
                $draftImage = $this->resolveDraftImage($site, '__shared_service_hero');
                if ($draftImage !== null) {
                    return $draftImage;
                }
            }

            $version = $this->activeImage($site, '__shared_service_hero');
            if ($version !== null) {
                return [
                    $version,
                    $this->renderedImageUrl($site, $version->url, $version->watermark_url),
                    $version->placement ?? [],
                    'shared_service_hero',
                ];
            }
        }

        $snapshotImage = $site->previews()->latest()->first()?->snapshot['hero_images'][$pageType] ?? null;
        if (is_array($snapshotImage)) {
            $url = $this->renderedImageUrl(
                $site,
                is_string($snapshotImage['url'] ?? null) ? $snapshotImage['url'] : null,
                is_string($snapshotImage['watermark_url'] ?? null) ? $snapshotImage['watermark_url'] : null,
            );
            if ($url !== null) {
                $snapshotPlacement = $snapshotImage['placement'] ?? [];

                return [null, $url, is_array($snapshotPlacement) ? $snapshotPlacement : [], 'snapshot_fallback'];
            }
        } elseif (is_string($snapshotImage) && $snapshotImage !== '') {
            return [null, $snapshotImage, [], 'snapshot_fallback'];
        }

        return [null, null, [], 'none'];
    }

    /**
     * @return array{0: HeroVersion, 1: string|null, 2: array<string, mixed>, 3: string}|null
     */
    private function resolveDraftImage(Site $site, string $pageType): ?array
    {
        $selection = SiteDraftAssetSelection::query()
            ->where('site_id', $site->id)
            ->where('family', 'hero')
            ->where('page_type', $pageType)
            ->where('slot', 'hero')
            ->first();

        if ($selection === null || $selection->version_id === null) {
            return null;
        }

        $version = HeroVersion::query()
            ->where('site_id', $site->id)
            ->find($selection->version_id);

        if ($version === null) {
            return null;
        }

        return [
            $version,
            $this->renderedImageUrl($site, $version->url, $version->watermark_url),
            $selection->placement ?? $version->placement ?? [],
            'draft_selection',
        ];
    }

    private function activeImage(Site $site, string $pageType): ?HeroVersion
    {
        return HeroVersion::query()
            ->where('site_id', $site->id)
            ->where('page_type', $pageType)
            ->where('slot', 'hero')
            ->where('is_active', true)
            ->first();
    }

    /**
     * @return array{0: HeroVideoVersion|null, 1: string|null}
     */
    private function resolveVideo(Site $site, GeneratedPage $page, bool $useDraftAssets): array
    {
        if ((string) $page->page_type !== 'home') {
            return [null, null];
        }

        if ($useDraftAssets) {
            $selection = SiteDraftAssetSelection::query()
                ->where('site_id', $site->id)
                ->where('family', 'hero_video')
                ->where('page_type', 'home')
                ->where('slot', 'hero')
                ->first();

            if ($selection !== null) {
                if ($selection->mode === 'off' || $selection->version_id === null) {
                    return [null, null];
                }

                $version = HeroVideoVersion::query()
                    ->where('site_id', $site->id)
                    ->find($selection->version_id);

                return $version !== null && Storage::disk('s3')->exists($version->s3_key)
                    ? [$version, $version->url()]
                    : [null, null];
            }
        }

        if (! $site->home_hero_video_enabled) {
            return [null, null];
        }

        $videoKey = is_string($site->home_hero_video_path) && $site->home_hero_video_path !== ''
            ? $site->home_hero_video_path
            : 'dev-previews/'.$site->id.'/hero-home-video.mp4';

        if (! Storage::disk('s3')->exists($videoKey)) {
            return [null, null];
        }

        $version = HeroVideoVersion::query()
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->where('s3_key', $videoKey)
            ->first();

        return [$version, Storage::disk('s3')->url($videoKey)];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveScene(Site $site, GeneratedPage $page, bool $useDraftAssets): ?array
    {
        if ((string) $page->page_type !== 'home') {
            return null;
        }

        $scene = $site->home_hero_scene;
        if ($useDraftAssets && $site->home_hero_scene_draft !== null) {
            $draft = $site->home_hero_scene_draft;
            $scene = ($draft['enabled'] ?? false) ? array_diff_key($draft, ['enabled' => true]) : null;
        }

        return is_array($scene) && ! empty($scene['slides']) ? $scene : null;
    }

    private function renderedImageUrl(Site $site, ?string $url, ?string $watermarkUrl): ?string
    {
        $profile = $site->businessProfile?->profile_data ?? [];
        if (array_key_exists('watermark_enabled', $profile)) {
            $watermarkEnabled = (bool) ($profile['watermark_enabled'] ?? true);
        } else {
            $snapshot = $site->previews()->latest()->first()?->snapshot ?? [];
            $watermarkEnabled = (bool) ($snapshot['watermark_enabled'] ?? true);
        }

        return $watermarkEnabled && $watermarkUrl !== null && $watermarkUrl !== '' ? $watermarkUrl : $url;
    }
}
