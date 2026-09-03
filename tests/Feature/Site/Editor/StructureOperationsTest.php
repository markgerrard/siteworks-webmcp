<?php

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\PublicPageCache;

function seedW1T10StructureSite(?array $sections = null, bool $withRevision = true): array
{
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);

    $user = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $content = ['sections' => $sections ?? [
        ['type' => 'hero', 'title' => 'A'],
        ['type' => 'cta', 'title' => 'B'],
    ]];
    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'content_data' => $content,
        'status' => PageStatus::Published,
    ]);

    if ($withRevision) {
        $revision = PageRevision::factory()->for($page, 'page')->create(['content_data' => $content]);
        $page->update(['published_revision_id' => $revision->id]);
    }

    return [$user, $site, $page->fresh()];
}

function runW1T10(User $user, Site $site, string $operation, array $input): OperationResult
{
    return app(EditorOperations::class)->run(
        new EditorContext($user, $site, ActorChannel::Webmcp),
        $operation,
        $input,
    );
}

function w1t10WriteInput(GeneratedPage $page, array $extra = []): array
{
    $page->refresh();

    return [
        'page_id' => $page->id,
        'revision_base' => $page->draft_revision_id ?? $page->published_revision_id,
        'structure_epoch' => (int) $page->structure_epoch,
        ...$extra,
    ];
}

function w1t10Sections(GeneratedPage $page): array
{
    $page->refresh();
    $revisionId = $page->draft_revision_id ?? $page->published_revision_id;
    $content = PageRevision::query()->findOrFail($revisionId)->content_data;

    return $content['sections'];
}

function w1t10Types(GeneratedPage $page): array
{
    return array_column(w1t10Sections($page), 'type');
}

it('adds trust at position 1, bumps structure_epoch, returns admin-edit html, and leaves published + public cache alone', function () {
    [$user, $site, $page] = seedW1T10StructureSite();
    $published = $page->published_revision_id;
    $cache = app(PublicPageCache::class);
    $generation = $cache->generation($site);

    $result = runW1T10($user, $site, 'add_section', w1t10WriteInput($page, [
        'type' => 'trust',
        'position' => 1,
    ]));

    $page->refresh();
    expect($result->ok)->toBeTrue()
        ->and($result->data['draft_revision_id'])->toBe($page->draft_revision_id)
        ->and($result->data['structure_epoch'])->toBe(1)
        ->and($result->data['html'])->toBeString()->toContain('data-editable')
        ->and($page->structure_epoch)->toBe(1)
        ->and(w1t10Types($page))->toBe(['hero', 'trust', 'cta'])
        ->and($page->published_revision_id)->toBe($published)
        ->and($cache->generation($site))->toBe($generation);
});

it('rejects project_gallery on home as validation', function () {
    [$user, $site, $page] = seedW1T10StructureSite();
    $published = $page->published_revision_id;

    $result = runW1T10($user, $site, 'add_section', w1t10WriteInput($page, [
        'type' => 'project_gallery',
        'position' => 1,
    ]));

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($page->fresh()->draft_revision_id)->toBeNull()
        ->and($page->fresh()->published_revision_id)->toBe($published);
});

it('rejects a second hero as validation', function () {
    [$user, $site, $page] = seedW1T10StructureSite();

    $result = runW1T10($user, $site, 'add_section', w1t10WriteInput($page, [
        'type' => 'hero',
        'position' => 1,
    ]));

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and(w1t10Types($page))->toBe(['hero', 'cta']);
});

it('moves stored index 1 to 0 and reorders', function () {
    [$user, $site, $page] = seedW1T10StructureSite();
    $published = $page->published_revision_id;

    $result = runW1T10($user, $site, 'move_section', w1t10WriteInput($page, [
        'from' => 1,
        'to' => 0,
    ]));

    expect($result->ok)->toBeTrue()
        ->and(w1t10Types($page))->toBe(['cta', 'hero'])
        ->and($page->fresh()->structure_epoch)->toBe(1)
        ->and($page->fresh()->published_revision_id)->toBe($published);
});

it('removes a section and keeps survivors item_ids', function () {
    [$user, $site, $page] = seedW1T10StructureSite();
    $item = ProjectItem::factory()->for($site)->create();
    $content = ['sections' => [
        ['type' => 'hero', 'title' => 'A'],
        ['type' => 'portfolio_strip', 'title' => 'Work', 'item_ids' => [$item->id]],
        ['type' => 'cta', 'title' => 'B'],
    ]];
    PageRevision::query()->whereKey($page->published_revision_id)->update(['content_data' => $content]);
    $page->update(['content_data' => $content]);

    $result = runW1T10($user, $site, 'remove_section', w1t10WriteInput($page, [
        'stored_index' => 0,
    ]));

    $sections = w1t10Sections($page);
    expect($result->ok)->toBeTrue()
        ->and(array_column($sections, 'type'))->toBe(['portfolio_strip', 'cta'])
        ->and($sections[0]['item_ids'])->toBe([$item->id]);
});

