<?php

use App\Enums\AgentRole;
use App\Exceptions\Site\StaleRevisionException;
use App\Exceptions\Site\StaleStructureException;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\PageService;
use App\Services\Site\SectionSchema;

beforeEach(function () {
    config([
        'editor.agent_tools.enabled' => true,
        'editor.operations.enabled' => true,
        'editor.agent_approval.enabled' => false,
    ]);
    $this->withoutVite();

    $this->seedEditorSite = function (array $hero = []): array {
        $user = User::factory()->staff(AgentRole::Agent)->create();
        $site = Site::factory()->create(['created_by_user_id' => $user->id]);
        $heroSection = array_merge([
            'type' => 'hero',
            'title' => 'Plumbing Partners — Plumbing Experts',
        ], $hero);
        $content = ['sections' => [
            $heroSection,
            ['type' => 'cta', 'title' => 'Call us'],
        ]];
        $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'content_data' => $content]);
        $revision = PageRevision::factory()->for($page, 'page')->create(['content_data' => $content]);
        $page->update(['published_revision_id' => $revision->id]);

        return ['user' => $user, 'site' => $site, 'page' => $page->fresh(), 'content' => $content];
    };

    $this->runOp = function (User $user, Site $site, string $operation, array $input): OperationResult {
        return app(EditorOperations::class)->run(
            new EditorContext($user, $site, ActorChannel::Webmcp),
            $operation,
            $input,
        );
    };

    $this->secondPlumbingRange = function (string $title): array {
        $needle = 'Plumbing';
        $first = mb_stripos($title, $needle);
        expect($first)->not->toBeFalse();
        $second = mb_stripos($title, $needle, $first + 1);
        expect($second)->not->toBeFalse();

        return ['start' => $second, 'length' => mb_strlen($needle)];
    };
});

it('writes title and accent_ranges in a single revision', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)();
    $before = PageRevision::query()->where('page_id', $page->id)->count();
    $title = 'Boiler repairs in Bristol';
    $range = ['start' => 0, 'length' => mb_strlen('Boiler')];

    $result = ($this->runOp)($user, $site, 'set_title_emphasis', [
        'page_id' => $page->id,
        'stored_index' => 0,
        'title' => $title,
        'ranges' => [$range],
        'revision_base' => $page->published_revision_id,
        'structure_epoch' => 0,
    ]);

    $page->refresh();
    $draft = PageRevision::query()->find($page->draft_revision_id);

    expect($result->ok)->toBeTrue()
        ->and(PageRevision::query()->where('page_id', $page->id)->count())->toBe($before + 1)
        ->and($draft->content_data['sections'][0]['title'])->toBe($title)
        ->and($draft->content_data['sections'][0]['accent_ranges'])->toBe([$range])
        ->and($page->published_revision_id)->not->toBe($page->draft_revision_id);
});

it('accents a later occurrence of a repeated phrase, not the first match', function () {
    $title = 'Plumbing Partners — Plumbing Experts';
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)(['title' => $title]);
    $range = ($this->secondPlumbingRange)($title);

    $result = ($this->runOp)($user, $site, 'set_title_emphasis', [
        'page_id' => $page->id,
        'stored_index' => 0,
        'ranges' => [$range],
        'revision_base' => $page->published_revision_id,
        'structure_epoch' => 0,
    ]);

    expect($result->ok)->toBeTrue()
        ->and($result->data['html'])->toContain('Plumbing Partners — <span class="accent-word"')
        ->and(substr_count($result->data['html'], '<span class="accent-word"'))->toBe(1);
});

it('indexes ranges as UTF-8 codepoints on a multibyte title', function () {
    $title = 'Café plumbing';
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)(['title' => $title]);
    $start = mb_strpos($title, 'plumbing');

    $result = ($this->runOp)($user, $site, 'set_title_emphasis', [
        'page_id' => $page->id,
        'stored_index' => 0,
        'ranges' => [['start' => $start, 'length' => mb_strlen('plumbing')]],
        'revision_base' => $page->published_revision_id,
        'structure_epoch' => 0,
    ]);

    expect($result->ok)->toBeTrue()
        ->and($result->data['html'])->toContain('Café <span class="accent-word"')
        ->and($result->data['html'])->toContain('>plumbing</span>');
});

