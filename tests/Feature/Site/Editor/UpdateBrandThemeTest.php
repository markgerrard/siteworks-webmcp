<?php

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\LogoConcept;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\ThemeResolver;

beforeEach(function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true, 'editor.agent_approval.enabled' => false]);

    $this->seedSite = function () {
        $user = User::factory()->staff(AgentRole::Agent)->create();
        $site = Site::factory()->create([
            'created_by_user_id' => $user->id,
            'theme' => 'trades-bold',
            'business_name' => 'Test Co',
        ]);
        $content = ['sections' => [
            ['type' => 'hero', 'title' => 'Welcome'],
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
        SiteDraft::create([
            'site_id' => $site->id,
            'composition' => ['theme' => []],
            'admin_revision' => 1,
            'updated_by_user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return [$user, $site->fresh(), $page->fresh()];
    };

    $this->runEditor = function (User $user, Site $site, array $input): OperationResult {
        return app(EditorOperations::class)->run(
            new EditorContext($user, $site, ActorChannel::Webmcp),
            'update_brand_theme',
            $input,
        );
    };
});

it('writes hex colour override tokens into composition theme', function () {
    [$user, $site] = ($this->seedSite)();

    $result = ($this->runEditor)($user, $site, [
        'tokens' => ['primary' => '#c1121f', 'surface_alt' => '#ffffff', 'text_muted' => '#4b5563'],
        'composition_revision' => 1,
    ]);

    expect($result->ok)->toBeTrue();

    $draft = SiteDraft::where('site_id', $site->id)->first();
    expect($draft)->not()->toBeNull()
        ->and($draft->composition['theme']['primary_override'] ?? null)->toBe('#c1121f');
    expect((int) $draft->admin_revision)->toBe(2);
});

it('rejects a non-hex colour token value with validation', function () {
    [$user, $site] = ($this->seedSite)();

    $result = ($this->runEditor)($user, $site, [
        'tokens' => ['primary' => 'not-a-color'],
        'composition_revision' => 1,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation');

    $draft = SiteDraft::where('site_id', $site->id)->first();
    expect($draft->composition['theme']['primary_override'] ?? null)->toBeNull();
    expect((int) $draft->admin_revision)->toBe(1);
});

it('rejects an unknown token key with validation, not silent drop', function () {
    [$user, $site] = ($this->seedSite)();

    $result = ($this->runEditor)($user, $site, [
        'tokens' => ['band' => '#c1121f'],
        'composition_revision' => 1,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation');

    $draft = SiteDraft::where('site_id', $site->id)->first();
    expect((int) $draft->admin_revision)->toBe(1);
});

it('writes allowlisted font overrides', function () {
    [$user, $site] = ($this->seedSite)();

    $result = ($this->runEditor)($user, $site, [
        'tokens' => ['surface_alt' => '#ffffff', 'text_muted' => '#4b5563'],
        'fonts' => ['display' => 'fraunces', 'body' => 'inter'],
        'composition_revision' => 1,
    ]);

    expect($result->ok)->toBeTrue();

    $draft = SiteDraft::where('site_id', $site->id)->first();
    expect($draft->composition['theme']['display_font_override'] ?? null)->toBe('fraunces')
        ->and($draft->composition['theme']['body_font_override'] ?? null)->toBe('inter');
});

it('writes the soft brand section scheme without changing the design brief', function () {
    [$user, $site] = ($this->seedSite)();
    $beforeBrief = $site->design_brief;

    $result = ($this->runEditor)($user, $site, [
        'brand_section_scheme' => 'soft',
        'composition_revision' => 1,
    ]);

    expect($result->ok)->toBeTrue();
    $draft = SiteDraft::where('site_id', $site->id)->firstOrFail();
    expect($draft->composition['theme']['brand_section_scheme_override'] ?? null)->toBe('soft')
        ->and($site->fresh()->design_brief)->toBe($beforeBrief);
});

it('rejects an unknown brand section scheme', function () {
    [$user, $site] = ($this->seedSite)();

    $result = ($this->runEditor)($user, $site, [
        'brand_section_scheme' => 'automatic',
        'composition_revision' => 1,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation');
});

it('clears the brand section override when the agent selects bold', function () {
    [$user, $site] = ($this->seedSite)();
    $draft = SiteDraft::where('site_id', $site->id)->firstOrFail();
    $composition = $draft->composition;
    $composition['theme']['brand_section_scheme_override'] = 'soft';
    $draft->update(['composition' => $composition]);

    $result = ($this->runEditor)($user, $site, [
        'brand_section_scheme' => 'bold',
        'composition_revision' => 1,
    ]);

    expect($result->ok)->toBeTrue();
    $theme = SiteDraft::where('site_id', $site->id)->firstOrFail()->composition['theme'];
    expect($theme)->not->toHaveKey('brand_section_scheme_override');
});

it('rejects a display font not in DesignBrief::DISPLAY_FONTS', function () {
    [$user, $site] = ($this->seedSite)();

    $result = ($this->runEditor)($user, $site, [
        'fonts' => ['display' => 'comic-sans'],
        'composition_revision' => 1,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation');
});

it('rejects a body font not in DesignBrief::BODY_FONTS', function () {
    [$user, $site] = ($this->seedSite)();

    $result = ($this->runEditor)($user, $site, [
        'fonts' => ['body' => 'comic-sans'],
        'composition_revision' => 1,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation');
});

it('blocks a palette where text/surface is below 4.5', function () {
    [$user, $site] = ($this->seedSite)();

    $result = ($this->runEditor)($user, $site, [
        'tokens' => ['surface' => '#ffffff', 'text' => '#cccccc'],
        'composition_revision' => 1,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation');

    $draft = SiteDraft::where('site_id', $site->id)->first();
    expect($draft->composition['theme']['text_override'] ?? null)->toBeNull();
    expect((int) $draft->admin_revision)->toBe(1);
});

it('blocks a palette where text_muted/surface is below 4.5', function () {
    [$user, $site] = ($this->seedSite)();

    $result = ($this->runEditor)($user, $site, [
        'tokens' => ['surface' => '#ffffff', 'text_muted' => '#d0d0d0'],
        'composition_revision' => 1,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation');

    $draft = SiteDraft::where('site_id', $site->id)->first();
    expect($draft->composition['theme']['text_muted_override'] ?? null)->toBeNull();
    expect((int) $draft->admin_revision)->toBe(1);
});

it('blocks a palette where text_muted/surface_alt is below 4.5', function () {
    [$user, $site] = ($this->seedSite)();

    $result = ($this->runEditor)($user, $site, [
        'tokens' => ['surface_alt' => '#e8e8e8', 'text_muted' => '#d0d0d0'],
        'composition_revision' => 1,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation');

    $draft = SiteDraft::where('site_id', $site->id)->first();
    expect($draft->composition['theme']['text_muted_override'] ?? null)->toBeNull();
    expect((int) $draft->admin_revision)->toBe(1);
});

it('warns with contrast_below_aaa when text/surface is between 4.5 and 7.0', function () {
    [$user, $site] = ($this->seedSite)();

    $result = ($this->runEditor)($user, $site, [
        'tokens' => ['surface' => '#ffffff', 'text' => '#666666', 'surface_alt' => '#f0f0f0', 'text_muted' => '#4b5563'],
        'composition_revision' => 1,
    ]);

    expect($result->ok)->toBeTrue();

    $warnings = $result->receipt->warnings;
    $aaaWarning = collect($warnings)->first(fn ($w) => $w['code'] === 'contrast_below_aaa');
    expect($aaaWarning)->not->toBeNull()
        ->and($aaaWarning['severity'])->toBe('warn');
});

it('warns on derived on-tokens below 4.5 but exempts text_muted_on_alt', function () {
    [$user, $site] = ($this->seedSite)();

    $result = ($this->runEditor)($user, $site, [
        'tokens' => ['primary' => '#7c9eb2', 'surface_alt' => '#f0f0f0', 'text_muted' => '#4b5563'],
        'composition_revision' => 1,
    ]);

    expect($result->ok)->toBeTrue();

    $warnings = $result->receipt->warnings;
    foreach ($warnings as $w) {
        if ($w['code'] === 'contrast_below_aa' && ($w['path'] ?? '') === 'text_muted_on_alt') {
            $this->fail('text_muted_on_alt must be exempt from contrast_below_aa');
        }
    }
});

it('accepts a palette where all pairs pass 4.5', function () {
    [$user, $site] = ($this->seedSite)();

    $result = ($this->runEditor)($user, $site, [
        'tokens' => ['primary' => '#1e40af', 'surface' => '#ffffff', 'text' => '#1a1a1a', 'surface_alt' => '#f0f0f0', 'text_muted' => '#4b5563'],
        'composition_revision' => 1,
    ]);

    expect($result->ok)->toBeTrue();

    $draft = SiteDraft::where('site_id', $site->id)->first();
    expect($draft->composition['theme']['primary_override'] ?? null)->toBe('#1e40af')
        ->and($draft->composition['theme']['text_override'] ?? null)->toBe('#1a1a1a');
    expect((int) $draft->admin_revision)->toBe(2);
});

it('never writes to sites.design_brief', function () {
    [$user, $site] = ($this->seedSite)();

    $beforeBrief = Site::where('id', $site->id)->value('design_brief');

    $result = ($this->runEditor)($user, $site, [
        'tokens' => ['primary' => '#c1121f', 'surface_alt' => '#ffffff', 'text_muted' => '#4b5563'],
        'composition_revision' => 1,
    ]);

    expect($result->ok)->toBeTrue();

    $afterBrief = Site::where('id', $site->id)->value('design_brief');
    expect($afterBrief)->toBe($beforeBrief);
});

it('requires composition_revision', function () {
    [$user, $site] = ($this->seedSite)();

    $result = ($this->runEditor)($user, $site, [
        'tokens' => ['primary' => '#c1121f'],
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation');
});

it('rejects stale composition_revision', function () {
    [$user, $site] = ($this->seedSite)();

    $result = ($this->runEditor)($user, $site, [
        'tokens' => ['primary' => '#c1121f'],
        'composition_revision' => 999,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('stale_revision');
});

it('does not mutate public rendering columns per drafts law', function () {
    [$user, $site, $page] = ($this->seedSite)();

    $beforePublishedRev = $page->fresh()->published_revision_id;
    $beforeHeroActive = HeroVersion::where('site_id', $site->id)->where('is_active', true)->count();
    $beforeLogoSelected = LogoConcept::where('site_id', $site->id)->where('is_selected', true)->count();
    $beforeVideoEnabled = Site::where('id', $site->id)->value('home_hero_video_enabled');

    $result = ($this->runEditor)($user, $site, [
        'tokens' => ['primary' => '#c1121f', 'surface_alt' => '#ffffff', 'text_muted' => '#4b5563'],
        'composition_revision' => 1,
    ]);

    expect($result->ok)->toBeTrue();

    $page->refresh();
    expect($page->published_revision_id)->toBe($beforePublishedRev);
    expect(HeroVersion::where('site_id', $site->id)->where('is_active', true)->count())->toBe($beforeHeroActive);
    expect(LogoConcept::where('site_id', $site->id)->where('is_selected', true)->count())->toBe($beforeLogoSelected);
    expect(Site::where('id', $site->id)->value('home_hero_video_enabled'))->toBe($beforeVideoEnabled);
});
