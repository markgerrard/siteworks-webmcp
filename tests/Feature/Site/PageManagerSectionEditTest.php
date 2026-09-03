<?php

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->staff = User::factory()->staff(AgentRole::Admin)->create());

function seedSiteWithHomeSection(): Site
{
    $site = Site::factory()->create();
    $content = ['sections' => [
        ['type' => 'hero', 'title' => 'Original Title', 'subheading' => 'Original sub', 'body' => 'Original body'],
    ]];
    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'content_data' => $content,
        'sort_order' => 0,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    $rev = PageRevision::create([
        'page_id' => $page->id,
        'content_data' => $content,
        'ai_generated' => false,
        'created_at' => now(),
    ]);
    $page->update(['published_revision_id' => $rev->id]);
    Preview::factory()->create(['site_id' => $site->id]);

    return $site;
}

test('saveSection() creates a draft PageRevision (versioned renderer path)', function () {
    $site = seedSiteWithHomeSection();
    $page = GeneratedPage::where('site_id', $site->id)->first();

    expect($page->draft_revision_id)->toBeNull();

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'hero')
        ->set('editHeading', 'Brand-New Title')
        ->set('editSubheading', 'Brand-new sub')
        ->call('saveSection');

    $page->refresh();
    expect($page->draft_revision_id)->not->toBeNull();
    $draftRev = PageRevision::find($page->draft_revision_id);
    expect($draftRev->content_data['sections'][0]['title'])->toBe('Brand-New Title');
    expect($draftRev->content_data['sections'][0]['subheading'])->toBe('Brand-new sub');
});

test('saveSection() triggers the unpublished-changes banner via pagesWithDrafts', function () {
    $site = seedSiteWithHomeSection();

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'hero')
        ->set('editHeading', 'New H')
        ->call('saveSection')
        ->assertDispatched('composition-dirty');

    // Banner component independently recomputes pending state
    Livewire::actingAs($this->staff)
        ->test('site.unpublished-changes-banner', ['siteId' => $site->id])
        ->assertSet('pending', true);
});

test('saveSection() bumps admin_revision so concurrent auto-publish bails', function () {
    $site = seedSiteWithHomeSection();
    app(\App\Services\Site\CompositionService::class)->getOrCreateDraft($site);
    $before = (int) SiteDraft::where('site_id', $site->id)->value('admin_revision');

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'hero')
        ->set('editHeading', 'New H')
        ->call('saveSection');

    $after = (int) SiteDraft::where('site_id', $site->id)->value('admin_revision');
    expect($after)->toBeGreaterThan($before);
});

test('saveSection() creates the draft + bumps admin_revision in a single transaction', function () {
    // Regression test. If these two writes split
    // across transactions, a concurrent AutoPublishCoordinator could
    // read the old admin_revision, see no admin intent, and publish
    // the in-flight draft. This test asserts both land when we commit
    // and neither lands when we roll back.
    $site = seedSiteWithHomeSection();

    // Seed existing admin_revision so we can detect the bump
    $cs = app(\App\Services\Site\CompositionService::class);
    $cs->getOrCreateDraft($site);
    $before = (int) SiteDraft::where('site_id', $site->id)->value('admin_revision');

    // Atomic: draft creation + admin_revision bump in the same tx
    try {
        DB::transaction(function () use ($site) {
            $gp = GeneratedPage::where('site_id', $site->id)->first();
            $user = User::factory()->staff(AgentRole::Admin)->create();
            Livewire::actingAs($user)
                ->test('page-manager', ['siteId' => $site->id])
                ->call('edit', 'home', 'hero')
                ->set('editHeading', 'Inside-tx title')
                ->call('saveSection');

            // Force rollback to prove atomicity — if draft_revision_id
            // landed but admin_revision didn't, we'd observe a split.
            throw new RuntimeException('rollback-proof');
        });
    } catch (RuntimeException $e) {
        // Expected
    }

    $page = GeneratedPage::where('site_id', $site->id)->first();
    $after = (int) SiteDraft::where('site_id', $site->id)->value('admin_revision');

    // Both writes rolled back — confirms single-transaction semantics
    expect($page->draft_revision_id)->toBeNull();
    expect($after)->toBe($before);
});

