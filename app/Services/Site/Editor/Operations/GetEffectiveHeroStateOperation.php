<?php

namespace App\Services\Site\Editor\Operations;

use App\Enums\HeroVersionSource;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\HeroResolution;
use App\Services\Site\HeroState;

final class GetEffectiveHeroStateOperation extends BaseOperation
{
    public function __construct(
        private readonly HeroResolution $heroResolution,
        private readonly EditorStateFactory $states,
    ) {}

    public function name(): string
    {
        return 'get_effective_hero_state';
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
        return 'Reads published-effective and draft-effective hero state and why that asset won. `section_field` and `placeholder` are reported from the hero section, not from the shared page image map.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'page_id' => ['type' => 'integer'],
                'page_type' => ['type' => 'string'],
                'slot' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(EditorContext $ctx, array $input): OperationResult
    {
        $state = $this->states->for($ctx->site, null);
        $slot = $input['slot'] ?? 'hero';

        if (! is_string($slot) || $slot !== 'hero') {
            return OperationResult::fail('validation', 'slot must be hero.', $state, [
                'fields' => ['slot' => ['must be hero']],
            ]);
        }

        $page = $this->resolvePage($ctx, $input);

        if ($page === null) {
            return OperationResult::fail('not_found', 'Page not found.', $state);
        }

        $state = $this->states->for($ctx->site, $page);
        $published = $this->present(
            $this->heroResolution->for($ctx->site, $page, false),
            $this->heroSection($page, false),
        );
        $draft = $this->present(
            $this->heroResolution->for($ctx->site, $page, true),
            $this->heroSection($page, true),
        );

        return OperationResult::ok([
            'published' => $published,
            'draft' => $draft,
            'differs' => $published !== $draft,
        ], $state);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolvePage(EditorContext $ctx, array $input): ?GeneratedPage
    {
        $query = GeneratedPage::query()
            ->where('site_id', $ctx->site->id)
            ->whereNull('archived_at');

        $pageId = $input['page_id'] ?? null;

        if ($pageId !== null) {
            if (! is_int($pageId) && ! (is_string($pageId) && preg_match('/^[1-9][0-9]*$/', $pageId) === 1)) {
                return null;
            }

            return $query->find((int) $pageId);
        }

        $pageType = $input['page_type'] ?? 'home';
        if (! is_string($pageType) || $pageType === '') {
            return null;
        }

        return $query->where('page_type', $pageType)->orderBy('id')->first();
    }

    /**
     * Reporting-only mapping of HeroState plus the two reasons decided in
     * hero.blade.php. This method must not copy section.background_image
     * into url — that leak is what changed lead_form / service_area_card /
     * hero_compact when Task 7 put those reasons in the shared map.
     *
     * @param  array<string, mixed>|null  $heroSection
     * @return array{
     *     mode: string,
     *     source: string,
     *     version_id: int|null,
     *     url: string|null,
     *     reason: string,
     *     placement: array<string, mixed>,
     *     image_version_id: int|null,
     *     image_url: string|null
     * }
     */
    private function present(HeroState $state, ?array $heroSection): array
    {
        $reason = $state->reason;
        $mode = $state->mode;
        $url = $state->mode === 'video' ? $state->video_url : $state->image_url;
        $versionId = $state->mode === 'video' ? $state->video_version_id : $state->image_version_id;
        $imageUrl = $state->image_url;

        if ($state->mode === 'scene') {
            $url = null;
        }

        if ($heroSection !== null && $state->mode !== 'video' && $state->mode !== 'scene') {
            if (! empty($heroSection['placeholder'])) {
                $reason = 'placeholder';
            } elseif ($reason === 'none' && $this->sectionHasBackground($heroSection)) {
                $reason = 'section_field';
                $mode = 'image';
                $url = null;
                $imageUrl = null;
            }
        }

        return [
            'mode' => $mode,
            'source' => $this->source($state, $reason),
            'version_id' => $versionId,
            'url' => $url,
            'reason' => $reason,
            'placement' => $state->placement,
            'image_version_id' => $state->image_version_id,
            'image_url' => $imageUrl,
        ];
    }

    private function source(HeroState $state, string $reason): string
    {
        if ($state->image_version_id !== null) {
            $source = HeroVersion::query()->whereKey($state->image_version_id)->value('source');
            if ($source instanceof HeroVersionSource) {
                return $source->value;
            }
            if (is_string($source) && $source !== '') {
                return $source;
            }
        }

        return match ($reason) {
            'snapshot_fallback' => 'snapshot',
            'section_field' => 'section_field',
            'placeholder' => 'placeholder',
            'video_mode_active' => 'video',
            'scene_active' => 'scene',
            default => 'none',
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function heroSection(GeneratedPage $page, bool $useDraft): ?array
    {
        $page->loadMissing(['draftRevision', 'publishedRevision']);
        $content = $useDraft
            ? ($page->draftRevision?->content_data ?? $page->publishedRevision?->content_data ?? $page->content_data ?? [])
            : ($page->publishedRevision?->content_data ?? $page->content_data ?? []);

        foreach (is_array($content['sections'] ?? null) ? $content['sections'] : [] as $section) {
            if (is_array($section) && ($section['type'] ?? null) === 'hero') {
                return $section;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private function sectionHasBackground(array $section): bool
    {
        $background = $section['background_image'] ?? null;

        if (is_string($background) && $background !== '') {
            return true;
        }

        if (is_array($background)) {
            $url = $background['url'] ?? $background['watermark_url'] ?? null;

            return is_string($url) && $url !== '';
        }

        return false;
    }
}
