<?php

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\HeroVideoVersion;
use App\Models\LogoConcept;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\PageService;
use App\Services\Site\PublicPageCache;
use Illuminate\Support\Facades\Storage;

/*
 * Task 16 — undo_revision (A2). Every expected value here is computed independently of the code
 * under test: the seed names the exact content each revision must carry, and undo asserts equality
 * against that seed, never "changed" or "non-empty".
 */

beforeEach(function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);
    $this->withoutVite();
});

/**
 * @param  list<array<string, mixed>>|null  $sections
 * @return array{0: User, 1: Site, 2: GeneratedPage, 3: PageRevision}
 */
function w2t16Site(?array $sections = null): array
{
    $user = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $content = ['sections' => $sections ?? [
        ['type' => 'hero', 'title' => 'Live baseline alpha'],
        ['type' => 'cta', 'title' => 'Call us'],
    ]];
    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'content_data' => $content,
        'status' => PageStatus::Published,
    ]);
    $published = PageRevision::factory()->for($page, 'page')->create(['content_data' => $content]);
    $page->update(['published_revision_id' => $published->id]);

    return [$user, $site, $page->fresh(), $published];
}

function w2t16Run(User $user, Site $site, string $operation, array $input): OperationResult
{
    $result = app(EditorOperations::class)->run(
        new EditorContext($user, $site, ActorChannel::Webmcp),
        $operation,
        $input,
    );

    return $result;
}

function w2t16Edit(User $user, Site $site, GeneratedPage $page, string $title): OperationResult
{
    $page->refresh();

    return w2t16Run($user, $site, 'edit_field', [
        'page_id' => $page->id,
        'stored_index' => 0,
        'field_path' => 'title',
        'value' => $title,
        'revision_base' => $page->draft_revision_id ?? $page->published_revision_id,
    ]);
}

function w2t16Undo(User $user, Site $site, GeneratedPage $page, int $revisionId, ?int $epoch = null): OperationResult
{
    $page->refresh();

    return w2t16Run($user, $site, 'undo_revision', [
        'page_id' => $page->id,
        'revision_id' => $revisionId,
        'revision_base' => $revisionId,
        'structure_epoch' => $epoch ?? (int) $page->structure_epoch,
    ]);
}

function w2t16Revision(int $id): PageRevision
{
    return PageRevision::query()->findOrFail($id);
}

function w2t16Title(PageRevision $revision): string
{
    return $revision->fresh()->content_data['sections'][0]['title'];
}

it('undoes the first edit after a publish back to the published content, leaving every publish-side surface untouched', function () {
    Storage::fake('s3'); // an active home hero video makes the admin render probe the s3 disk
    [$user, $site, $page, $published] = w2t16Site();
    $hero = HeroVersion::factory()->for($site)->active()->create();
    $logo = LogoConcept::factory()->for($site)->selected()->create();
    $video = HeroVideoVersion::factory()->for($site)->active()->create();
    $site->update(['home_hero_video_enabled' => true]);
    $cache = app(PublicPageCache::class);
    $cacheGeneration = $cache->generation($site);

    // Publish has cleared the draft pointer, so the parent expression must fall through to the
    // published revision — draft_revision_id alone would be null here and make this edit
    // permanently un-undoable.
    $edit = w2t16Edit($user, $site, $page, 'Edited after publish');
    expect($edit->ok)->toBeTrue();

    $page->refresh();
    $draftId = $page->draft_revision_id;

    // Asserted on a FRESH database load: a missing $fillable entry silently discards the lineage
    // at PageRevision::create() while the in-memory model still looks correct.
    expect(w2t16Revision($draftId)->parent_revision_id)->toBe($published->id);

    $result = w2t16Undo($user, $site, $page, $draftId);

    $page->refresh();
    expect($result->ok)->toBeTrue()
        ->and($page->draft_revision_id)->toBe($result->data['draft_revision_id'])
        // the independently-known published content, not merely "changed"
        ->and(w2t16Title(w2t16Revision($page->draft_revision_id)))->toBe('Live baseline alpha')
        ->and($page->published_revision_id)->toBe($published->id)
        // the page has a draft again (it happens to match what is live), so pending_publish is true —
        // stated here so it is not later read as a bug
        ->and($result->state->pendingPublish)->toBeTrue()
        // drafts law: an undo never publishes, activates, or invalidates
        ->and($hero->fresh()->is_active)->toBeTrue()
        ->and($logo->fresh()->is_selected)->toBeTrue()
        ->and($video->fresh()->is_active)->toBeTrue()
        ->and($site->fresh()->home_hero_video_enabled)->toBeTrue()
        ->and($cache->generation($site))->toBe($cacheGeneration);

    // A second edit on top of the undo: its recorded parent is the UNDO revision, not the
    // published one — an implementation that (like rollbackToRevision) moves
    // published_revision_id to the restore target is caught by this half.
    expect(w2t16Edit($user, $site, $page, 'Edited again on top')->ok)->toBeTrue();
    $page->refresh();
    $second = w2t16Undo($user, $site, $page, $page->draft_revision_id);

    $page->refresh();
    expect($second->ok)->toBeTrue()
        ->and(w2t16Title(w2t16Revision($page->draft_revision_id)))->toBe('Live baseline alpha')
        ->and($page->published_revision_id)->toBe($published->id);
});

