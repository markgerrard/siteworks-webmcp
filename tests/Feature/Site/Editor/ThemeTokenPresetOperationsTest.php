<?php

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Models\Client;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Site;
use App\Models\Site\EditorOperationLog;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\Site\SiteVersionCurrent;
use App\Models\ThemeTokenPreset;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\PageRenderer;
use App\Services\Site\SitePublishService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EditorSeeds;

beforeEach(function () {
    config([
        'editor.agent_tools.enabled' => true,
        'editor.operations.enabled' => true,
        'editor.agent_approval.enabled' => false,
        'editor.agent_tools.roles' => ['staff', 'client'],
    ]);
    $this->withoutVite();
    Queue::fake();
    Storage::fake('s3');
});

/**
 * @return array{0: User, 1: Site, 2: GeneratedPage}
 */
function themeTokenPresetOpSite(): array
{
    $user = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $user->id,
        'theme' => 'trades-bold',
        'business_name' => 'Preset Co',
        'design_brief' => [
            'mood' => 'warm-traditional',
            'display_font' => 'fraunces',
            'body_font' => 'source-sans-3',
            'heading_scale' => 'balanced',
            'spacing_density' => 'balanced',
            'corner_style' => 'soft',
            'palette' => [
                'primary' => '#1f3a5f',
                'accent' => '#8b6b2f',
                'tertiary' => '#f4ede0',
                'surface' => '#ffffff',
                'surface_alt' => '#f8f5ee',
                'border' => '#e4ddcf',
                'text' => '#1a1a1a',
                'text_muted' => '#6b7280',
            ],
        ],
    ]);
    $content = ['sections' => [
        ['id' => '01JHG7KX3MNQRSTVWXYZHERO01', 'type' => 'hero', 'title' => 'Welcome'],
        ['id' => '01JHG7KX3MNQRSTVWXYZCTA001', 'type' => 'cta', 'title' => 'Call us'],
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
    SiteDraft::create([
        'site_id' => $site->id,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold'],
            'homepage_page_id' => $page->id,
        ],
        'admin_revision' => 1,
        'updated_by_user_id' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    Preview::factory()->for($site)->create();
    EditorSeeds::exposeAsInternal($site);

    return [$user, $site->fresh(), $page->fresh()];
}

function runThemeTokenPresetOp(User $user, Site $site, string $operation, array $input, ActorChannel $channel = ActorChannel::Webmcp): OperationResult
{
    return app(EditorOperations::class)->run(
        new EditorContext($user, $site, $channel),
        $operation,
        $input,
    );
}

function seedSiteTokenOverrides(User $user, Site $site, array $tokens): int
{
    $result = runThemeTokenPresetOp($user, $site, 'set_theme_tokens', [
        'tokens' => $tokens,
        'composition_revision' => (int) SiteDraft::where('site_id', $site->id)->value('admin_revision'),
    ]);
    expect($result->ok)->toBeTrue();

    return (int) SiteDraft::where('site_id', $site->id)->value('admin_revision');
}

/**
 * @param  array<string, mixed>  $map
 * @return array<string, mixed>
 */
function sortedTokenMap(array $map): array
{
    ksort($map);

    return $map;
}

/**
 * @param  list<string>  $keys
 * @return list<string>
 */
function sortedTokenKeys(array $keys): array
{
    sort($keys);

    return $keys;
}

it('snapshots the current site token_overrides as a named preset', function () {
    [$user, $site] = themeTokenPresetOpSite();
    $revision = seedSiteTokenOverrides($user, $site, [
        'color-band' => '#f7f2ea',
        'color-text-on-band' => '#1a1a1a',
        'radius-card' => '4px',
    ]);

    $result = runThemeTokenPresetOp($user, $site->fresh(), 'save_theme_token_preset', [
        'name' => 'cream-band',
        'description' => 'Warm cream band on inverted sites',
        'composition_revision' => $revision,
    ]);

    expect($result->ok)->toBeTrue()
        ->and($result->data['name'] ?? null)->toBe('cream-band')
        ->and(sortedTokenMap($result->data['tokens'] ?? []))->toBe([
            'color-band' => '#f7f2ea',
            'color-text-on-band' => '#1a1a1a',
            'radius-card' => '4px',
        ]);

    $preset = ThemeTokenPreset::query()->where('name', 'cream-band')->first();
    expect($preset)->not->toBeNull()
        ->and($preset->description)->toBe('Warm cream band on inverted sites')
        ->and(sortedTokenMap($preset->tokens))->toBe([
            'color-band' => '#f7f2ea',
            'color-text-on-band' => '#1a1a1a',
            'radius-card' => '4px',
        ])
        ->and($preset->created_by_user_id)->toBe($user->id);

    expect((int) SiteDraft::where('site_id', $site->id)->value('admin_revision'))->toBe($revision);

    expect(EditorOperationLog::query()
        ->where('site_id', $site->id)
        ->where('operation', 'save_theme_token_preset')
        ->where('actor_channel', 'webmcp')
        ->where('result_code', 'ok')
        ->exists())->toBeTrue();
});

it('refuses to save when the site has no token_overrides', function () {
    [$user, $site] = themeTokenPresetOpSite();

    $result = runThemeTokenPresetOp($user, $site, 'save_theme_token_preset', [
        'name' => 'empty-set',
        'composition_revision' => 1,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($result->error['message'])->toContain('empty');

    expect(ThemeTokenPreset::query()->count())->toBe(0);
});

it('returns a clear error when the preset name is already taken', function () {
    [$user, $site] = themeTokenPresetOpSite();
    $revision = seedSiteTokenOverrides($user, $site, ['color-band' => '#f7f2ea']);
    ThemeTokenPreset::factory()->create(['name' => 'cream-band']);

    $result = runThemeTokenPresetOp($user, $site->fresh(), 'save_theme_token_preset', [
        'name' => 'cream-band',
        'composition_revision' => $revision,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($result->error['message'])->toContain('cream-band')
        ->and($result->error['fields']['name'] ?? null)->not->toBeNull();

    expect(ThemeTokenPreset::query()->where('name', 'cream-band')->count())->toBe(1);
});

it('applies a preset under bespoke site keys and writes provenance meta', function () {
    Carbon::setTestNow('2026-08-31T12:00:00+00:00');

    [$sourceUser, $source] = themeTokenPresetOpSite();
    $sourceRevision = seedSiteTokenOverrides($sourceUser, $source, [
        'color-band' => '#f7f2ea',
        'color-text-on-band' => '#1a1a1a',
        'radius-card' => '4px',
    ]);
    expect(runThemeTokenPresetOp($sourceUser, $source->fresh(), 'save_theme_token_preset', [
        'name' => 'cream-band',
        'composition_revision' => $sourceRevision,
    ])->ok)->toBeTrue();

    [$targetUser, $target] = themeTokenPresetOpSite();
    $targetRevision = seedSiteTokenOverrides($targetUser, $target, [
        'color-band' => '#111111',
    ]);

    $result = runThemeTokenPresetOp($targetUser, $target->fresh(), 'apply_theme_token_preset', [
        'name' => 'cream-band',
        'composition_revision' => $targetRevision,
    ]);

    expect($result->ok)->toBeTrue()
        ->and(sortedTokenKeys($result->data['filled']))->toBe(['color-text-on-band', 'radius-card'])
        ->and($result->data['skipped'])->toBe(['color-band'])
        ->and(sortedTokenMap($result->data['token_overrides']))->toBe([
            'color-band' => '#111111',
            'color-text-on-band' => '#1a1a1a',
            'radius-card' => '4px',
        ])
        ->and($result->data['token_overrides_meta']['applied_preset'] ?? null)->toBe('cream-band')
        ->and($result->data['token_overrides_meta']['applied_at'] ?? null)->toBe('2026-08-31T12:00:00+00:00');

    $theme = SiteDraft::where('site_id', $target->id)->firstOrFail()->composition['theme'];
    expect(sortedTokenMap($theme['token_overrides']))->toBe([
        'color-band' => '#111111',
        'color-text-on-band' => '#1a1a1a',
        'radius-card' => '4px',
    ])
        ->and($theme['token_overrides_meta']['applied_preset'] ?? null)->toBe('cream-band')
        ->and($theme['token_overrides_meta']['applied_at'] ?? null)->toBe('2026-08-31T12:00:00+00:00');

    expect(EditorOperationLog::query()
        ->where('site_id', $target->id)
        ->where('operation', 'apply_theme_token_preset')
        ->where('result_code', 'ok')
        ->exists())->toBeTrue();
});

it('copies a preset rather than linking it', function () {
    [$sourceUser, $source] = themeTokenPresetOpSite();
    $sourceRevision = seedSiteTokenOverrides($sourceUser, $source, [
        'color-band' => '#f7f2ea',
    ]);
    expect(runThemeTokenPresetOp($sourceUser, $source->fresh(), 'save_theme_token_preset', [
        'name' => 'cream-band',
        'composition_revision' => $sourceRevision,
    ])->ok)->toBeTrue();

    [$targetUser, $target] = themeTokenPresetOpSite();
    expect(runThemeTokenPresetOp($targetUser, $target, 'apply_theme_token_preset', [
        'name' => 'cream-band',
        'composition_revision' => 1,
    ])->ok)->toBeTrue();

    ThemeTokenPreset::query()->where('name', 'cream-band')->firstOrFail()->update([
        'tokens' => ['color-band' => '#000000'],
    ]);

    $theme = SiteDraft::where('site_id', $target->id)->firstOrFail()->composition['theme'];
    expect($theme['token_overrides'])->toBe(['color-band' => '#f7f2ea']);
});

it('publishes applied preset tokens onto the live render', function () {
    [$sourceUser, $source] = themeTokenPresetOpSite();
    $sourceRevision = seedSiteTokenOverrides($sourceUser, $source, [
        'color-band' => '#f7f2ea',
        'color-text-on-band' => '#1a1a1a',
    ]);
    expect(runThemeTokenPresetOp($sourceUser, $source->fresh(), 'save_theme_token_preset', [
        'name' => 'cream-band',
        'composition_revision' => $sourceRevision,
    ])->ok)->toBeTrue();

    [$targetUser, $target, $page] = themeTokenPresetOpSite();
    expect(runThemeTokenPresetOp($targetUser, $target, 'apply_theme_token_preset', [
        'name' => 'cream-band',
        'composition_revision' => 1,
    ])->ok)->toBeTrue();

    app(SitePublishService::class)->publishSite($target->fresh());

    $current = SiteVersionCurrent::where('site_id', $target->id)->firstOrFail();
    expect($current->version_id)->not->toBeNull();

    $html = app(PageRenderer::class)->render($target->fresh(), $page->id, mode: 'public');

    expect($html)->toContain('--color-band: #f7f2ea')
        ->and($html)->toContain('--color-text-on-band: #1a1a1a');
});

it('includes warn-only contrast warnings when apply fills a low-contrast token', function () {
    [$sourceUser, $source] = themeTokenPresetOpSite();
    $sourceRevision = seedSiteTokenOverrides($sourceUser, $source, [
        'color-band' => '#f7f2ea',
    ]);
    expect(runThemeTokenPresetOp($sourceUser, $source->fresh(), 'save_theme_token_preset', [
        'name' => 'cream-band',
        'composition_revision' => $sourceRevision,
    ])->ok)->toBeTrue();

    [$targetUser, $target] = themeTokenPresetOpSite();
    $result = runThemeTokenPresetOp($targetUser, $target, 'apply_theme_token_preset', [
        'name' => 'cream-band',
        'composition_revision' => 1,
    ]);

    expect($result->ok)->toBeTrue();

    $aa = collect($result->receipt->warnings)->first(fn ($warning) => ($warning['code'] ?? null) === 'contrast_below_aa');

    expect($aa)->not->toBeNull()
        ->and($aa['severity'])->toBe('warn')
        ->and($aa['path'] ?? null)->toBe('color-band');
});

it('returns not_found when applying an unknown preset name', function () {
    [$user, $site] = themeTokenPresetOpSite();

    $result = runThemeTokenPresetOp($user, $site, 'apply_theme_token_preset', [
        'name' => 'missing-preset',
        'composition_revision' => 1,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('not_found')
        ->and($result->error['message'])->toContain('missing-preset');
});

it('lists preset names, descriptions, and token counts', function () {
    ThemeTokenPreset::factory()->create([
        'name' => 'cream-band',
        'description' => 'Warm cream band',
        'tokens' => ['color-band' => '#f7f2ea', 'color-text-on-band' => '#1a1a1a'],
    ]);
    ThemeTokenPreset::factory()->create([
        'name' => 'sharp-cards',
        'description' => null,
        'tokens' => ['radius-card' => '2px'],
    ]);

    [$user, $site] = themeTokenPresetOpSite();
    $result = runThemeTokenPresetOp($user, $site, 'list_theme_token_presets', []);

    expect($result->ok)->toBeTrue()
        ->and($result->data['presets'])->toBe([
            [
                'name' => 'cream-band',
                'description' => 'Warm cream band',
                'token_count' => 2,
            ],
            [
                'name' => 'sharp-cards',
                'description' => null,
                'token_count' => 1,
            ],
        ]);
});

it('rejects all three preset ops on the client agent channel', function (string $operation, array $input) {
    $client = Client::factory()->create();
    $clientUser = User::factory()->create(['client_id' => $client->id, 'role' => null]);
    [, $site] = themeTokenPresetOpSite();
    $site->update(['client_id' => $client->id]);

    $result = runThemeTokenPresetOp($clientUser, $site->fresh(), $operation, $input, ActorChannel::Mcp);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('forbidden');
})->with([
    'save' => ['save_theme_token_preset', ['name' => 'cream-band', 'composition_revision' => 1]],
    'apply' => ['apply_theme_token_preset', ['name' => 'cream-band', 'composition_revision' => 1]],
    'list' => ['list_theme_token_presets', []],
]);