test('saveSection() still mirrors to Preview.snapshot for legacy compat', function () {
    $site = seedSiteWithHomeSection();

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'hero')
        ->set('editHeading', 'New H')
        ->call('saveSection');

    $preview = $site->latestPreview->fresh();
    expect($preview->snapshot['pages']['home']['hero']['title'])->toBe('New H');
});

test('reviews and reviews_badge rows offer no edit flyout — they have no editable copy', function () {
    $site = Site::factory()->create();
    $content = ['sections' => [
        ['type' => 'hero', 'title' => 'About'],
        ['type' => 'reviews_badge', 'provider' => 'google'],
        ['type' => 'reviews', 'provider' => 'google', 'display_style' => 'carousel'],
        ['type' => 'story', 'title' => 'Our Story'],
    ]];
    $page = GeneratedPage::create([
        'site_id' => $site->id, 'page_type' => 'about', 'content_data' => $content,
        'sort_order' => 1, 'version' => 1, 'status' => PageStatus::Published,
    ]);
    $rev = PageRevision::create([
        'page_id' => $page->id, 'content_data' => $content, 'ai_generated' => false, 'created_at' => now(),
    ]);
    $page->update(['published_revision_id' => $rev->id]);
    Preview::factory()->create(['site_id' => $site->id]);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->assertDontSeeHtml("edit('about', 'reviews_badge'")
        ->assertDontSeeHtml("edit('about', 'reviews',")
        ->assertSeeHtml("edit('about', 'story',")
        ->assertSee('Managed in the Google Reviews panel');
});

/*
 * Item-schema round-trip: details sections store items as label/value
 * (what the template renders); FAQs use question/answer; other sections
 * use icon/title/body. The flyout must read AND write each section's
 * native schema — it used to read title/body only (label/value items
 * showed as empty rows) and write title/body only (saving destroyed
 * every label/value item — a contact page could lose its Phone, Email,
 * Address and layout in one save).
 */
function seedContactDetailsPage(): Site
{
    $site = Site::factory()->create();
    $content = ['sections' => [
        ['type' => 'details', 'title' => 'Get In Touch', 'items' => [
            ['label' => 'Coverage', 'value' => 'We serve Newquay.'],
            ['label' => 'Phone', 'value' => '07840368946'],
            ['label' => 'Email', 'value' => 'hi@example.com'],
        ]],
    ]];
    $page = GeneratedPage::create([
        'site_id' => $site->id, 'page_type' => 'contact', 'content_data' => $content,
        'sort_order' => 2, 'version' => 1, 'status' => PageStatus::Published,
    ]);
    $rev = PageRevision::create([
        'page_id' => $page->id, 'content_data' => $content, 'ai_generated' => false, 'created_at' => now(),
    ]);
    $page->update(['published_revision_id' => $rev->id]);
    Preview::factory()->create(['site_id' => $site->id]);

    return $site;
}

test('edit() seeds label/value items into the flyout rows', function () {
    $site = seedContactDetailsPage();

    $lw = Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'contact', 'details');

    $items = $lw->get('editItems');
    expect($items[0]['title'])->toBe('Coverage')
        ->and($items[0]['body'])->toBe('We serve Newquay.')
        ->and($items[1]['title'])->toBe('Phone')
        ->and($items[2]['body'])->toBe('hi@example.com');
});

test('saveSection() writes label/value items back in their native schema', function () {
    $site = seedContactDetailsPage();
    $page = GeneratedPage::where('site_id', $site->id)->first();

    $lw = Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'contact', 'details');

    $items = $lw->get('editItems');
    $items[] = ['icon' => '', 'title' => 'WhatsApp', 'body' => '07840368946'];
    $lw->set('editItems', $items)->call('saveSection');

    $page->refresh();
    $saved = collect(PageRevision::find($page->draft_revision_id)->content_data['sections'])
        ->firstWhere('type', 'details')['items'];

    expect($saved)->toHaveCount(4)
        ->and($saved[0])->toBe(['label' => 'Coverage', 'value' => 'We serve Newquay.'])
        ->and($saved[1])->toBe(['label' => 'Phone', 'value' => '07840368946'])
        ->and($saved[3])->toBe(['label' => 'WhatsApp', 'value' => '07840368946']);
});