it('refuses with validation no_recorded_parent when the draft revision has no recorded parent', function () {
    [$user, $site, $page, $published] = w2t16Site();

    // A revision written before the lineage column existed (and any inserted other than through
    // createDraftRevision()): deliberately parentless. An honest refusal beats a wrong restore.
    $legacy = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Pre-migration draft']]],
    ]);
    $page->update(['draft_revision_id' => $legacy->id]);
    $revisionCount = PageRevision::query()->where('page_id', $page->id)->count();

    $result = w2t16Undo($user, $site, $page->fresh(), $legacy->id);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($result->error['no_recorded_parent'])->toBeTrue()
        ->and($result->error['message'])->toContain('no recorded parent')
        ->and($page->fresh()->draft_revision_id)->toBe($legacy->id)
        ->and($page->fresh()->published_revision_id)->toBe($published->id)
        ->and(PageRevision::query()->where('page_id', $page->id)->count())->toBe($revisionCount);
});

it('records that directly inserted revisions (GenerateServicePageJob) are deliberately parentless', function () {
    [$user, $site, $page, $published] = w2t16Site();

    // GenerateServicePageJob writes published-side flips through PageRevision::create() directly,
    // not createDraftRevision() — recorded here so the null is not later "fixed" with a backfill.
    $flipped = PageRevision::create([
        'page_id' => $page->id,
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Job-written content']]],
        'ai_generated' => true,
        'ai_model_version' => 'demo-model',
        'created_by_user_id' => null,
        'created_at' => now(),
    ]);
    $page->update(['published_revision_id' => $flipped->id]);

    expect($flipped->fresh()->parent_revision_id)->toBeNull()
        ->and($page->fresh()->published_revision_id)->toBe($flipped->id);
});

it('refuses a parent that does not resolve within the same page and never writes across sites', function () {
    [$user, $site, $page, $published] = w2t16Site();
    [$otherUser, $otherSite, $otherPage, $otherPublished] = w2t16Site([
        ['type' => 'hero', 'title' => 'Other site live content'],
    ]);

    $edit = w2t16Edit($user, $site, $page, 'Edited before mis-copy');
    expect($edit->ok)->toBeTrue();
    $page->refresh();
    $draftId = $page->draft_revision_id;

    // A mis-copied clone: the parent points at the OTHER site's revision. A bare
    // PageRevision::find($parentId) would restore that content into this site's draft.
    PageRevision::query()->whereKey($draftId)->update(['parent_revision_id' => $otherPublished->id]);

    $otherRevisionCount = PageRevision::query()->where('page_id', $otherPage->id)->count();
    $revisionCount = PageRevision::query()->where('page_id', $page->id)->count();

    $result = w2t16Undo($user, $site, $page->fresh(), $draftId);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($result->error['no_recorded_parent'])->toBeTrue()
        // this site's draft is untouched — no new revision was written from the stranger content
        ->and($page->fresh()->draft_revision_id)->toBe($draftId)
        ->and(w2t16Title(w2t16Revision($draftId)))->toBe('Edited before mis-copy')
        ->and(PageRevision::query()->where('page_id', $page->id)->count())->toBe($revisionCount)
        // and the other site was never touched either
        ->and($otherPage->fresh()->draft_revision_id)->toBeNull()
        ->and(PageRevision::query()->where('page_id', $otherPage->id)->count())->toBe($otherRevisionCount);
});

