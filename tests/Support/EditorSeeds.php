<?php

namespace Tests\Support;

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\LogoConcept;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\SiteMedia;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

final class EditorSeeds
{
    /**
     * Write operations that return a job_ref and have changed nothing yet.
     *
     * @var list<string>
     */
    public const ASYNC_OPERATIONS = [
        'generate_image',
        'regenerate_hero',
        'generate_logo_concepts',
        'generate_hero_video',
        'render_preview',
    ];

    /**
     * 4×4 PNG used by the existing upload ingest tests.
     */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAQAAAAEAQMAAACTPww9AAAAIGNIUk0AAHomAACAhAAA+gAAAIDoAAB1MAAA6mAAADqYAAAXcJy6UTwAAAAGUExURf8AAP///0EdNBEAAAABYktHRAH/Ai3eAAAAB3RJTUUH6ggcBTkHx5/rUAAAAAtJREFUCNdjYIAAAAAIAAEvIN0xAAAAAElFTkSuQmCC';

    /**
     * @return array{0: User, 1: Site, 2: GeneratedPage}
     */
    public static function site(): array
    {
        $actor = User::factory()->staff(AgentRole::Agent)->create();
        $site = Site::factory()->create(['created_by_user_id' => $actor->id]);
        $content = ['sections' => [
            ['type' => 'hero', 'title' => 'A'],
            ['type' => 'cta', 'title' => 'Call us'],
        ]];
        $page = GeneratedPage::create([
            'site_id' => $site->id,
            'page_type' => 'home',
            'content_data' => $content,
            'sort_order' => 0,
            'version' => 1,
            'status' => PageStatus::Published,
        ]);
        $revision = PageRevision::factory()->for($page, 'page')->create(['content_data' => $content]);
        $page->update(['published_revision_id' => $revision->id]);

        return [$actor, $site, $page->fresh()];
    }

    /**
     * Home page whose stored_index 0 is a hero (with a background_image field), plus a CTA
     * and a contact form so structure / form writes have a valid target.
     *
     * @return array{0: User, 1: Site, 2: GeneratedPage}
     */
    public static function homeWithHero(): array
    {
        $actor = User::factory()->staff(AgentRole::Agent)->create();
        $site = Site::factory()->create(['created_by_user_id' => $actor->id]);
        $content = ['sections' => [
            ['type' => 'hero', 'title' => 'Welcome', 'subtitle' => 'Local trades', 'background_image' => null],
            ['type' => 'cta', 'title' => 'Call us'],
            ['type' => 'contact_form', 'title' => 'Contact us', 'submit_label' => 'Send', 'fields' => []],
        ]];
        $page = GeneratedPage::create([
            'site_id' => $site->id,
            'page_type' => 'home',
            'content_data' => $content,
            'sort_order' => 0,
            'version' => 1,
            'status' => PageStatus::Published,
        ]);
        $revision = PageRevision::factory()->for($page, 'page')->create(['content_data' => $content]);
        $page->update(['published_revision_id' => $revision->id]);

        return [$actor, $site, $page->fresh()];
    }

    public static function pngBase64(): string
    {
        return self::PNG_BASE64;
    }

    public static function activeHeroCount(Site $site): int
    {
        return HeroVersion::query()
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->count();
    }

    /**
     * List this site in editor.exposure.internal_sites — the same config key ToolExposure
     * reads — so agent-channel calls reach operations the sandbox default excludes.
     * Does not change the default set; an unlisted site stays sandbox.
     */
    public static function exposeAsInternal(Site $site): void
    {
        config(['editor.exposure.internal_sites' => (string) $site->id]);
    }