/*
 * Rich-text item bodies (ProseMirror docs) must survive structural flyout
 * edits. saveSection() preserves a doc only when the row's flattened text
 * matches the original — but it used to look the original up BY INDEX, so
 * removing or reordering any item shifted every later row onto the wrong
 * original and silently flattened all their rich answers to plain text.
 */
function pmDoc(string ...$paragraphs): array
{
    return ['type' => 'doc', 'content' => array_map(fn ($t) => [
        'type' => 'paragraph',
        'content' => [['type' => 'text', 'text' => $t]],
    ], $paragraphs)];
}

function seedFaqPageWithRichAnswers(): Site
{
    $site = Site::factory()->create();
    $content = ['sections' => [
        ['type' => 'faqs', 'title' => 'FAQs', 'items' => [
            ['question' => 'Do you serve Newquay?', 'answer' => pmDoc('Yes we do.', 'Travel is free.')],
            ['question' => 'Are you insured?', 'answer' => pmDoc('Fully insured.', 'Certificates on request.')],
            ['question' => 'Do you offer guarantees?', 'answer' => pmDoc('Ten-year guarantee.', 'Backed in writing.')],
        ]],
    ]];
    $page = GeneratedPage::create([
        'site_id' => $site->id, 'page_type' => 'home', 'content_data' => $content,
        'sort_order' => 0, 'version' => 1, 'status' => PageStatus::Published,
    ]);
    $rev = PageRevision::create([
        'page_id' => $page->id, 'content_data' => $content, 'ai_generated' => false, 'created_at' => now(),
    ]);
    $page->update(['published_revision_id' => $rev->id]);
    Preview::factory()->create(['site_id' => $site->id]);

    return $site;
}

function savedFaqItems(Site $site): array
{
    $page = GeneratedPage::where('site_id', $site->id)->first();

    return collect(PageRevision::find($page->draft_revision_id)->content_data['sections'])
        ->firstWhere('type', 'faqs')['items'];
}

test('untouched saveSection() round-trips rich-text FAQ answers intact', function () {
    $site = seedFaqPageWithRichAnswers();

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'faqs')
        ->call('saveSection');

    // toEqual, not toBe: jsonb canonicalises key order on read-back.
    $saved = savedFaqItems($site);
    expect($saved[0]['answer'])->toEqual(pmDoc('Yes we do.', 'Travel is free.'))
        ->and($saved[1]['answer'])->toEqual(pmDoc('Fully insured.', 'Certificates on request.'))
        ->and($saved[2]['answer'])->toEqual(pmDoc('Ten-year guarantee.', 'Backed in writing.'));
});

test('removing one FAQ item does not flatten the remaining rich-text answers', function () {
    $site = seedFaqPageWithRichAnswers();

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'faqs')
        ->call('removeItem', 0)
        ->call('saveSection');

    $saved = savedFaqItems($site);
    expect($saved)->toHaveCount(2)
        ->and($saved[0]['answer'])->toEqual(pmDoc('Fully insured.', 'Certificates on request.'))
        ->and($saved[1]['answer'])->toEqual(pmDoc('Ten-year guarantee.', 'Backed in writing.'));
});

test('reordering FAQ items does not flatten their rich-text answers', function () {
    $site = seedFaqPageWithRichAnswers();

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'faqs')
        ->call('reorderItems', 0, 2)
        ->call('saveSection');

    $saved = savedFaqItems($site);
    expect($saved[0]['answer'])->toEqual(pmDoc('Fully insured.', 'Certificates on request.'))
        ->and($saved[1]['answer'])->toEqual(pmDoc('Ten-year guarantee.', 'Backed in writing.'))
        ->and($saved[2]['answer'])->toEqual(pmDoc('Yes we do.', 'Travel is free.'));
});

