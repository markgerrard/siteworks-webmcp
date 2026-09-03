<?php

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\ApprovalPresentation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\OperationResult;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);
    $this->withoutVite();
});

/**
 * Four catalogued sections, each carrying a server-shaped ULID id. The ids are
 * minted here and stored; tests READ them back from storage, never from the
 * fixture array, so the asserted address is the stored id, not an offset.
 */
function a1cT4FourSections(): array
{
    return [
        ['type' => 'hero', 'title' => 'A', 'id' => (string) Str::ulid()],
        ['type' => 'cta', 'title' => 'B', 'id' => (string) Str::ulid()],
        ['type' => 'trust', 'title' => 'C', 'id' => (string) Str::ulid()],
        ['type' => 'faqs', 'title' => 'D', 'id' => (string) Str::ulid()],
    ];
}

/**
 * @param  list<array<string, mixed>>  $sections
 * @return array{0: User, 1: Site, 2: GeneratedPage}
 */
function a1cT4Seed(array $sections): array
{
    $user = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $content = ['sections' => $sections];
    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'content_data' => $content,
        'status' => PageStatus::Published,
    ]);
    $revision = PageRevision::factory()->for($page, 'page')->create(['content_data' => $content]);
    $page->update(['published_revision_id' => $revision->id]);

    return [$user, $site, $page->fresh()];
}

/**
 * @return list<array<string, mixed>>
 */
function a1cT4StoredSections(GeneratedPage $page): array
{
    $page->refresh();
    $revisionId = $page->draft_revision_id ?? $page->published_revision_id;

    return PageRevision::query()->findOrFail($revisionId)->content_data['sections'];
}

/**
 * Per-section variant list, positional — rows without a variant read as null.
 * (array_column skips rows lacking the key, which would weaken every equality
 * oracle below to "only the sections that changed".)
 *
 * @return list<string|null>
 */