it('refuses stale_revision with current_revision_id when revision_id is not the current draft', function () {
    [$user, $site, $page, $published] = w2t16Site();
    expect(w2t16Edit($user, $site, $page, 'Edited once')->ok)->toBeTrue();
    $page->refresh();
    $draftId = $page->draft_revision_id;

    // naming the published revision rather than the current draft
    $result = w2t16Undo($user, $site, $page, $published->id);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('stale_revision')
        ->and($result->error['current_revision_id'])->toBe($draftId)
        ->and($page->fresh()->draft_revision_id)->toBe($draftId);
});

it('refuses stale_revision on a page with no draft at all, naming the published revision as current', function () {
    [$user, $site, $page, $published] = w2t16Site();

    $result = w2t16Undo($user, $site, $page, $published->id);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('stale_revision')
        ->and($result->error['current_revision_id'])->toBe($published->id)
        ->and($page->fresh()->draft_revision_id)->toBeNull();
});

it('refuses a stale structure_epoch with stale_revision and writes nothing', function () {
    [$user, $site, $page, $published] = w2t16Site();
    expect(w2t16Edit($user, $site, $page, 'Edited once')->ok)->toBeTrue();
    $page->refresh();
    $draftId = $page->draft_revision_id;
    $revisionCount = PageRevision::query()->where('page_id', $page->id)->count();

    $result = w2t16Undo($user, $site, $page, $draftId, epoch: ((int) $page->structure_epoch) + 1);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('stale_revision')
        ->and($result->error['message'])->toContain('structure')
        ->and($result->error['current_revision_id'])->toBe($draftId)
        ->and($page->fresh()->draft_revision_id)->toBe($draftId)
        ->and(PageRevision::query()->where('page_id', $page->id)->count())->toBe($revisionCount);
});

it('undoes edit B after edit → discard → edit back to the published base, not the discarded draft', function () {
    [$user, $site, $page, $published] = w2t16Site([
        ['type' => 'hero', 'title' => 'Live baseline alpha'],
    ]);
    $pages = app(PageService::class);

    $a = $pages->editField($page, 'sections.0.title', 'Discarded attempt A');
    $pages->discardDraft($page->fresh()); // clears the draft pointer WITHOUT deleting A's row
    $b = $pages->editField($page->fresh(), 'sections.0.title', 'Kept edit B');

    // Both drafts descend from the published base — A is in nobody's lineage, so the
    // next-lowest-id heuristic would restore 'Discarded attempt A' here.
    expect(w2t16Revision($a->id)->parent_revision_id)->toBe($published->id)
        ->and(w2t16Revision($b->id)->parent_revision_id)->toBe($published->id);

    $result = w2t16Undo($user, $site, $page->fresh(), $b->id);

    $page->refresh();
    expect($result->ok)->toBeTrue()
        // equality with the independently-known published base — restoring A fails this loudly
        ->and(w2t16Title(w2t16Revision($page->draft_revision_id)))->toBe('Live baseline alpha')
        ->and($page->published_revision_id)->toBe($published->id);
});

it('never moves published_revision_id — that publish-side move belongs to rollbackToRevision alone', function () {
    [$user, $site, $page, $published] = w2t16Site();
    $pages = app(PageService::class);
    $earlier = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Earlier live content']]],
    ]);

    expect(w2t16Edit($user, $site, $page, 'Edited once')->ok)->toBeTrue();
    $page->refresh();

    $undo = w2t16Undo($user, $site, $page, $page->draft_revision_id);
    expect($undo->ok)->toBeTrue()
        ->and($page->fresh()->published_revision_id)->toBe($published->id);

    // The companion fact, stated plainly: rollbackToRevision() MOVES published_revision_id and
    // mirrors legacy content_data — it is a publish-side operation, which is exactly why the undo
    // path cannot reuse it.
    $pages->rollbackToRevision($page->fresh(), $earlier);

    expect($page->fresh()->published_revision_id)->toBe($earlier->id)
        ->and($page->fresh()->content_data['sections'][0]['title'])->toBe('Earlier live content');
});