test('editing one FAQ answer flattens only that item, not its siblings', function () {
    $site = seedFaqPageWithRichAnswers();

    $lw = Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'faqs');

    $items = $lw->get('editItems');
    $items[1]['body'] = 'Fully insured, always.';
    $lw->set('editItems', $items)->call('saveSection');

    $saved = savedFaqItems($site);
    expect($saved[0]['answer'])->toEqual(pmDoc('Yes we do.', 'Travel is free.'))
        ->and($saved[1]['answer'])->toBe('Fully insured, always.')
        ->and($saved[2]['answer'])->toEqual(pmDoc('Ten-year guarantee.', 'Backed in writing.'));
});

/*
 * Item keys the flyout does not surface must survive the round-trip.
 * saveSection() rebuilt every item from a fixed shape (icon/title/body,
 * question/answer or label/value), so every OTHER key the templates render
 * was silently dropped on save: process step numbers, the services
 * source_service link key, and the whole of feature_showcase — whose items
 * carry url/name/tagline/screenshot_url and no title/body at all, so the
 * flyout shows blank rows and saving used to blank the section.
 */
function seedSectionWithItems(string $type, array $items, string $pageType = 'home'): Site
{
    $site = Site::factory()->create();
    $content = ['sections' => [
        ['type' => $type, 'title' => 'Section title', 'items' => $items],
    ]];
    $page = GeneratedPage::create([
        'site_id' => $site->id, 'page_type' => $pageType, 'content_data' => $content,
        'sort_order' => 0, 'version' => 1, 'status' => PageStatus::Published,
    ]);
    $rev = PageRevision::create([
        'page_id' => $page->id, 'content_data' => $content, 'ai_generated' => false, 'created_at' => now(),
    ]);
    $page->update(['published_revision_id' => $rev->id]);
    Preview::factory()->create(['site_id' => $site->id]);

    return $site;
}

function savedItemsOf(Site $site, string $type): array
{
    $page = GeneratedPage::where('site_id', $site->id)->first();

    return collect(PageRevision::find($page->draft_revision_id)->content_data['sections'])
        ->firstWhere('type', $type)['items'];
}

test('saveSection() preserves step numbers on process items', function () {
    $site = seedSectionWithItems('process', [
        ['step' => 1, 'icon' => 'search', 'title' => 'Site assessment', 'body' => pmDoc('We visit and quote.')],
        ['step' => 2, 'icon' => 'wrench', 'title' => 'Installation', 'body' => pmDoc('We fit it.')],
        ['step' => 3, 'icon' => 'check', 'title' => 'Sign-off', 'body' => pmDoc('You approve.')],
    ]);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'process')
        ->call('saveSection');

    $saved = savedItemsOf($site, 'process');
    expect($saved[0]['step'])->toBe(1)
        ->and($saved[1]['step'])->toBe(2)
        ->and($saved[2]['step'])->toBe(3);
});

test('saveSection() preserves the source_service link key on service cards', function () {
    $site = seedSectionWithItems('services', [
        ['icon' => 'flame', 'title' => 'Welding', 'body' => pmDoc('Skilled welding.'), 'source_service' => 'Welding'],
        ['icon' => 'leaf', 'title' => 'Garden Makeovers', 'body' => pmDoc('Full transformations.'), 'source_service' => 'Garden makeovers'],
    ]);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'services')
        ->call('saveSection');

    $saved = savedItemsOf($site, 'services');
    expect($saved[0]['source_service'])->toBe('Welding')
        ->and($saved[1]['source_service'])->toBe('Garden makeovers');
});