function a1cT4Variants(GeneratedPage $page): array
{
    return array_map(
        fn (array $section): ?string => $section['variant'] ?? null,
        a1cT4StoredSections($page),
    );
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function a1cT4WriteInput(GeneratedPage $page, array $extra = []): array
{
    $page->refresh();

    return [
        'page_id' => $page->id,
        'revision_base' => $page->draft_revision_id ?? $page->published_revision_id,
        'structure_epoch' => (int) $page->structure_epoch,
        ...$extra,
    ];
}

/**
 * @param  array<string, mixed>  $input
 */
function a1cT4Run(User $user, Site $site, string $operation, array $input): OperationResult
{
    return app(EditorOperations::class)->run(
        new EditorContext($user, $site, ActorChannel::Webmcp),
        $operation,
        $input,
    );
}

/**
 * The acceptance test for the ruling. An id-addressed operation must hit the
 * same section after the list shifts under it, now at a new offset.
 *
 * Named wrong implementation: one that resolves the id to an offset ONCE and
 * reuses the integer — it holds offset 2 for the third section, and after the
 * first removal that integer lands on `faqs`, not `trust`. The full-list
 * equality on `variant` (and on the id at index 1) cannot pass for it.
 */
it('addresses by section_id after the list shifts and hits the same section, now at a new offset', function () {
    [$user, $site, $page] = a1cT4Seed(a1cT4FourSections());

    // The id is read from the STORED page — an agent would get it from the read path.
    $thirdSectionId = a1cT4StoredSections($page)[2]['id'];

    // Remove the first section positionally — the transition clause, unchanged behaviour.
    $remove = a1cT4Run($user, $site, 'remove_section', a1cT4WriteInput($page, ['stored_index' => 0]));
    expect($remove->ok)->toBeTrue();

    // The third section is now stored at index 1. Address it by id alone.
    $result = a1cT4Run($user, $site, 'set_variant', a1cT4WriteInput($page, [
        'section_id' => $thirdSectionId,
        'variant' => 'classic',
    ]));

    expect($result->ok)->toBeTrue();
    $sections = a1cT4StoredSections($page);
    expect(array_column($sections, 'type'))->toBe(['cta', 'trust', 'faqs'])
        ->and($sections[1]['id'])->toBe($thirdSectionId)
        ->and(a1cT4Variants($page))->toBe([null, 'classic', null]);
});

/**
 * § D6.0's oracle. `stored_index` must be omittable wherever `section_id` is
 * accepted — at the operation AND through the HTTP route.
 *
 * Named wrong implementation: Part B skipped, leaving the positional key
 * required — the operation reds on the first half, SectionsRequest reds the
 * route with 422 on the second.
 */
it('accepts section_id alone with stored_index omitted entirely, at the operation and through the route', function () {
    [$user, $site, $page] = a1cT4Seed(a1cT4FourSections());
    $targetId = a1cT4StoredSections($page)[1]['id'];

    $input = a1cT4WriteInput($page, ['section_id' => $targetId]);
    expect(array_key_exists('stored_index', $input))->toBeFalse();

    $result = a1cT4Run($user, $site, 'remove_section', $input);
    expect($result->ok)->toBeTrue()
        ->and(count(a1cT4StoredSections($page)))->toBe(3)
        ->and(array_column(a1cT4StoredSections($page), 'type'))->toBe(['hero', 'trust', 'faqs']);

    // Same id-only payload through the primary agent front.
    [$owner, $site2, $page2] = a1cT4Seed(a1cT4FourSections());
    $targetId2 = a1cT4StoredSections($page2)[1]['id'];

    $this->actingAs($owner)
        ->withHeader('X-Page-Revision-Base', (string) $page2->published_revision_id)
        ->postJson(route('site.editor.sections', [$site2, $page2]), [
            'op' => 'remove',
            'section_id' => $targetId2,
            'structure_epoch' => (int) $page2->structure_epoch,
        ])
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect(array_column(a1cT4StoredSections($page2), 'type'))->toBe(['hero', 'trust', 'faqs']);
});

/**
 * Part C. The route must pass section_id through validated() to the operation.
 *
 * Named wrong implementation: SectionsRequest keeps no rule for section_id, so
 * validated() silently drops the key. The disagreement case then degrades to a
 * positional write that succeeds (200) instead of the required 422 — that is
 * the assertion this test exists to make.
 */
it('routes section_id through POST /sections to the operation and mutates the id-named section', function () {
    [$owner, $site, $page] = a1cT4Seed(a1cT4FourSections());
    $trustId = a1cT4StoredSections($page)[2]['id'];

    $this->actingAs($owner)
        ->withHeader('X-Page-Revision-Base', (string) $page->published_revision_id)
        ->postJson(route('site.editor.sections', [$site, $page]), [
            'op' => 'set_variant',
            'section_id' => $trustId,
            'variant' => 'classic',
            'structure_epoch' => (int) $page->structure_epoch,
        ])
        ->assertOk()
        ->assertJsonPath('ok', true);

    $sections = a1cT4StoredSections($page);
    expect(array_column($sections, 'type'))->toBe(['hero', 'cta', 'trust', 'faqs'])
        ->and($sections[2]['id'])->toBe($trustId)
        ->and(a1cT4Variants($page))->toBe([null, null, 'classic', null]);

    // Both addresses supplied and disagreeing must reach the resolver as a pair.
    [$owner2, $site2, $page2] = a1cT4Seed(a1cT4FourSections());
    $faqsId = a1cT4StoredSections($page2)[3]['id'];

    $this->actingAs($owner2)
        ->withHeader('X-Page-Revision-Base', (string) $page2->published_revision_id)
        ->postJson(route('site.editor.sections', [$site2, $page2]), [
            'op' => 'remove',
            'section_id' => $faqsId,
            'stored_index' => 0, // names the hero — a different section
            'structure_epoch' => (int) $page2->structure_epoch,
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation');

    expect(count(a1cT4StoredSections($page2)))->toBe(4);
});

/**
 * § D6's failure modes, asserted on the error code.
 */
it('fails disagreement as validation and an unknown id as not_found', function () {
    [$user, $site, $page] = a1cT4Seed(a1cT4FourSections());
    $sections = a1cT4StoredSections($page);
    $trustId = $sections[2]['id'];

    $disagree = a1cT4Run($user, $site, 'remove_section', a1cT4WriteInput($page, [
        'section_id' => $trustId,
        'stored_index' => 3, // names faqs — a different section
    ]));
    expect($disagree->ok)->toBeFalse()
        ->and($disagree->error['code'])->toBe('validation')
        ->and(count(a1cT4StoredSections($page)))->toBe(4);

    $unknown = a1cT4Run($user, $site, 'remove_section', a1cT4WriteInput($page, [
        'section_id' => '01UNKNOWNSECTIONID0000000',
    ]));
    expect($unknown->ok)->toBeFalse()
        ->and($unknown->error['code'])->toBe('not_found')
        ->and(count(a1cT4StoredSections($page)))->toBe(4);

    // move_section: section_id replaces from; to stays an integer slot.
    $move = a1cT4Run($user, $site, 'move_section', a1cT4WriteInput($page, [
        'section_id' => $trustId,
        'to' => 0,
    ]));
    expect($move->ok)->toBeTrue()
        ->and(array_column(a1cT4StoredSections($page), 'type'))->toBe(['trust', 'hero', 'cta', 'faqs']);
});

/**
 * § D6 set_variant row: the write must go to the RESOLVED offset, not the raw
 * positional input. The raw input here is absent — a wrong implementation that
 * falls back to a default offset (0) writes the variant onto the hero; the
 * full-list equality on `variant` cannot pass for it.
 */
it('writes the variant to the section the id names, not to a positional fallback', function () {
    [$user, $site, $page] = a1cT4Seed(a1cT4FourSections());
    $trustId = a1cT4StoredSections($page)[2]['id'];

    $result = a1cT4Run($user, $site, 'set_variant', a1cT4WriteInput($page, [
        'section_id' => $trustId,
        'variant' => 'ink-ledger',
    ]));

    expect($result->ok)->toBeTrue()
        ->and(a1cT4Variants($page))->toBe([null, null, 'ink-ledger', null]);

    // Both addresses supplied and agreeing still writes the resolved offset.
    $agreeing = a1cT4Run($user, $site, 'set_variant', a1cT4WriteInput($page, [
        'section_id' => $trustId,
        'stored_index' => 2,
        'variant' => 'classic',
    ]));

    expect($agreeing->ok)->toBeTrue()
        ->and(a1cT4Variants($page))->toBe([null, null, 'classic', null]);
});

/**
 * Invariant 1. Ids are server-assigned; a caller-supplied id is never stored.
 * Presence is not an oracle — assert valid ULID, exact count, inequality, and
 * that the seeded ids are untouched.
 */
it('mints a server ULID on add_section and never stores a caller-supplied id', function () {
    [$user, $site, $page] = a1cT4Seed(a1cT4FourSections());
    $seededIds = array_column(a1cT4StoredSections($page), 'id');
    $supplied = '01AAAAAAAAAAAAAAAAAAAAAAAAAA';

    $result = a1cT4Run($user, $site, 'add_section', a1cT4WriteInput($page, [
        'type' => 'trust',
        'position' => 1,
        'id' => $supplied,
    ]));

    expect($result->ok)->toBeTrue();
    $sections = a1cT4StoredSections($page);
    $storedIds = array_column($sections, 'id');
    expect(count($sections))->toBe(5)
        ->and($storedIds[1])->not->toBe($supplied)
        ->and(Str::isUlid($storedIds[1]))->toBeTrue()
        ->and([$storedIds[0], $storedIds[2], $storedIds[3], $storedIds[4]])->toBe([
            $seededIds[0], $seededIds[1], $seededIds[2], $seededIds[3],
        ]);
});

/**
 * § D6 add_section row: anchors place the new section relative to an id-named
 * section, position may be omitted, and the two anchors are mutually exclusive.
 */
it('places an added section before or after an id-named anchor', function () {
    [$user, $site, $page] = a1cT4Seed(a1cT4FourSections());
    $ids = array_column(a1cT4StoredSections($page), 'id');

    $before = a1cT4Run($user, $site, 'add_section', a1cT4WriteInput($page, [
        'type' => 'faqs',
        'before_section_id' => $ids[2],
    ]));
    expect($before->ok)->toBeTrue();
    $afterBefore = a1cT4StoredSections($page);
    expect(array_column($afterBefore, 'type'))->toBe(['hero', 'cta', 'faqs', 'trust', 'faqs'])
        ->and($afterBefore[2]['id'])->not->toBeIn($ids)
        ->and(Str::isUlid($afterBefore[2]['id']))->toBeTrue();

    $after = a1cT4Run($user, $site, 'add_section', a1cT4WriteInput($page, [
        'type' => 'faqs',
        'after_section_id' => $ids[2],
    ]));
    expect($after->ok)->toBeTrue();
    expect(array_column(a1cT4StoredSections($page), 'type'))
        ->toBe(['hero', 'cta', 'faqs', 'trust', 'faqs', 'faqs']);

    // The two anchors are mutually exclusive.
    $both = a1cT4Run($user, $site, 'add_section', a1cT4WriteInput($page, [
        'type' => 'faqs',
        'before_section_id' => $ids[0],
        'after_section_id' => $ids[0],
    ]));
    expect($both->ok)->toBeFalse()
        ->and($both->error['code'])->toBe('validation');

    // A positional position and an anchor both name the insertion point; refusing
    // both is the same never-silently-prefer rule the disagreement case applies.
    $mixed = a1cT4Run($user, $site, 'add_section', a1cT4WriteInput($page, [
        'type' => 'faqs',
        'position' => 0,
        'before_section_id' => $ids[0],
    ]));
    expect($mixed->ok)->toBeFalse()
        ->and($mixed->error['code'])->toBe('validation');

    // An unknown anchor is not_found, never a silent append.
    $unknown = a1cT4Run($user, $site, 'add_section', a1cT4WriteInput($page, [
        'type' => 'faqs',
        'after_section_id' => '01UNKNOWNSECTIONID0000000',
    ]));
    expect($unknown->ok)->toBeFalse()
        ->and($unknown->error['code'])->toBe('not_found')
        ->and(count(a1cT4StoredSections($page)))->toBe(6);
});

/**
 * Anchors must also survive the HTTP surface — without a SectionsRequest rule
 * validated() drops them and the positional position becomes required (422).
 */
it('accepts an anchor through the HTTP route without a position', function () {
    [$owner, $site, $page] = a1cT4Seed(a1cT4FourSections());
    $ids = array_column(a1cT4StoredSections($page), 'id');

    $this->actingAs($owner)
        ->withHeader('X-Page-Revision-Base', (string) $page->published_revision_id)
        ->postJson(route('site.editor.sections', [$site, $page]), [
            'op' => 'add',
            'type' => 'trust',
            'after_section_id' => $ids[0],
            'structure_epoch' => (int) $page->structure_epoch,
        ])
        ->assertOk()
        ->assertJsonPath('ok', true);

    $sections = a1cT4StoredSections($page);
    expect(count($sections))->toBe(5)
        ->and($sections[1]['type'])->toBe('trust')
        ->and($sections[0]['id'])->toBe($ids[0]);
});

/**
 * § E. ApprovalPresentation::ARGUMENT_KEYS is an allowlist — without section_id
 * in it, the id is silently missing from the summary the human approves.
 */
it('shows section_id in the approval summary', function () {
    [$user, $site, $page] = a1cT4Seed(a1cT4FourSections());
    $trustId = a1cT4StoredSections($page)[2]['id'];

    $fields = app(ApprovalPresentation::class)->for(
        new EditorContext($user, $site, ActorChannel::Webmcp),
        'remove_section',
        ['page_id' => $page->id, 'section_id' => $trustId],
    );

    expect($fields)->toHaveKey('section_id')
        ->and($fields['section_id'])->toBe($trustId)
        ->and($fields['page_id'])->toBe((string) $page->id); // the allowlist presents strings
});