    /**
     * Enable both editor flags, fake the queue, and dispatch the named operation
     * with the smallest valid input. Returns an ok result or a typed failure; never throws.
     */
    public static function invokeMinimally(string $op, Site $site, ?GeneratedPage $page, User $user): OperationResult
    {
        config(['editor.operations.enabled' => true, 'editor.agent_tools.enabled' => true]);
        Queue::fake();
        Bus::fake();
        Storage::fake(config('filesystems.default'));

        if (function_exists('test')) {
            try {
                test()->withoutVite();
            } catch (Throwable) {
            }
        }

        try {
            return app(EditorOperations::class)->run(
                new EditorContext($user, $site, ActorChannel::Webmcp),
                $op,
                self::minimalInput($op, $site, $page?->fresh()),
            );
        } catch (Throwable $exception) {
            report($exception);

            return OperationResult::fail(
                'internal',
                'Unexpected error.',
                app(EditorStateFactory::class)->for($site, $page?->fresh()),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function run(User $user, Site $site, string $op, array $input, ActorChannel $channel = ActorChannel::Webmcp): OperationResult
    {
        config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]); // every wave-1 test runs with the flag on; the gate tests toggle it themselves

        return app(EditorOperations::class)->run(
            new EditorContext($user, $site, $channel),
            $op,
            $input,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function minimalInput(string $op, Site $site, ?GeneratedPage $page): array
    {
        $revisionBase = $page?->draft_revision_id ?? $page?->published_revision_id;
        $epoch = (int) ($page?->structure_epoch ?? 0);
        $compositionRevision = (int) (SiteDraft::query()->where('site_id', $site->id)->value('admin_revision') ?? 0);
        $sections = self::currentSections($page);
        $lastIndex = max(count($sections) - 1, 0);
        $formIndex = self::firstIndexOfType($sections, ['contact_form', 'lead_form']) ?? $lastIndex;
        $removableIndex = self::firstIndexOfType($sections, ['cta', 'contact_form', 'trust']) ?? $lastIndex;

        return match ($op) {
            'edit_field' => [
                'page_id' => $page?->id,
                'stored_index' => 0,
                'field_path' => 'title',
                'value' => 'Edited title',
                'revision_base' => $revisionBase,
                'structure_epoch' => $epoch,
            ],
            'add_section' => [
                'page_id' => $page?->id,
                'type' => 'trust',
                'position' => 1,
                'revision_base' => $revisionBase,
                'structure_epoch' => $epoch,
            ],
            'remove_section' => [
                'page_id' => $page?->id,
                'stored_index' => $removableIndex,
                'revision_base' => $revisionBase,
                'structure_epoch' => $epoch,
            ],
            'move_section' => [
                'page_id' => $page?->id,
                'from' => $lastIndex,
                'to' => 0,
                'revision_base' => $revisionBase,
                'structure_epoch' => $epoch,
            ],
            'set_variant' => [
                'page_id' => $page?->id,
                'stored_index' => 0,
                'variant' => null,
                'revision_base' => $revisionBase,
                'structure_epoch' => $epoch,
            ],
            'update_form' => [
                'page_id' => $page?->id,
                'stored_index' => $formIndex,
                'fields' => [],
                'revision_base' => $revisionBase,
            ],
            'update_page_settings' => [
                'page_id' => $page?->id,
                'meta_title' => 'Distinct seeded meta title',
                'revision_base' => $revisionBase,
            ],
            'upload_image' => [
                'data_base64' => self::pngBase64(),
                'composition_revision' => $compositionRevision,
            ],
            'generate_image' => [
                'page_id' => $page?->id,
                'stored_index' => 0,
                'field_path' => 'background_image',
                'composition_revision' => $compositionRevision,
            ],
            'regenerate_hero' => [
                'page_type' => 'home',
                'composition_revision' => $compositionRevision,
            ],
            'generate_logo_concepts' => [
                'composition_revision' => $compositionRevision,
            ],
            'restore_image_version' => [
                'scope' => 'hero',
                'version_id' => self::heroVersionId($site),
                'composition_revision' => $compositionRevision,
                'page_type' => 'home',
                'slot' => 'hero',
            ],
            'select_logo' => [
                'concept_id' => self::logoConceptId($site),
                'composition_revision' => $compositionRevision,
            ],
            'seed_product_reviews' => [
                'composition_revision' => $compositionRevision,
                'reviews' => [[
                    'product_slug' => 'missing-product',
                    'rating' => 5,
                    'title' => 'Seeded',
                    'body' => 'A seeded review.',
                    'author_name' => 'Seed',
                ]],
            ],
            'set_hero_copy_style' => [
                'hero_copy_style' => 'panel',
                'composition_revision' => $compositionRevision,
            ],
            'set_shop_index_blocks' => [
                'blocks' => [
                    ['source' => 'newest', 'limit' => 4, 'layout' => 'grid', 'heading' => 'Products'],
                ],
                'blocks_revision' => \App\Services\Shop\ShopIndexBlockSettings::revision($site),
                'composition_revision' => $compositionRevision,
            ],
            'set_fulfilment' => [
                'fulfilment' => [
                    'delivery' => [
                        'enabled' => true,
                        'label' => 'Local delivery',
                        'zones' => [[
                            'name' => 'Inner',
                            'prefixes' => ['SW1A'],
                            'fee_cents' => 400,
                            'lead_time' => 'next day',
                        ]],
                    ],
                    'collect' => ['enabled' => false],
                    'shipping' => ['enabled' => true, 'note' => 'Nationwide'],
                    'widget' => ['prompt' => 'Check delivery to your postcode', 'remember_days' => 30],
                ],
                'composition_revision' => $compositionRevision,
            ],
            'set_nav_container' => [
                'nav_container_style' => 'pill',
                'nav_container_fill' => 'surface',
                'composition_revision' => $compositionRevision,
            ],
            'set_nav_label' => [
                'page_id' => $page?->id,
                'label' => 'Nav Label X',
                'composition_revision' => self::ensureNavItem($site, $page),
            ],
            'set_theme_tokens' => [
                'tokens' => ['color-band' => '#f7f2ea'],
                'composition_revision' => $compositionRevision,
            ],
            'save_theme_token_preset' => [
                'name' => 'seeded-preset',
                'composition_revision' => $compositionRevision,
            ],
            'apply_theme_token_preset' => [
                'name' => 'missing-preset',
                'composition_revision' => $compositionRevision,
            ],
            'list_theme_token_presets' => [],
            'set_section_style' => [
                'page_id' => $page?->id,
                'section_id' => (string) (($sections[0]['id'] ?? '') !== '' ? $sections[0]['id'] : 'missing-section'),
                'tokens' => ['color-band' => '#f7f2ea'],
                'revision_base' => $revisionBase,
                'structure_epoch' => $epoch,
            ],
            'restore_media_version' => [
                'page_id' => $page?->id,
                'stored_index' => 0,
                'field_path' => 'background_image',
                'media_id' => self::siteMediaId($site),
                'revision_base' => $revisionBase,
                'structure_epoch' => $epoch,
            ],
            'list_image_versions' => [
                'scope' => 'hero',
                'page_type' => 'home',
                'slot' => 'hero',
            ],
            'get_page_structure' => [
                'page_id' => $page?->id,
            ],
            'get_brand_context' => [],
            'publish_summary' => [],
            'get_job_status' => [
                'job_ref' => 'missing-job-ref',
            ],
            'get_video_state' => [],
            'manage_video' => [
                'action' => 'pause',
                'composition_revision' => $compositionRevision,
            ],
            default => [],
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function currentSections(?GeneratedPage $page): array
    {
        if ($page === null) {
            return [];
        }

        $revisionId = $page->draft_revision_id ?? $page->published_revision_id;
        $content = $revisionId
            ? (PageRevision::query()->find($revisionId)?->content_data ?? $page->content_data ?? [])
            : ($page->content_data ?? []);
        $sections = $content['sections'] ?? [];

        return is_array($sections) ? array_values($sections) : [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @param  list<string>  $types
     */
    private static function firstIndexOfType(array $sections, array $types): ?int
    {
        foreach ($sections as $index => $section) {
            if (in_array($section['type'] ?? null, $types, true)) {
                return $index;
            }
        }

        return null;
    }

    private static function ensureNavItem(Site $site, ?GeneratedPage $page): int
    {
        $draft = app(\App\Services\Site\CompositionService::class)->getOrCreateDraft($site);
        $revision = (int) ($draft->admin_revision ?? 0);

        if ($page === null) {
            return $revision;
        }

        $composition = $draft->composition;
        $items = is_array($composition['nav']['items'] ?? null) ? $composition['nav']['items'] : [];

        foreach ($items as $item) {
            if (is_array($item) && (int) ($item['page_id'] ?? 0) === $page->id) {
                return $revision;
            }

            foreach (is_array($item['children'] ?? null) ? $item['children'] : [] as $child) {
                if (is_array($child) && (int) ($child['page_id'] ?? 0) === $page->id) {
                    return $revision;
                }
            }
        }

        $items[] = [
            'type' => 'page',
            'page_id' => $page->id,
            'label' => $page->nav_label ?: 'Home',
        ];
        $composition['nav']['items'] = $items;
        $draft->composition = $composition;
        $draft->save();

        return $revision;
    }

    private static function heroVersionId(Site $site): int
    {
        $existing = HeroVersion::query()
            ->where('site_id', $site->id)
            ->where('page_type', 'home')
            ->where('slot', 'hero')
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return HeroVersion::factory()->for($site)->create([
            'page_type' => 'home',
            'slot' => 'hero',
        ])->id;
    }

    private static function logoConceptId(Site $site): int
    {
        $existing = LogoConcept::query()->where('site_id', $site->id)->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return LogoConcept::factory()->for($site)->create()->id;
    }

    private static function siteMediaId(Site $site): int
    {
        $existing = SiteMedia::query()->where('site_id', $site->id)->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return SiteMedia::factory()->for($site)->create()->id;
    }
}