it('returns stale_revision when structure_epoch does not match, mentioning structure', function () {
    [$user, $site, $page] = seedW1T10StructureSite();
    $published = $page->published_revision_id;

    $result = runW1T10($user, $site, 'add_section', w1t10WriteInput($page, [
        'type' => 'trust',
        'position' => 1,
        'structure_epoch' => 9,
    ]));

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('stale_revision')
        ->and($result->error['current_revision_id'])->toBe($published)
        ->and($result->error['message'])->toContain('structure')
        ->and($page->fresh()->draft_revision_id)->toBeNull()
        ->and($page->fresh()->published_revision_id)->toBe($published);
});

it('returns stale_revision when revision_base does not match', function () {
    [$user, $site, $page] = seedW1T10StructureSite();
    $published = $page->published_revision_id;

    $result = runW1T10($user, $site, 'add_section', w1t10WriteInput($page, [
        'type' => 'trust',
        'position' => 1,
        'revision_base' => 999_999,
    ]));

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('stale_revision')
        ->and($result->error['current_revision_id'])->toBe($published)
        ->and($page->fresh()->draft_revision_id)->toBeNull()
        ->and($page->fresh()->published_revision_id)->toBe($published);
});

it('returns validation when structure_epoch is missing and never writes', function () {
    [$user, $site, $page] = seedW1T10StructureSite();
    $published = $page->published_revision_id;
    $input = w1t10WriteInput($page, [
        'type' => 'trust',
        'position' => 1,
    ]);
    unset($input['structure_epoch']);

    $result = runW1T10($user, $site, 'add_section', $input);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($page->fresh()->draft_revision_id)->toBeNull()
        ->and($page->fresh()->published_revision_id)->toBe($published)
        ->and($page->fresh()->structure_epoch)->toBe(0);
});

it('returns validation when revision_base is missing and never writes', function () {
    [$user, $site, $page] = seedW1T10StructureSite();
    $input = w1t10WriteInput($page, [
        'type' => 'trust',
        'position' => 1,
    ]);
    unset($input['revision_base']);

    $result = runW1T10($user, $site, 'remove_section', $input + ['stored_index' => 1]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($page->fresh()->draft_revision_id)->toBeNull();
});

it('sets a valid hero variant and rejects an invalid one', function () {
    [$user, $site, $page] = seedW1T10StructureSite();
    $published = $page->published_revision_id;

    $ok = runW1T10($user, $site, 'set_variant', w1t10WriteInput($page, [
        'stored_index' => 0,
        'variant' => 'boxed-left',
    ]));

    expect($ok->ok)->toBeTrue()
        ->and(w1t10Sections($page)[0]['variant'])->toBe('boxed-left')
        ->and($page->fresh()->published_revision_id)->toBe($published);

    $bad = runW1T10($user, $site, 'set_variant', w1t10WriteInput($page, [
        'stored_index' => 0,
        'variant' => 'not-a-variant',
    ]));

    expect($bad->ok)->toBeFalse()
        ->and($bad->error['code'])->toBe('validation')
        ->and(w1t10Sections($page)[0]['variant'])->toBe('boxed-left');
});

it('treats uncatalogued section types as validation, never an exception', function () {
    [$user, $site, $page] = seedW1T10StructureSite();

    $result = runW1T10($user, $site, 'add_section', w1t10WriteInput($page, [
        'type' => 'before_after',
        'position' => 1,
    ]));

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($result->error['message'])->toContain('not addable')
        ->and($page->fresh()->draft_revision_id)->toBeNull();
});

it('returns validation when the page has no revision rows', function () {
    [$user, $site, $page] = seedW1T10StructureSite(withRevision: false);

    $result = runW1T10($user, $site, 'add_section', [
        'page_id' => $page->id,
        'type' => 'trust',
        'position' => 1,
        'revision_base' => 1,
        'structure_epoch' => 0,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($page->fresh()->draft_revision_id)->toBeNull()
        ->and($page->fresh()->published_revision_id)->toBeNull();
});

it('records singleton and max on the Task 10 catalog types and rejects a second hero_compact', function () {
    foreach (['projects_hero', 'project_detail_hero', 'hero_compact', 'seo', 'geo', 'cta_band', 'contact_form'] as $type) {
        expect(config("section_catalog.{$type}.singleton"))->toBeTrue()
            ->and(config("section_catalog.{$type}.max"))->toBe(1);
    }

    [$user, $site, $page] = seedW1T10StructureSite();

    $first = runW1T10($user, $site, 'add_section', w1t10WriteInput($page, [
        'type' => 'hero_compact',
        'position' => 1,
    ]));
    expect($first->ok)->toBeTrue();

    $second = runW1T10($user, $site, 'add_section', w1t10WriteInput($page, [
        'type' => 'hero_compact',
        'position' => 2,
    ]));
    expect($second->ok)->toBeFalse()
        ->and($second->error['code'])->toBe('validation');
});

it('rejects add_section fields outside the catalog initial_fields instead of silently dropping them', function () {
    [$user, $site, $page] = seedW1T10StructureSite();
    $result = runW1T10($user, $site, 'add_section', w1t10WriteInput($page, [
        'type' => 'trust',
        'position' => 1,
        'fields' => ['not_a_real_field' => 'x'],
    ]));
    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and(array_keys($result->error['fields']))->toContain('fields.not_a_real_field')
        ->and(w1t10Types($page->fresh()))->toBe(['hero', 'cta']);
});