it('bumps structure_epoch when the undo changes the section list', function () {
    [$user, $site, $page, $published] = w2t16Site(); // two sections, epoch 0

    // A field-only edit: the undo restores the same two sections — the epoch must not move.
    expect(w2t16Edit($user, $site, $page, 'Edited title')->ok)->toBeTrue();
    $page->refresh();

    $fieldUndo = w2t16Undo($user, $site, $page, $page->draft_revision_id);
    expect($fieldUndo->ok)->toBeTrue()
        ->and($fieldUndo->data['structure_epoch'])->toBe(0)
        ->and((int) $page->fresh()->structure_epoch)->toBe(0);

    $page->refresh(); // the operation layer loads its own page instance; re-read before the next base

    // A structure edit: removing a section bumps to 1, and undoing it restores two sections —
    // a section-count change, so the epoch must bump again (stored indexes moved).
    $remove = w2t16Run($user, $site, 'remove_section', [
        'page_id' => $page->id,
        'stored_index' => 1,
        'revision_base' => $page->draft_revision_id,
        'structure_epoch' => 0,
    ]);
    expect($remove->ok)->toBeTrue()
        ->and((int) $page->fresh()->structure_epoch)->toBe(1);

    $page->refresh();
    $structureUndo = w2t16Undo($user, $site, $page, $page->draft_revision_id);

    $page->refresh();
    expect($structureUndo->ok)->toBeTrue()
        ->and($structureUndo->data['structure_epoch'])->toBe(2)
        ->and((int) $page->structure_epoch)->toBe(2)
        ->and(count(w2t16Revision($page->draft_revision_id)->content_data['sections']))->toBe(2)
        ->and(w2t16Title(w2t16Revision($page->draft_revision_id)))->toBe('Live baseline alpha')
        ->and($page->published_revision_id)->toBe($published->id);
});
it('bumps structure_epoch on undo of a same-count reorder — not just count changes', function () {
    // Three sections with three distinct types so a type-identity projection
    // sees a reorder even though the count is unchanged.
    [$user, $site, $page, $published] = w2t16Site([
        ['type' => 'hero', 'title' => 'Alpha'],
        ['type' => 'cta',  'title' => 'Beta'],
        ['type' => 'trust', 'title' => 'Gamma'],
    ]); // epoch 0

    expect(w2t16Edit($user, $site, $page, 'Edited title')->ok)->toBeTrue();
    $page->refresh();
    $draftBeforeMove = $page->draft_revision_id;

    // Reorder: swap index 0 ↔ 1. Count stays 3, but the list changed.
    $move = w2t16Run($user, $site, 'move_section', [
        'page_id'        => $page->id,
        'from'           => 0,
        'to'             => 1,
        'revision_base'  => $draftBeforeMove,
        'structure_epoch' => 0,
    ]);
    expect($move->ok)->toBeTrue()
        ->and((int) $page->fresh()->structure_epoch)->toBe(1);

    $page->refresh();
    $draftAfterMove = $page->draft_revision_id;
    $epochBeforeUndo = (int) $page->structure_epoch; // 1

    // Undo the reorder. The section list moves back — epoch must bump again
    // even though the count never changed.
    $undo = w2t16Undo($user, $site, $page, $draftAfterMove, $epochBeforeUndo);
    expect($undo->ok)->toBeTrue()
        ->and($undo->data['structure_epoch'])->toBe($epochBeforeUndo + 1)
        ->and((int) $page->fresh()->structure_epoch)->toBe($epochBeforeUndo + 1);

    $page->refresh();

    // Sections are back in their original order (hero, cta, trust).
    $sections = w2t16Revision($page->draft_revision_id)->fresh()->content_data['sections'];
    expect(array_map(fn ($s) => $s['type'], $sections))->toBe(['hero', 'cta', 'trust']);
});

