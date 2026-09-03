<?php

namespace App\Services\Site\Editor\Operations;

use App\Models\HeroVideoVersion;
use App\Models\Site;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\DraftAssetSelections;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;
use Illuminate\Support\Facades\Storage;

final class GetVideoStateOperation extends BaseOperation
{
    public function __construct(
        private readonly DraftAssetSelections $selections,
        private readonly EditorStateFactory $states,
    ) {}

    public function name(): string
    {
        return 'get_video_state';
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function wrapInAdminChange(): bool
    {
        return false;
    }

    public function address(): string
    {
        return 'site';
    }

    public function sideEffects(): string
    {
        return 'Lists hero video versions with active and drafted state and persisted probe metadata. reduced_motion_fallback is always none because the hero markup has no prefers-reduced-motion gate. Never probes and never dispatches.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(EditorContext $ctx, array $input): OperationResult
    {
        $site = $ctx->site;
        $drafted = $this->selections->heroVideoFor($site);
        $draftedId = ($drafted !== null && $drafted['mode'] === 'on') ? $drafted['version']?->id : null;

        $versions = HeroVideoVersion::query()
            ->where('site_id', $site->id)
            ->orderByDesc('id')
            ->get()
            ->map(function (HeroVideoVersion $version) use ($site, $draftedId, $ctx): array {
                $entry = $this->describe($version, $site, $draftedId);
                if ($entry['codec'] === null && $entry['duration_secs'] === null) {
                    $ctx->warnings->add(
                        'asset_unreferenced',
                        "Hero video version {$version->id} has not been probed.",
                        "versions.{$version->id}",
                    );
                }

                return $entry;
            })
            ->values()
            ->all();

        return OperationResult::ok([
            'versions' => $versions,
            'reduced_motion_fallback' => 'none',
        ], $this->states->for($site, null));
    }

    /**
     * @return array{
     *     id: int,
     *     active: bool,
     *     drafted: bool,
     *     duration_secs: float|null,
     *     codec: string|null,
     *     width: int|null,
     *     height: int|null,
     *     poster: string|null,
     *     autoplay_eligible: bool|null,
     *     reduced_motion_fallback: 'none'
     * }
     */
    public function describe(HeroVideoVersion $version, Site $site, ?int $draftedId): array
    {
        $metadata = is_array($version->metadata) ? $version->metadata : [];
        $probed = array_key_exists('codec', $metadata) || array_key_exists('duration_secs', $metadata);
        $poster = null;
        if ((bool) $version->is_active && is_string($site->home_hero_video_poster_path) && $site->home_hero_video_poster_path !== '') {
            $poster = Storage::disk('s3')->url($site->home_hero_video_poster_path);
        }

        return [
            'id' => $version->id,
            'active' => (bool) $version->is_active,
            'drafted' => $draftedId !== null && $draftedId === $version->id,
            'duration_secs' => $probed && array_key_exists('duration_secs', $metadata)
                ? (float) $metadata['duration_secs']
                : null,
            'codec' => $probed && is_string($metadata['codec'] ?? null) ? $metadata['codec'] : null,
            'width' => $probed && array_key_exists('width', $metadata) ? (int) $metadata['width'] : null,
            'height' => $probed && array_key_exists('height', $metadata) ? (int) $metadata['height'] : null,
            'poster' => $poster,
            'autoplay_eligible' => $probed ? true : null,
            'reduced_motion_fallback' => 'none',
        ];
    }
}
