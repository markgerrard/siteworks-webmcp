<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(\App\Http\Middleware\EnsureClientUser::class);
    $this->withoutVite();
});

function setupVariantEditableServicesPage(): array
{
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'services', 'title' => 'Our services', 'items' => []],
        ]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);
    test()->actingAs($user);

    return [$site, $page, $rev, $user];
}

test('writing a registered section variant creates a draft revision that carries it', function () {
    [$site, $page] = setupVariantEditableServicesPage();
    $publishedId = $page->published_revision_id;

    $response = $this->postJson(
        route('site.admin.field-update', ['site' => $site->id, 'page' => $page->id]),
        [
            'section_index' => 0,
            'field_path' => 'variant',
            'value' => 'featured-ledger',
        ],
    );

    $response->assertOk();
    $page->refresh();
    expect($page->draft_revision_id)->not->toBeNull()
        ->and($page->published_revision_id)->toBe($publishedId);
    $draft = PageRevision::find($page->draft_revision_id);
    expect($draft->content_data['sections'][0]['variant'])->toBe('featured-ledger');
    expect(PageRevision::find($publishedId)->content_data['sections'][0])->not->toHaveKey('variant');
});

test('an unknown section variant is rejected', function () {
    [$site, $page] = setupVariantEditableServicesPage();

    $this->postJson(
        route('site.admin.field-update', ['site' => $site->id, 'page' => $page->id]),
        [
            'section_index' => 0,
            'field_path' => 'variant',
            'value' => 'nope',
        ],
    )->assertStatus(422);
});

test('json null clears a section variant through the field update path', function () {
    [$site, $page] = setupVariantEditableServicesPage();

    $response = $this->postJson(
        route('site.admin.field-update', ['site' => $site->id, 'page' => $page->id]),
        [
            'section_index' => 0,
            'field_path' => 'variant',
            'value' => null,
        ],
    );

    $response->assertOk();
    $page->refresh();
    $draft = PageRevision::find($page->draft_revision_id);
    expect($draft->content_data['sections'][0])->toHaveKey('variant')
        ->and($draft->content_data['sections'][0]['variant'])->toBeNull();
});

test('writing a variant on an inline family without a site_sections schema creates a draft', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'reviews_summary', 'heading' => 'What clients say'],
        ]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);
    $this->actingAs($user);

    $response = $this->postJson(
        route('site.admin.field-update', ['site' => $site->id, 'page' => $page->id]),
        [
            'section_index' => 0,
            'field_path' => 'variant',
            'value' => 'grid',
        ],
    );

    $response->assertOk();
    $page->refresh();
    expect($page->draft_revision_id)->not->toBeNull();
    $draft = PageRevision::find($page->draft_revision_id);
    expect($draft->content_data['sections'][0]['variant'])->toBe('grid');
});

test('variant writes on a family with no registered variants are rejected', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'faqs', 'title' => 'FAQs', 'items' => []],
        ]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);
    $this->actingAs($user);

    $this->postJson(
        route('site.admin.field-update', ['site' => $site->id, 'page' => $page->id]),
        [
            'section_index' => 0,
            'field_path' => 'variant',
            'value' => 'classic',
        ],
    )->assertStatus(422);

    $page->refresh();
    expect($page->draft_revision_id)->toBeNull();
});

test('json null cannot clear a non-variant field', function () {
    [$site, $page, $rev] = setupVariantEditableServicesPage();

    $this->postJson(route('site.admin.field-update', ['site' => $site->id, 'page' => $page->id]), [
        'section_index' => 0, 'field_path' => 'title', 'value' => null,
    ])->assertStatus(422);

    $page->refresh();
    expect($page->draft_revision_id)->toBeNull()
        ->and(PageRevision::find($rev->id)->content_data['sections'][0]['title'])->toBe('Our services');
});