it('accepts a range at the start and end of the title', function () {
    $title = 'Trusted';
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)(['title' => $title]);

    $result = ($this->runOp)($user, $site, 'set_title_emphasis', [
        'page_id' => $page->id,
        'stored_index' => 0,
        'ranges' => [['start' => 0, 'length' => mb_strlen($title)]],
        'revision_base' => $page->published_revision_id,
        'structure_epoch' => 0,
    ]);

    expect($result->ok)->toBeTrue()
        ->and($result->data['html'])->toContain('<span class="accent-word"')
        ->and($result->data['html'])->toContain('>Trusted</span>');
});

it('rejects overlapping, descending, and out-of-bounds ranges as validation', function (array $ranges) {
    $title = 'Trusted Plumbing';
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)(['title' => $title]);
    $published = $page->published_revision_id;

    $result = ($this->runOp)($user, $site, 'set_title_emphasis', [
        'page_id' => $page->id,
        'stored_index' => 0,
        'ranges' => $ranges,
        'revision_base' => $published,
        'structure_epoch' => 0,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($page->fresh()->draft_revision_id)->toBeNull()
        ->and($page->fresh()->published_revision_id)->toBe($published);
})->with([
    'overlapping' => [[['start' => 0, 'length' => 10], ['start' => 8, 'length' => 4]]],
    'descending' => [[['start' => 8, 'length' => 8], ['start' => 0, 'length' => 7]]],
    'out of bounds' => [[['start' => 0, 'length' => 99]]],
    'zero length' => [[['start' => 0, 'length' => 0]]],
    'negative start' => [[['start' => -1, 'length' => 3]]],
]);

it('validates ranges against the resulting title when title is written too', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)([
        'title' => 'A long original title that would fit a wide range',
    ]);
    $published = $page->published_revision_id;

    $result = ($this->runOp)($user, $site, 'set_title_emphasis', [
        'page_id' => $page->id,
        'stored_index' => 0,
        'title' => 'Hi',
        'ranges' => [['start' => 0, 'length' => 10]],
        'revision_base' => $published,
        'structure_epoch' => 0,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($page->fresh()->draft_revision_id)->toBeNull();
});

it('clears accent_ranges in the same revision when edit_field writes title, and warns', function () {
    $title = 'Trusted Plumbing Partner';
    $ranges = [['start' => 8, 'length' => 8]];
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)([
        'title' => $title,
        'accent_ranges' => $ranges,
    ]);
    $before = PageRevision::query()->where('page_id', $page->id)->count();

    $result = ($this->runOp)($user, $site, 'edit_field', [
        'page_id' => $page->id,
        'stored_index' => 0,
        'field_path' => 'title',
        'value' => 'Hi',
        'revision_base' => $page->published_revision_id,
        'structure_epoch' => 0,
    ]);

    $page->refresh();
    $draft = PageRevision::query()->find($page->draft_revision_id);

    expect($result->ok)->toBeTrue()
        ->and(PageRevision::query()->where('page_id', $page->id)->count())->toBe($before + 1)
        ->and($draft->content_data['sections'][0]['title'])->toBe('Hi')
        ->and($draft->content_data['sections'][0])->not->toHaveKey('accent_ranges')
        ->and(array_column($result->receipt->warnings, 'code'))->toContain('accent_ranges_dropped');
});

it('does not clear accent_ranges when edit_field writes a non-title field', function () {
    $ranges = [['start' => 0, 'length' => 7]];
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)([
        'title' => 'Trusted Plumbing',
        'subtitle' => 'Local',
        'accent_ranges' => $ranges,
    ]);

    $result = ($this->runOp)($user, $site, 'edit_field', [
        'page_id' => $page->id,
        'stored_index' => 0,
        'field_path' => 'subtitle',
        'value' => 'Updated subtitle',
        'revision_base' => $page->published_revision_id,
        'structure_epoch' => 0,
    ]);

    $page->refresh();
    $draft = PageRevision::query()->find($page->draft_revision_id);

    expect($result->ok)->toBeTrue()
        ->and($draft->content_data['sections'][0]['accent_ranges'])->toBe($ranges)
        ->and(array_column($result->receipt->warnings, 'code'))->not->toContain('accent_ranges_dropped');
});

it('drops ranges and warns when the hero already has title_lines', function () {
    $title = 'Trusted Plumbing Partner';
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)([
        'title' => $title,
        'title_lines' => ['Trusted Plumbing', 'Partner'],
    ]);

    $result = ($this->runOp)($user, $site, 'set_title_emphasis', [
        'page_id' => $page->id,
        'stored_index' => 0,
        'title' => 'New hero title',
        'ranges' => [['start' => 0, 'length' => 3]],
        'revision_base' => $page->published_revision_id,
        'structure_epoch' => 0,
    ]);

    $page->refresh();
    $draft = PageRevision::query()->find($page->draft_revision_id);

    expect($result->ok)->toBeTrue()
        ->and($draft->content_data['sections'][0]['title'])->toBe('New hero title')
        ->and($draft->content_data['sections'][0])->not->toHaveKey('accent_ranges')
        ->and(array_column($result->receipt->warnings, 'code'))->toContain('accent_ranges_dropped');
});