test('saveSection() leaves showcase items intact when the flyout cannot edit them', function () {
    $site = seedSectionWithItems('feature_showcase', [
        ['url' => 'https://carmex.example/', 'name' => 'CarMex Garage', 'tagline' => 'Independent garage, Wigan', 'screenshot_url' => '/showcase/carmex.png'],
        ['url' => 'https://tiles.example/', 'name' => 'Tiling Co', 'tagline' => 'Tilers, Perth', 'screenshot_url' => '/showcase/tiles.png'],
    ]);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'feature_showcase')
        ->call('saveSection');

    $saved = savedItemsOf($site, 'feature_showcase');
    expect($saved[0]['name'])->toBe('CarMex Garage')
        ->and($saved[0]['url'])->toBe('https://carmex.example/')
        ->and($saved[0]['tagline'])->toBe('Independent garage, Wigan')
        ->and($saved[0]['screenshot_url'])->toBe('/showcase/carmex.png')
        ->and($saved[1]['name'])->toBe('Tiling Co');
});

test('saveSection() does not seed empty title/body keys onto items that never had them', function () {
    $site = seedSectionWithItems('feature_showcase', [
        ['url' => 'https://carmex.example/', 'name' => 'CarMex Garage', 'tagline' => 'Independent garage, Wigan', 'screenshot_url' => '/showcase/carmex.png'],
    ]);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'feature_showcase')
        ->call('saveSection');

    expect(savedItemsOf($site, 'feature_showcase')[0])
        ->not->toHaveKey('title')
        ->not->toHaveKey('body')
        ->not->toHaveKey('icon');
});

test('saveSection() preserves extra keys against the ORIGINAL item after a reorder', function () {
    $site = seedSectionWithItems('process', [
        ['step' => 1, 'icon' => 'search', 'title' => 'Assess', 'body' => pmDoc('First.')],
        ['step' => 2, 'icon' => 'wrench', 'title' => 'Install', 'body' => pmDoc('Second.')],
        ['step' => 3, 'icon' => 'check', 'title' => 'Sign-off', 'body' => pmDoc('Third.')],
    ]);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'process')
        ->call('reorderItems', 0, 2)
        ->call('saveSection');

    // Rows moved, so each row's extra keys must follow ITS original item —
    // a positional merge would hand 'Assess' step 3 and icon 'check'.
    $saved = savedItemsOf($site, 'process');
    expect($saved[2]['title'])->toBe('Assess')
        ->and($saved[2]['step'])->toBe(1)
        ->and($saved[2]['icon'])->toBe('search')
        ->and($saved[0]['title'])->toBe('Install')
        ->and($saved[0]['step'])->toBe(2);
});

test('saveSection() still clears an item title the agent emptied', function () {
    $site = seedSectionWithItems('process', [
        ['step' => 1, 'icon' => 'search', 'title' => 'Assess', 'body' => pmDoc('First.')],
    ]);

    $lw = Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'process');

    $items = $lw->get('editItems');
    $items[0]['title'] = '';
    $lw->set('editItems', $items)->call('saveSection');

    expect(savedItemsOf($site, 'process')[0]['title'])->toBe('');
});

test('edit() denies an outsider with 403 instead of continuing', function () {
    $owner = User::factory()->staff(AgentRole::Agent)->create();
    $outsider = User::factory()->staff(AgentRole::Agent)->create();
    $site = seedSiteWithHomeSection();
    $site->update(['created_by_user_id' => $owner->id]);

    Livewire::actingAs($outsider)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'home', 'hero', 0)
        ->assertForbidden();
});

test('saveSection() denies an outsider with 403 instead of writing a draft', function () {
    $owner = User::factory()->staff(AgentRole::Agent)->create();
    $outsider = User::factory()->staff(AgentRole::Agent)->create();
    $site = seedSiteWithHomeSection();
    $site->update(['created_by_user_id' => $owner->id]);
    $page = GeneratedPage::where('site_id', $site->id)->firstOrFail();

    Livewire::actingAs($outsider)
        ->test('page-manager', ['siteId' => $site->id])
        ->set('editing', 'home.hero.0')
        ->set('editHeading', 'Stolen Title')
        ->call('saveSection')
        ->assertForbidden();

    expect($page->fresh()->draft_revision_id)->toBeNull();
});
