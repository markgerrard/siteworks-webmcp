<?php

namespace App\Services\Site\Editor\Operations;

use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\LogoConcept;
use App\Models\Site;
use App\Services\Site\BrandImageService;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\DraftAssetSelections;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;

final class GetBrandContextOperation extends BaseOperation
{
    /**
     * @var array<string, array{aspect: string, min_width: int}>
     */
    public const SLOT_SPECS = [
        'hero' => ['aspect' => '16:9', 'min_width' => 1920],
        'section_image' => ['aspect' => '4:3', 'min_width' => 1200],
        'portrait' => ['aspect' => '1:1', 'min_width' => 800],
        'logo' => ['aspect' => 'free', 'min_width' => 512],
    ];

    public function __construct(
        private readonly BrandImageService $brandImages,
        private readonly DraftAssetSelections $draftAssets,
        private readonly EditorStateFactory $states,
    ) {}

    public function name(): string
    {
        return 'get_brand_context';
    }

    public function readOnly(): bool
    {
        return true;
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
        return 'Reads the brand profile as captured (palette, fonts, tone) plus current draft hero and logo URLs. For the EFFECTIVE palette, text-safe contrast colours, and design tokens the renderer actually applies, use get_brand_system instead.';
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
        $site = $ctx->site->loadMissing('businessProfile');
        $profile = is_array($site->businessProfile?->profile_data) ? $site->businessProfile->profile_data : [];
        $brief = is_array($site->design_brief) ? $site->design_brief : [];

        return OperationResult::ok([
            'business_name' => $site->business_name,
            'summary' => self::nullableString($profile['summary'] ?? null),
            'tone' => self::nullableString($profile['tone'] ?? null),
            'palette' => $this->brandImages->effectivePalette($site),
            'fonts' => [
                'display' => self::fontSlug($brief['display_font'] ?? null),
                'body' => self::fontSlug($brief['body_font'] ?? null),
            ],
            'logo' => $this->logoPayload($site),
            'hero' => $this->heroPayload($site),
            'slots' => self::SLOT_SPECS,
        ], $this->states->for($site, null));
    }

    /**
     * @return array{url: string|null, safe_on: string|null}
     */
    private function logoPayload(Site $site): array
    {
        $concept = $this->draftAssets->logoFor($site) ?? $site->selectedLogoConcept;

        if (! $concept instanceof LogoConcept) {
            return ['url' => null, 'safe_on' => null];
        }

        return [
            'url' => $concept->url(),
            'safe_on' => self::safeOn($concept),
        ];
    }

    /**
     * The hero each page will RENDER in draft modes (mirrors PageRenderer's resolution: a page uses its own
     * row when hero_source is `dedicated` or a draft selection exists for it, otherwise the shared service
     * hero), keyed by page_type. The shared service hero is also exposed under `__shared_service_hero`.
     *
     * @return array<string, string>
     */
    private function heroPayload(Site $site): array
    {
        $active = HeroVersion::query()
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->where('slot', 'hero')
            ->orderBy('id')
            ->get()
            ->keyBy('page_type');

        $resolved = function (string $pageType) use ($site, $active): ?string {
            $version = $this->draftAssets->heroFor($site, $pageType, 'hero') ?? $active->get($pageType);
            $url = $version instanceof HeroVersion ? $version->url : null;

            return is_string($url) && $url !== '' ? $url : null;
        };

        $draftedPageTypes = $this->draftAssets->all($site)
            ->where('family', 'hero')
            ->where('slot', 'hero')
            ->pluck('page_type')
            ->all();

        $shared = $resolved('__shared_service_hero');
        $heroes = [];
        if ($shared !== null) {
            $heroes['__shared_service_hero'] = $shared;
        }

        $pages = GeneratedPage::query()
            ->where('site_id', $site->id)
            ->whereNull('archived_at')
            ->orderBy('id') // deterministic key order: Postgres row order is otherwise unspecified
            ->get(['id', 'page_type', 'hero_source']);

        foreach ($pages as $page) {
            $pageType = $page->page_type;
            if (! is_string($pageType) || $pageType === '') {
                continue;
            }
            if ($pageType === 'home') {
                $url = $resolved('home');
            } else {
                $dedicated = ($page->hero_source === 'dedicated' || in_array($pageType, $draftedPageTypes, true))
                    ? $resolved($pageType)
                    : null;
                $url = $dedicated ?? $shared;
            }
            if ($url !== null) {
                $heroes[$pageType] = $url;
            }
        }

        return $heroes;
    }

    private static function safeOn(LogoConcept $concept): ?string
    {
        $readsOnDark = data_get($concept->metadata ?? [], 'reads_on_dark');

        if ($readsOnDark === true) {
            return 'dark';
        }

        if ($readsOnDark === false) {
            return 'light';
        }

        return null;
    }

    private static function fontSlug(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $value : 'inter';
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