it('lets edit_field write hero accent_word now that it is schema-registered', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)();

    $result = ($this->runOp)($user, $site, 'edit_field', [
        'page_id' => $page->id,
        'stored_index' => 0,
        'field_path' => 'accent_word',
        'value' => 'Plumbing',
        'revision_base' => $page->published_revision_id,
        'structure_epoch' => 0,
    ]);

    $page->refresh();
    $draft = PageRevision::query()->find($page->draft_revision_id);

    expect($result->ok)->toBeTrue()
        ->and($draft->content_data['sections'][0]['accent_word'])->toBe('Plumbing');
});

it('rejects set_title_emphasis on a section type that does not render an accent', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)();
    $published = $page->published_revision_id;

    $result = ($this->runOp)($user, $site, 'set_title_emphasis', [
        'page_id' => $page->id,
        'stored_index' => 1,
        'ranges' => [['start' => 0, 'length' => 4]],
        'revision_base' => $published,
        'structure_epoch' => 0,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($page->fresh()->draft_revision_id)->toBeNull();
});

it('returns stale_revision on a wrong revision_base and never writes', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)();
    $published = $page->published_revision_id;

    $result = ($this->runOp)($user, $site, 'set_title_emphasis', [
        'page_id' => $page->id,
        'stored_index' => 0,
        'ranges' => [['start' => 0, 'length' => 8]],
        'revision_base' => 999999,
        'structure_epoch' => 0,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('stale_revision')
        ->and($result->error['current_revision_id'])->toBe($published)
        ->and($page->fresh()->draft_revision_id)->toBeNull();
});

it('returns stale_revision on a fresh base but stale structure_epoch', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)();
    $published = $page->published_revision_id;

    app(PageService::class)->mutateSections($page, $published, 0, fn (array $sections): array => $sections);
    $page->refresh();

    $result = ($this->runOp)($user, $site, 'set_title_emphasis', [
        'page_id' => $page->id,
        'stored_index' => 0,
        'ranges' => [['start' => 0, 'length' => 8]],
        'revision_base' => $page->draft_revision_id,
        'structure_epoch' => 0,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('stale_revision')
        ->and($page->fresh()->published_revision_id)->toBe($published);
});

it('shares editField lock, base and epoch checks so two paths land in one revision', function () {
    ['page' => $page] = ($this->seedEditorSite)(['title' => 'Trusted Plumbing']);
    $base = $page->published_revision_id;
    $before = PageRevision::query()->where('page_id', $page->id)->count();

    $revision = app(PageService::class)->editFields(
        $page,
        [
            'sections.0.title' => 'Trusted Electrical',
            'sections.0.accent_ranges' => [['start' => 8, 'length' => 10]],
        ],
        null,
        $base,
        0,
    );

    $page->refresh();

    expect(PageRevision::query()->where('page_id', $page->id)->count())->toBe($before + 1)
        ->and($revision->id)->toBe($page->draft_revision_id)
        ->and($revision->content_data['sections'][0]['title'])->toBe('Trusted Electrical')
        ->and($revision->content_data['sections'][0]['accent_ranges'])->toBe([['start' => 8, 'length' => 10]]);
});

it('throws StaleRevisionException from editFields on a wrong base', function () {
    ['page' => $page] = ($this->seedEditorSite)();

    app(PageService::class)->editFields(
        $page,
        ['sections.0.title' => 'Nope'],
        null,
        999999,
        0,
    );
})->throws(StaleRevisionException::class);

it('throws StaleStructureException from editFields on a stale epoch', function () {
    ['page' => $page] = ($this->seedEditorSite)();

    app(PageService::class)->editFields(
        $page,
        ['sections.0.title' => 'Nope'],
        null,
        $page->published_revision_id,
        7,
    );
})->throws(StaleStructureException::class);

it('registers a ranges field type that rejects a non-list payload', function () {
    $errors = app(SectionSchema::class)->validateField('hero', 'accent_ranges', 'not-ranges');

    expect($errors)->not->toBe([])
        ->and(implode(' ', $errors))->not->toContain('Unknown field');
});