it('bumps structure_epoch on undo of a same-type reorder — the type-sequence projection alone misses it', function () {
    // Two sections of the SAME type, distinguished only by their content. A type-identity
    // projection sees the identical sequence before and after the reorder, so a
    // count/type comparison alone would stay blind even though the stored indexes
    // moved just as the distinct-type case does. Expected values are computed
    // independently (epoch_before_undo + 1) and the restore order is asserted on
    // the distinguishing content, never on `type` (equal here).
    [$user, $site, $page, $published] = w2t16Site([
        ['type' => 'about-text', 'title' => 'First block'],
        ['type' => 'about-text', 'title' => 'Second block'],
    ]); // epoch 0

    // Reorder through the structural path: swap index 0 <-> 1. Count stays 2 and the
    // ordered type sequence is `about-text, about-text` both before and after, which
    // a type-sequence projection alone cannot distinguish from a no-op.
    $move = w2t16Run($user, $site, 'move_section', [
        'page_id'         => $page->id,
        'from'            => 0,
        'to'              => 1,
        'revision_base'   => $page->published_revision_id,
        'structure_epoch' => 0,
    ]);
    expect($move->ok)->toBeTrue()
        ->and((int) $page->fresh()->structure_epoch)->toBe(1);

    $page->refresh();
    $draftAfterMove = $page->draft_revision_id;
    $epochBeforeUndo = (int) $page->structure_epoch; // 1

    // Undo the same-type reorder. The count and the type sequence are BOTH unchanged by this
    // undo, yet the stored indexes move — the epoch must bump, or a client holding the pre-undo
    // index map writes to the wrong section.
    $undo = w2t16Undo($user, $site, $page, $draftAfterMove, $epochBeforeUndo);
    expect($undo->ok)->toBeTrue()
        ->and($undo->data['structure_epoch'])->toBe($epochBeforeUndo + 1)
        ->and((int) $page->fresh()->structure_epoch)->toBe($epochBeforeUndo + 1);

    $page->refresh();

    // Sections are back in their ORIGINAL order, asserted on the distinguishing content —
    // not on `type`, which is the same for both sections by design.
    $sections = w2t16Revision($page->draft_revision_id)->fresh()->content_data['sections'];
    expect(array_map(fn ($s) => $s['title'] ?? null, $sections))->toBe(['First block', 'Second block']);
});

it('bumps structure_epoch on undo of a same-type reorder whose sections carry DIFFERENT field sets', function () {
    // The sort()-based permutation check this replaces was not a total ordering: PHP treats two
    // associative arrays with different key sets as uncomparable, so two lists that ARE exact
    // permutations could sort into different orders and the reorder went undetected. These two
    // sections share a type and differ in which optional field they carry — an ordinary shape,
    // and the exact counterexample that defeated the previous implementation.
    [$user, $site, $page, $published] = w2t16Site([
        ['type' => 'about-text', 'variant' => null, 'title' => 'First block'],
        ['type' => 'about-text', 'variant' => null, 'body' => 'Second block'],
    ]); // epoch 0

    $move = w2t16Run($user, $site, 'move_section', [
        'page_id'         => $page->id,
        'from'            => 0,
        'to'              => 1,
        'revision_base'   => $page->published_revision_id,
        'structure_epoch' => 0,
    ]);
    expect($move->ok)->toBeTrue()
        ->and((int) $page->fresh()->structure_epoch)->toBe(1);

    $page->refresh();
    $draftAfterMove = $page->draft_revision_id;
    $epochBeforeUndo = (int) $page->structure_epoch; // 1

    // Count and ordered type sequence are BOTH unchanged, and the two sections have different
    // key sets — so this bumps only if the multiset comparison is total.
    $undo = w2t16Undo($user, $site, $page, $draftAfterMove, $epochBeforeUndo);
    expect($undo->ok)->toBeTrue()
        ->and($undo->data['structure_epoch'])->toBe($epochBeforeUndo + 1)
        ->and((int) $page->fresh()->structure_epoch)->toBe($epochBeforeUndo + 1);

    $page->refresh();

    // Restored order asserted on the distinguishing fields, never on `type`, which is constant here.
    $sections = w2t16Revision($page->draft_revision_id)->fresh()->content_data['sections'];
    expect(array_map(fn ($s) => $s['title'] ?? $s['body'] ?? null, $sections))
        ->toBe(['First block', 'Second block']);
});
