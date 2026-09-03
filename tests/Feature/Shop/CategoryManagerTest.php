<?php

use App\Enums\AgentRole;
use App\Enums\Shop\ProductStatus;
use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Client;
use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Shop\ProductImage;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use App\Models\User;
use App\Services\Shop\CategoryTreeService;
use App\Services\Shop\SnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\CommerceReads;

uses(RefreshDatabase::class);

function categoryManagerQueryCount(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

test('user can list, create, rename and delete categories for their site', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->set('newName', 'Bouquets')
        ->call('addCategory')
        ->assertSet('newName', '')
        ->assertHasNoErrors();

    expect(Category::where('site_id', $site->id)->count())->toBe(1);

    $cat = Category::where('site_id', $site->id)->first();

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('rename', $cat->id, 'Bouquets & Posies');

    expect($cat->fresh()->name)->toBe('Bouquets & Posies');

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('delete', $cat->id);

    expect(Category::where('site_id', $site->id)->count())->toBe(0);
});

test('a client can save category content through the flyout', function () {
    $tenant = \App\Models\Client::factory()->create();
    $client = User::factory()->create(['client_id' => $tenant->id]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);
    $category = app(CategoryTreeService::class)->create($site, 'Collection');
    $this->actingAs($client);

    $component = Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $category->id)
        ->set('editorTab', 'content')
        ->assertSee('Content')
        ->set('descriptionLong.'.$category->id, '<h2>Details</h2><p>Safe copy.</p>')
        ->set('faqs.'.$category->id, [['q' => 'Question?', 'a' => 'Answer.']])
        ->set('metaTitle.'.$category->id, 'A title')
        ->set('metaDescription.'.$category->id, 'A description')
        ->call('saveEditor')
        ->assertHasNoErrors();

    expect($category->fresh()->description_long)->toBe('<h2>Details</h2><p>Safe copy.</p>')
        ->and($category->fresh()->faqs)->toBe([['q' => 'Question?', 'a' => 'Answer.']])
        ->and($category->fresh()->meta_description)->toBe('A description');
});

test('the category flyout shows an inline error for overlong sanitised copy', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $category = app(CategoryTreeService::class)->create($site, 'Collection');
    $this->actingAs($user);

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $category->id)
        ->set('editorTab', 'content')
        ->set('descriptionLong.'.$category->id, str_repeat('<p>Safe copy.</p>', 1_251))
        ->call('saveEditor')
        ->assertHasErrors(['descriptionLong.'.$category->id])
        ->assertSet('editingId', $category->id)
        ->assertSee('Long copy must be at most 20,000 characters after sanitising.');

    expect($category->fresh()->description_long)->toBeNull();
});

test('using a category content draft fills the form until the user saves it', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $category = app(CategoryTreeService::class)->create($site, 'Collection');
    $this->actingAs($user);
    $draft = [
        'description_long' => '<p>Draft copy.</p>',
        'faqs' => [['q' => 'Draft question?', 'a' => 'Draft answer.']],
        'meta_title' => 'Draft title',
        'meta_description' => 'Draft description',
    ];

    $component = Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $category->id)
        ->set('editorTab', 'content')
        ->set('contentDraft', $draft)
        ->set('contentDraftStatus', 'ready')
        ->call('useContentDraft')
        ->assertSet('descriptionLong.'.$category->id, $draft['description_long'])
        ->assertSet('faqs.'.$category->id, $draft['faqs'])
        ->assertSet('metaTitle.'.$category->id, $draft['meta_title'])
        ->assertSet('metaDescription.'.$category->id, $draft['meta_description'])
        ->assertSet('editingId', $category->id);

    expect($category->fresh()->description_long)->toBeNull()
        ->and($category->fresh()->is_ai_seeded)->toBeFalse();

    $component->call('saveEditor')->assertHasNoErrors()->assertSet('editingId', null);

    expect($category->fresh()->description_long)->toBe($draft['description_long'])
        ->and($category->fresh()->is_ai_seeded)->toBeTrue();
});

test('the category flyout reindexes legacy FAQ objects before rendering and saving', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $category = app(CategoryTreeService::class)->create($site, 'Collection');
    $hostileKey = '0);window.__pwned=1;//';
    $category->forceFill([
        'faqs' => [
            $hostileKey => ['q' => 'Legacy question?', 'a' => 'Legacy answer.'],
        ],
    ])->save();
    $this->actingAs($user);

    $component = Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $category->id)
        ->set('editorTab', 'content')
        ->assertStatus(200)
        ->assertSet('faqs.'.$category->id, [['q' => 'Legacy question?', 'a' => 'Legacy answer.']]);

    expect($component->html())->not->toContain($hostileKey);

    $component->call('saveEditor')->assertHasNoErrors();

    expect($category->fresh()->faqs)->toBe([
        ['q' => 'Legacy question?', 'a' => 'Legacy answer.'],
    ]);
});

test('drafting category copy flashes the demo-unavailable notice and keeps stored copy', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $category = app(CategoryTreeService::class)->create($site, 'Collection');
    $category->forceFill(['description_long' => '<p>Stored copy.</p>'])->save();
    $this->actingAs($user);

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $category->id)
        ->set('editorTab', 'content')
        ->call('draftContent')
        ->assertSee('Not available in this demo')
        ->assertSet('contentDraft', null)
        ->assertSet('descriptionLong.'.$category->id, '<p>Stored copy.</p>');

    expect($category->fresh()->description_long)->toBe('<p>Stored copy.</p>');
});

test('the category draft spinner has a sixty second client-side timeout', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $category = app(CategoryTreeService::class)->create($site, 'Collection');
    $this->actingAs($user);

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $category->id)
        ->set('editorTab', 'content')
        ->set('contentDraftStatus', 'drafting')
        ->assertSee('setTimeout')
        ->assertSee('60000')
        ->assertSee('draft copy — try again.');
});

test('the category manager nests under a parent, toggles tree fields and indents by depth', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);

    $service = app(CategoryTreeService::class);
    $cakesCat = $service->create($site, 'Cakes');
    $wedding = $service->create($site, 'Wedding Cakes', $cakesCat);

    $html = Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->set('parentId.'.$wedding->id, $cakesCat->id)
        ->set('isAnchor.'.$cakesCat->id, false)
        ->call('saveTree', $cakesCat->id)
        ->set('visibility.'.$wedding->id, 'hidden')
        ->set('metaTitle.'.$wedding->id, 'Wedding cakes Palo Alto')
        ->set('metaDescription.'.$wedding->id, 'Bespoke tiers.')
        ->set('sort.'.$wedding->id, 'name')
        ->call('saveTree', $wedding->id)
        ->html();

    expect($cakesCat->fresh()->is_anchor)->toBeFalse()
        ->and($wedding->fresh()->visibility)->toBe('hidden')
        ->and($wedding->fresh()->meta_title)->toBe('Wedding cakes Palo Alto')
        ->and($wedding->fresh()->meta_description)->toBe('Bespoke tiers.')
        ->and($wedding->fresh()->sort)->toBe('name')
        ->and($html)->toContain('data-depth="1"')
        ->and($html)->toContain('data-depth="2"')
        ->and($html)->not->toContain('placeholder="Meta title"')
        ->and($html)->not->toMatch('/setParent\('.$wedding->id.', '.$wedding->id.'\)/');
});

test('renaming a category through the manager recomputes subtree paths', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $service = app(CategoryTreeService::class);
    $cakes = $service->create($site, 'Cakes');
    $wedding = $service->create($site, 'Wedding Cakes', $cakes);

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('rename', $cakes->id, 'Gateaux');

    expect($cakes->fresh()->name)->toBe('Gateaux')
        ->and($wedding->fresh()->path)->toBe('cakes/wedding-cakes');
});

it('shows a rejected flyout move as an inline parent error and keeps the review open', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $service = app(CategoryTreeService::class);
    $cakes = $service->create($site, 'Cakes');
    $wedding = $service->create($site, 'Wedding Cakes', $cakes);
    $service->create($site, 'Tiered', $wedding);
    $tarts = $service->create($site, 'Tarts');
    $fruit = $service->create($site, 'Fruit Tarts', $tarts);

    $html = Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $wedding->id)
        ->set('parentId.'.$wedding->id, $fruit->id)
        ->call('saveEditor')
        ->assertHasErrors(['parentId.'.$wedding->id])
        ->assertSet('editingId', $wedding->id)
        ->assertSee('Categories cannot nest deeper than 3 levels.')
        ->html();

    expect($html)->toContain('data-category-detail="'.$wedding->id.'"')
        ->and($wedding->fresh()->parent_id)->toBe($cakes->id)
        ->and($wedding->fresh()->name)->toBe('Wedding Cakes');
});

it('shows a cycle rejection as an inline parent error in the open flyout', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $service = app(CategoryTreeService::class);
    $cakes = $service->create($site, 'Cakes');
    $wedding = $service->create($site, 'Wedding Cakes', $cakes);

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $cakes->id)
        ->set('parentId.'.$cakes->id, $wedding->id)
        ->call('saveEditor')
        ->assertHasErrors(['parentId.'.$cakes->id])
        ->assertSet('editingId', $cakes->id)
        ->assertSee('A category cannot be moved under itself or one of its descendants.');

    expect($cakes->fresh()->parent_id)->toBeNull();
});

it('opens the flyout with a parent error when a row Move to is rejected', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $service = app(CategoryTreeService::class);
    $cakes = $service->create($site, 'Cakes');
    $wedding = $service->create($site, 'Wedding Cakes', $cakes);
    $service->create($site, 'Tiered', $wedding);
    $tarts = $service->create($site, 'Tarts');
    $fruit = $service->create($site, 'Fruit Tarts', $tarts);

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('setParent', $wedding->id, $fruit->id)
        ->assertHasErrors(['parentId.'.$wedding->id])
        ->assertSet('editingId', $wedding->id)
        ->assertSee('Categories cannot nest deeper than 3 levels.');

    expect($wedding->fresh()->parent_id)->toBe($cakes->id);
});

test('moving a depth-3 subtree under another depth-2 node is rejected', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $service = app(CategoryTreeService::class);
    $cakes = $service->create($site, 'Cakes');
    $wedding = $service->create($site, 'Wedding Cakes', $cakes);
    $tiered = $service->create($site, 'Tiered', $wedding);
    $tarts = $service->create($site, 'Tarts');
    $fruit = $service->create($site, 'Fruit Tarts', $tarts);

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('setParent', $wedding->id, $fruit->id);

    expect($wedding->fresh()->parent_id)->toBe($cakes->id)
        ->and($tiered->fresh()->path)->toBe('cakes/wedding-cakes/tiered');
});

it('does not let a poisoned untouched row block saving another row', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $service = app(CategoryTreeService::class);
    $poisoned = $service->create($site, 'Legacy');
    $poisoned->forceFill(['visibility' => 'public'])->save();
    $ok = $service->create($site, 'Cakes');

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->set('sort.'.$ok->id, 'name')
        ->call('saveTree', $ok->id)
        ->assertHasNoErrors();

    expect($ok->fresh()->sort)->toBe('name')
        ->and($poisoned->fresh()->visibility)->toBe('public');
});

it('names the offending category when an edited row fails validation', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $cat = app(CategoryTreeService::class)->create($site, 'Cakes');

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->set('visibility.'.$cat->id, 'public')
        ->call('saveTree', $cat->id)
        ->assertHasErrors(['visibility.'.$cat->id]);

    expect($cat->fresh()->visibility)->toBe('visible');
});

it('rejects invalid visibility and sort values on saveTree', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $cat = app(CategoryTreeService::class)->create($site, 'Cakes');

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->set('visibility.'.$cat->id, 'public')
        ->call('saveTree', $cat->id)
        ->assertHasErrors(['visibility.'.$cat->id]);

    expect($cat->fresh()->visibility)->toBe('visible');

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->set('sort.'.$cat->id, 'alpha')
        ->call('saveTree', $cat->id)
        ->assertHasErrors(['sort.'.$cat->id]);

    expect($cat->fresh()->sort)->toBe('manual');
});

it('reports a duplicate category name instead of erroring', function () {
    [$site] = [Site::factory()->create(['created_by_user_id' => User::factory()->staff(AgentRole::Admin)->create()->id])];
    $this->actingAs(User::find($site->created_by_user_id));
    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->set('newName', 'Cakes')->call('addCategory')
        ->set('newName', 'Cakes')->call('addCategory')
        ->assertHasErrors(['newName']);
    expect(Category::query()->where('site_id', $site->id)->count())->toBe(1);
});

test('a category manager mounted while the shop was on fails closed once the flag is turned off', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id, 'shop_enabled' => true]);
    $this->actingAs($user);

    $component = Livewire::test('shop.category-manager', ['siteId' => $site->id]);

    $site->update(['shop_enabled' => false]);

    $component->set('newName', 'Bouquets')->call('addCategory')->assertStatus(404);
    expect(Category::where('site_id', $site->id)->count())->toBe(0);
});

it('orders sibling categories by sort_order rather than alphabetical path', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $service = app(CategoryTreeService::class);
    $cakes = $service->create($site, 'Cakes');
    $apple = $service->create($site, 'Apple', $cakes, ['sort_order' => 20]);
    $zebra = $service->create($site, 'Zebra', $cakes, ['sort_order' => 10]);

    $html = Livewire::test('shop.category-manager', ['siteId' => $site->id])->html();
    $zebraPos = strpos($html, 'data-category-id="'.$zebra->id.'"');
    $applePos = strpos($html, 'data-category-id="'.$apple->id.'"');

    expect($zebraPos)->not->toBeFalse()
        ->and($applePos)->not->toBeFalse()
        ->and($zebraPos)->toBeLessThan($applePos);
});

it('renders a compact tree with product counts, depth rails, badges and no per-row form', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $service = app(CategoryTreeService::class);
    $cakes = $service->create($site, 'Celebration Cakes');
    $birthdays = $service->create($site, 'Birthdays', $cakes);
    $gifting = $service->create($site, 'Gifting', attrs: ['is_anchor' => false, 'visibility' => 'hidden']);

    Product::factory()->for($site)->count(2)->create()->each(
        fn (Product $product) => $product->categories()->attach($cakes->id, ['is_primary' => true]),
    );
    Product::factory()->for($site)->create()->categories()->attach($birthdays->id, ['is_primary' => true]);

    $html = Livewire::test('shop.category-manager', ['siteId' => $site->id])->html();

    expect($html)->toContain('data-category-id="'.$cakes->id.'"')
        ->and($html)->toContain('data-depth="1"')
        ->and($html)->toContain('data-depth="2"')
        ->and($html)->toContain('data-product-count="2"')
        ->and($html)->toContain('data-product-count="1"')
        ->and($html)->toContain('category-tree-rail')
        ->and($html)->toContain('Visible')
        ->and($html)->toContain('Hidden')
        ->and($html)->toContain('Anchor')
        ->and($html)->toContain('id="category-row-'.$cakes->id.'"')
        ->and($html)->toContain('Move to')
        ->and($html)->toContain('Hide')
        ->and($html)->toContain('Show')
        ->and($html)->toContain('Delete')
        ->and($html)->not->toContain('placeholder="Meta title"')
        ->and($html)->not->toContain('placeholder="Meta description"')
        ->and($html)->not->toContain('>Save<');

    expect($html)->toContain('Anchor')
        ->and(substr_count($html, 'data-is-anchor="true"'))->toBe(2)
        ->and(substr_count($html, 'data-is-anchor="false"'))->toBe(1);
});

it('shows a storefront path that opens the live category page when the site has a public host', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $service = app(CategoryTreeService::class);
    $cakes = $service->create($site, 'Cakes');
    $service->create($site, 'Wedding Cakes', $cakes);

    $html = Livewire::test('shop.category-manager', ['siteId' => $site->id])->html();
    $host = $site->fresh()->publicHost();

    expect($host)->not->toBeNull()
        ->and($html)->toContain('/collections/cakes')
        ->and($html)->toContain('/collections/cakes/wedding-cakes')
        ->and($html)->toContain('https://'.$host.'/collections/cakes')
        ->and($html)->toContain('target="_blank"');
});

it('hides the storefront path when the site has no public host', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $site->forceFill(['preview_domain' => null, 'custom_domain' => null, 'custom_domain_status' => null])->save();
    $this->actingAs($user);
    app(CategoryTreeService::class)->create($site, 'Cakes');

    $html = Livewire::test('shop.category-manager', ['siteId' => $site->id])->html();

    expect($site->fresh()->publicHost())->toBeNull()
        ->and($html)->toContain('Cakes')
        ->and($html)->not->toContain('/collections/');
});

it('puts a disclosure caret on parents so children can be collapsed', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $service = app(CategoryTreeService::class);
    $cakes = $service->create($site, 'Cakes');
    $wedding = $service->create($site, 'Wedding Cakes', $cakes);

    $html = Livewire::test('shop.category-manager', ['siteId' => $site->id])->html();

    expect($html)->toContain('data-disclosure-id="'.$cakes->id.'"')
        ->and($html)->toContain('aria-expanded')
        ->and($html)->not->toContain('data-disclosure-id="'.$wedding->id.'"');
});

it('names the detail view from the category heading', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $cat = app(CategoryTreeService::class)->create($site, 'Cakes');

    $html = Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $cat->id)
        ->html();

    expect($html)->toContain('id="category-detail-heading"')
        ->and($html)->toContain('Categories')
        ->and($html)->not->toContain('Categories › Cakes')
        ->and($html)->toContain('data-category-detail="'.$cat->id.'"')
        ->and($html)->not->toContain('data-modal="category-editor"');
});

it('opens the edit panel from the row, saves through it, and closes on success', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $service = app(CategoryTreeService::class);
    $cakes = $service->create($site, 'Cakes');
    $gifting = $service->create($site, 'Gifting');

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $gifting->id)
        ->assertSet('editingId', $gifting->id)
        ->assertSet('editName', 'Gifting')
        ->assertDontSee('data-flux-flyout', false)
        ->assertSee('data-category-detail="'.$gifting->id.'"', false)
        ->set('editName', 'Hampers')
        ->set('parentId.'.$gifting->id, $cakes->id)
        ->set('visibility.'.$gifting->id, 'hidden')
        ->set('isAnchor.'.$gifting->id, false)
        ->set('sort.'.$gifting->id, 'newest')
        ->set('metaTitle.'.$gifting->id, 'Hampers title')
        ->set('metaDescription.'.$gifting->id, 'Gift boxes.')
        ->call('saveEditor')
        ->assertHasNoErrors()
        ->assertSet('editingId', null);

    expect($gifting->fresh()->name)->toBe('Hampers')
        ->and($gifting->fresh()->parent_id)->toBe($cakes->id)
        ->and($gifting->fresh()->visibility)->toBe('hidden')
        ->and($gifting->fresh()->is_anchor)->toBeFalse()
        ->and($gifting->fresh()->sort)->toBe('newest')
        ->and($gifting->fresh()->meta_title)->toBe('Hampers title')
        ->and($gifting->fresh()->meta_description)->toBe('Gift boxes.');
});

it('renders validation errors inline in the open edit panel', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $cat = app(CategoryTreeService::class)->create($site, 'Cakes');

    $html = Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $cat->id)
        ->set('visibility.'.$cat->id, 'public')
        ->call('saveEditor')
        ->assertHasErrors(['visibility.'.$cat->id])
        ->assertSet('editingId', $cat->id)
        ->html();

    expect($html)->toMatch('/Cakes:.*visibility/i')
        ->and($html)->toContain('data-category-detail="'.$cat->id.'"');

    expect($cat->fresh()->visibility)->toBe('visible');
});

it('cancels the edit panel without saving and returns focus to the row', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $cat = app(CategoryTreeService::class)->create($site, 'Cakes');

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $cat->id)
        ->set('editName', 'Nope')
        ->call('cancelEditor')
        ->assertSet('editingId', null)
        ->assertDispatched('modal-close', name: 'category-editor')
        ->assertJs('document.getElementById('.json_encode('category-row-'.$cat->id).')?.focus()');

    expect($cat->fresh()->name)->toBe('Cakes');
});

it('discards every edited flyout field on cancel so a later save does not persist them', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $service = app(CategoryTreeService::class);
    $cakes = $service->create($site, 'Cakes');
    $gifting = $service->create($site, 'Gifting', attrs: [
        'visibility' => 'visible',
        'is_anchor' => true,
        'sort' => 'manual',
        'meta_title' => 'Orig title',
        'meta_description' => 'Orig desc',
    ]);

    $component = Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $gifting->id)
        ->set('editName', 'Abandoned')
        ->set('parentId.'.$gifting->id, $cakes->id)
        ->set('visibility.'.$gifting->id, 'hidden')
        ->set('isAnchor.'.$gifting->id, false)
        ->set('sort.'.$gifting->id, 'newest')
        ->set('metaTitle.'.$gifting->id, 'Abandoned title')
        ->set('metaDescription.'.$gifting->id, 'Abandoned desc')
        ->call('cancelEditor')
        ->assertSet('editingId', null)
        ->assertSet('editName', '')
        ->assertSet('parentId.'.$gifting->id, null)
        ->assertSet('visibility.'.$gifting->id, 'visible')
        ->assertSet('isAnchor.'.$gifting->id, true)
        ->assertSet('sort.'.$gifting->id, 'manual')
        ->assertSet('metaTitle.'.$gifting->id, 'Orig title')
        ->assertSet('metaDescription.'.$gifting->id, 'Orig desc')
        ->assertDispatched('modal-close', name: 'category-editor');

    expect($gifting->fresh()->name)->toBe('Gifting')
        ->and($gifting->fresh()->parent_id)->toBeNull()
        ->and($gifting->fresh()->visibility)->toBe('visible')
        ->and($gifting->fresh()->is_anchor)->toBeTrue()
        ->and($gifting->fresh()->sort)->toBe('manual')
        ->and($gifting->fresh()->meta_title)->toBe('Orig title')
        ->and($gifting->fresh()->meta_description)->toBe('Orig desc');

    $component
        ->call('openEditor', $gifting->id)
        ->assertSet('editName', 'Gifting')
        ->assertSet('parentId.'.$gifting->id, null)
        ->assertSet('visibility.'.$gifting->id, 'visible')
        ->assertSet('isAnchor.'.$gifting->id, true)
        ->assertSet('sort.'.$gifting->id, 'manual')
        ->assertSet('metaTitle.'.$gifting->id, 'Orig title')
        ->assertSet('metaDescription.'.$gifting->id, 'Orig desc')
        ->call('saveEditor')
        ->assertHasNoErrors()
        ->assertSet('editingId', null);

    expect($gifting->fresh()->name)->toBe('Gifting')
        ->and($gifting->fresh()->parent_id)->toBeNull()
        ->and($gifting->fresh()->visibility)->toBe('visible')
        ->and($gifting->fresh()->is_anchor)->toBeTrue()
        ->and($gifting->fresh()->sort)->toBe('manual')
        ->and($gifting->fresh()->meta_title)->toBe('Orig title')
        ->and($gifting->fresh()->meta_description)->toBe('Orig desc');
});

it('discards every edited flyout field on Esc close so a later save does not persist them', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $service = app(CategoryTreeService::class);
    $cakes = $service->create($site, 'Cakes');
    $gifting = $service->create($site, 'Gifting', attrs: [
        'visibility' => 'visible',
        'is_anchor' => true,
        'sort' => 'manual',
        'meta_title' => 'Orig title',
        'meta_description' => 'Orig desc',
    ]);

    $component = Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $gifting->id)
        ->set('editName', 'Abandoned')
        ->set('parentId.'.$gifting->id, $cakes->id)
        ->set('visibility.'.$gifting->id, 'hidden')
        ->set('isAnchor.'.$gifting->id, false)
        ->set('sort.'.$gifting->id, 'newest')
        ->set('metaTitle.'.$gifting->id, 'Abandoned title')
        ->set('metaDescription.'.$gifting->id, 'Abandoned desc')
        ->call('closeEditor')
        ->assertSet('editingId', null)
        ->assertSet('parentId.'.$gifting->id, null)
        ->assertSet('visibility.'.$gifting->id, 'visible')
        ->assertSet('isAnchor.'.$gifting->id, true)
        ->assertSet('sort.'.$gifting->id, 'manual')
        ->assertSet('metaTitle.'.$gifting->id, 'Orig title')
        ->assertSet('metaDescription.'.$gifting->id, 'Orig desc')
        ->assertJs('document.getElementById('.json_encode('category-row-'.$gifting->id).')?.focus()');

    $component
        ->call('openEditor', $gifting->id)
        ->call('saveEditor')
        ->assertHasNoErrors();

    expect($gifting->fresh()->name)->toBe('Gifting')
        ->and($gifting->fresh()->parent_id)->toBeNull()
        ->and($gifting->fresh()->visibility)->toBe('visible')
        ->and($gifting->fresh()->is_anchor)->toBeTrue()
        ->and($gifting->fresh()->sort)->toBe('manual')
        ->and($gifting->fresh()->meta_title)->toBe('Orig title')
        ->and($gifting->fresh()->meta_description)->toBe('Orig desc');
});

it('deletes from the edit panel with a confirm that names the product count', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $cat = app(CategoryTreeService::class)->create($site, 'Cakes');
    Product::factory()->for($site)->count(2)->create()->each(
        fn (Product $product) => $product->categories()->attach($cat->id, ['is_primary' => true]),
    );

    $html = Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $cat->id)
        ->html();

    expect($html)->toMatch('/2 products will be unassigned/');

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $cat->id)
        ->call('delete', $cat->id)
        ->assertSet('editingId', null);

    expect(Category::where('site_id', $site->id)->count())->toBe(0);
});

it('opens the editor for a category and shows its meta counters', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $cat = app(CategoryTreeService::class)->create($site, 'Cakes');

    $panel = Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $cat->id)
        ->assertSet('editingId', $cat->id)
        ->assertSet('editName', 'Cakes');

    expect($panel->html())->toContain('0/70')
        ->and($panel->html())->toContain('0/170')
        ->and($panel->html())->toContain('Sort products by');
});

it('uses Add category, the sort labels, and the empty-state copy', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);

    $empty = Livewire::test('shop.category-manager', ['siteId' => $site->id])->html();

    expect($empty)->toContain('Add category')
        ->and($empty)->toContain('No categories yet — add one to start organising the shop.')
        ->and($empty)->not->toContain('Add a category to organise the catalogue.');

    $cat = app(CategoryTreeService::class)->create($site, 'Cakes');
    $panel = Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $cat->id)
        ->html();

    expect($panel)->toContain('Sort products by')
        ->and($panel)->toContain('Manual')
        ->and($panel)->toContain('Name')
        ->and($panel)->toContain('Newest')
        ->and($panel)->toContain('Price ↑')
        ->and($panel)->toContain('Price ↓');
});

it('opens the editor from the row and restores focus when the review closes', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $cat = app(CategoryTreeService::class)->create($site, 'Cakes');

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $cat->id)
        ->assertSet('editingId', $cat->id)
        ->call('closeEditor')
        ->assertSet('editingId', null)
        ->assertJs('document.getElementById('.json_encode('category-row-'.$cat->id).')?.focus()');
});

it('rejects client updates to the locked site id', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);

    $component = Livewire::test('shop.category-manager', ['siteId' => $site->id]);

    expect(fn () => $component->set('siteId', $site->id + 1))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('shows a 40px thumbnail for a category hero image and a placeholder otherwise', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $service = app(CategoryTreeService::class);
    $withImage = $service->create($site, 'Cakes');
    $withImage->forceFill(['hero_image_url' => 'https://cdn.example/cakes.jpg'])->save();
    $plain = $service->create($site, 'Gifting');

    $html = Livewire::test('shop.category-manager', ['siteId' => $site->id])->html();

    expect($html)->toContain('https://cdn.example/cakes.jpg')
        ->and($html)->toContain('h-10 w-10')
        ->and($html)->toContain('data-category-thumb="'.$withImage->id.'"')
        ->and($html)->toContain('data-category-thumb-placeholder="'.$plain->id.'"');
});

it('hides and shows the selected categories through the bulk bar', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $service = app(CategoryTreeService::class);
    $cakes = $service->create($site, 'Cakes');
    $gifting = $service->create($site, 'Gifting');
    $untouched = $service->create($site, 'Weddings');

    $component = Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->set('selected.'.$cakes->id, true)
        ->set('selected.'.$gifting->id, true);

    expect($component->html())->toContain("bulkSetVisibility('hidden')")
        ->and($component->html())->toContain("bulkSetVisibility('visible')");

    $component->call('bulkSetVisibility', 'hidden')->assertHasNoErrors();

    expect($cakes->fresh()->visibility)->toBe('hidden')
        ->and($gifting->fresh()->visibility)->toBe('hidden')
        ->and($untouched->fresh()->visibility)->toBe('visible');

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->set('selected.'.$cakes->id, true)
        ->set('selected.'.$gifting->id, true)
        ->call('bulkSetVisibility', 'visible')
        ->assertHasNoErrors();

    expect($cakes->fresh()->visibility)->toBe('visible')
        ->and($gifting->fresh()->visibility)->toBe('visible');
});

it('does not bulk-change visibility when the shop flag is off', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id, 'shop_enabled' => true]);
    $this->actingAs($user);
    $cat = app(CategoryTreeService::class)->create($site, 'Cakes');

    $component = Livewire::test('shop.category-manager', ['siteId' => $site->id]);
    $site->update(['shop_enabled' => false]);

    $component->set('selected.'.$cat->id, true)
        ->call('bulkSetVisibility', 'hidden')
        ->assertStatus(404);

    expect($cat->fresh()->visibility)->toBe('visible');
});

it('toggles visibility from the row menu without opening a form', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $cat = app(CategoryTreeService::class)->create($site, 'Cakes');

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('toggleVisibility', $cat->id)
        ->assertHasNoErrors();

    expect($cat->fresh()->visibility)->toBe('hidden');

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('toggleVisibility', $cat->id)
        ->assertSee('Hide');

    expect($cat->fresh()->visibility)->toBe('visible');
});

it('refuses a missing or cross-site parent on setParent instead of re-rooting', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $foreignSite = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $service = app(CategoryTreeService::class);
    $cakes = $service->create($site, 'Cakes');
    $wedding = $service->create($site, 'Wedding Cakes', $cakes);
    $foreign = $service->create($foreignSite, 'Foreign');

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('setParent', $wedding->id, $foreign->id)
        ->assertHasErrors(['parentId.'.$wedding->id])
        ->assertSet('editingId', $wedding->id)
        ->assertSee('Category not found.');

    expect($wedding->fresh()->parent_id)->toBe($cakes->id)
        ->and($wedding->fresh()->path)->toBe('cakes/wedding-cakes');

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('setParent', $wedding->id, $wedding->id + 999999)
        ->assertHasErrors(['parentId.'.$wedding->id])
        ->assertSet('editingId', $wedding->id);

    expect($wedding->fresh()->parent_id)->toBe($cakes->id)
        ->and($wedding->fresh()->path)->toBe('cakes/wedding-cakes');
});

it('refuses a missing or cross-site parent on the flyout save instead of re-rooting', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $foreignSite = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $service = app(CategoryTreeService::class);
    $cakes = $service->create($site, 'Cakes');
    $wedding = $service->create($site, 'Wedding Cakes', $cakes);
    $foreign = $service->create($foreignSite, 'Foreign');

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $wedding->id)
        ->set('parentId.'.$wedding->id, $foreign->id)
        ->call('saveEditor')
        ->assertHasErrors(['parentId.'.$wedding->id])
        ->assertSet('editingId', $wedding->id)
        ->assertSee('Category not found.');

    expect($wedding->fresh()->parent_id)->toBe($cakes->id)
        ->and($wedding->fresh()->name)->toBe('Wedding Cakes')
        ->and($wedding->fresh()->path)->toBe('cakes/wedding-cakes');
});

it('lets an explicit empty parent re-root a category to the top level', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $service = app(CategoryTreeService::class);
    $cakes = $service->create($site, 'Cakes');
    $wedding = $service->create($site, 'Wedding Cakes', $cakes);

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('setParent', $wedding->id, 0)
        ->assertHasNoErrors();

    expect($wedding->fresh()->parent_id)->toBeNull()
        ->and($wedding->fresh()->path)->toBe('wedding-cakes');
});

it('refuses a cross-site parent for a client-portal user instead of re-rooting', function () {
    $tenant = Client::factory()->create();
    $client = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);
    $foreignSite = Site::factory()->create();
    $this->actingAs($client);
    $service = app(CategoryTreeService::class);
    $cakes = $service->create($site, 'Cakes');
    $wedding = $service->create($site, 'Wedding Cakes', $cakes);
    $foreign = $service->create($foreignSite, 'Foreign');

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('setParent', $wedding->id, $foreign->id)
        ->assertHasErrors(['parentId.'.$wedding->id])
        ->assertSet('editingId', $wedding->id)
        ->assertSee('Category not found.');

    expect($wedding->fresh()->parent_id)->toBe($cakes->id)
        ->and($wedding->fresh()->path)->toBe('cakes/wedding-cakes');

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $wedding->id)
        ->set('parentId.'.$wedding->id, $foreign->id)
        ->call('saveEditor')
        ->assertHasErrors(['parentId.'.$wedding->id])
        ->assertSet('editingId', $wedding->id);

    expect($wedding->fresh()->parent_id)->toBe($cakes->id);
});

it('computes subtree height and descendants from loaded rows without per-category queries', function () {
    $user = User::factory()->staff()->create();
    $this->actingAs($user);
    $service = app(CategoryTreeService::class);

    $small = Site::factory()->create(['created_by_user_id' => $user->id]);
    $cakes = $service->create($small, 'Cakes');
    $wedding = $service->create($small, 'Wedding Cakes', $cakes);
    $tiered = $service->create($small, 'Tiered', $wedding);

    $large = Site::factory()->create(['created_by_user_id' => $user->id]);
    foreach (range(1, 10) as $i) {
        $parent = $service->create($large, 'Parent '.$i);
        $service->create($large, 'Child A '.$i, $parent);
        $service->create($large, 'Child B '.$i, $parent);
    }

    $mountSmall = categoryManagerQueryCount(
        fn () => Livewire::test('shop.category-manager', ['siteId' => $small->id]),
    );
    $mountLarge = categoryManagerQueryCount(
        fn () => Livewire::test('shop.category-manager', ['siteId' => $large->id]),
    );

    expect($mountSmall)->toBe($mountLarge);

    $smallComponent = Livewire::test('shop.category-manager', ['siteId' => $small->id]);
    $cakesRow = collect($smallComponent->get('categories'))->firstWhere('id', $cakes->id);
    $optionIds = collect($cakesRow['parent_options'])->pluck('id');
    expect($optionIds)->not->toContain($wedding->id)
        ->and($optionIds)->not->toContain($tiered->id);

    $mutateSmall = categoryManagerQueryCount(function () use ($small, $tiered): void {
        Livewire::test('shop.category-manager', ['siteId' => $small->id])
            ->call('toggleVisibility', $tiered->id);
    });
    $leaf = Category::query()->where('site_id', $large->id)->where('depth', 2)->first();
    $mutateLarge = categoryManagerQueryCount(function () use ($large, $leaf): void {
        Livewire::test('shop.category-manager', ['siteId' => $large->id])
            ->call('toggleVisibility', $leaf->id);
    });

    expect($mutateSmall)->toBe($mutateLarge);
});

it('cascades bulk hide and show to the whole subtree', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $service = app(CategoryTreeService::class);
    $cakes = $service->create($site, 'Cakes');
    $wedding = $service->create($site, 'Wedding Cakes', $cakes);
    $tiered = $service->create($site, 'Tiered', $wedding);
    $gifting = $service->create($site, 'Gifting');

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->set('selected.'.$cakes->id, true)
        ->call('bulkSetVisibility', 'hidden')
        ->assertHasNoErrors();

    expect($cakes->fresh()->visibility)->toBe('hidden')
        ->and($wedding->fresh()->visibility)->toBe('hidden')
        ->and($tiered->fresh()->visibility)->toBe('hidden')
        ->and($gifting->fresh()->visibility)->toBe('visible');

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->set('selected.'.$cakes->id, true)
        ->call('bulkSetVisibility', 'visible')
        ->assertHasNoErrors();

    expect($cakes->fresh()->visibility)->toBe('visible')
        ->and($wedding->fresh()->visibility)->toBe('visible')
        ->and($tiered->fresh()->visibility)->toBe('visible');
});

it('cascades a single-category hide to the subtree', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $service = app(CategoryTreeService::class);
    $cakes = $service->create($site, 'Cakes');
    $wedding = $service->create($site, 'Wedding Cakes', $cakes);
    $tiered = $service->create($site, 'Tiered', $wedding);

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('toggleVisibility', $cakes->id)
        ->assertHasNoErrors();

    expect($cakes->fresh()->visibility)->toBe('hidden')
        ->and($wedding->fresh()->visibility)->toBe('hidden')
        ->and($tiered->fresh()->visibility)->toBe('hidden');
});

it('drops a hidden subtree from category_paths and 404s the nested public url', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $user->id,
        'custom_domain' => 'hidden-subtree.example',
        'custom_domain_status' => 'active',
        'business_name' => 'Bloom & Stem',
    ]);
    $this->actingAs($user);
    $service = app(CategoryTreeService::class);
    $cakes = $service->create($site, 'Cakes');
    $wedding = $service->create($site, 'Wedding Cakes', $cakes);
    Product::factory()->for($site)->published()->create();

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->set('selected.'.$cakes->id, true)
        ->call('bulkSetVisibility', 'hidden');

    CommerceReads::drainRebuild($site->id);

    $json = app(SnapshotBuilder::class)->build($site->id);

    expect($json['category_paths'])->not->toHaveKey('cakes/wedding-cakes')
        ->and($wedding->fresh()->visibility)->toBe('hidden')
        ->and($cakes->fresh()->visibility)->toBe('hidden');

    $this->get('http://hidden-subtree.example/collections/cakes/wedding-cakes')->assertNotFound();
});

it('aborts a mixed bulk selection without changing either site', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $other = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $service = app(CategoryTreeService::class);
    $own = $service->create($site, 'Cakes');
    $sibling = $service->create($site, 'Gifting');
    $foreign = $service->create($other, 'Foreign');

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->set('selected.'.$own->id, true)
        ->set('selected.'.$sibling->id, true)
        ->set('selected.'.$foreign->id, true)
        ->call('bulkSetVisibility', 'hidden')
        ->assertHasErrors(['selected']);

    expect($own->fresh()->visibility)->toBe('visible')
        ->and($sibling->fresh()->visibility)->toBe('visible')
        ->and($foreign->fresh()->visibility)->toBe('visible');

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->set('selected.'.$own->id, true)
        ->set('selected.'.($own->id + 999999), true)
        ->call('bulkSetVisibility', 'hidden')
        ->assertHasErrors(['selected']);

    expect($own->fresh()->visibility)->toBe('visible');
});

it('rejects an empty or too-long rename instead of persisting it', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $cat = app(CategoryTreeService::class)->create($site, 'Cakes');

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('rename', $cat->id, '')
        ->assertHasErrors(['name']);

    expect($cat->fresh()->name)->toBe('Cakes');

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('rename', $cat->id, str_repeat('a', 121))
        ->assertHasErrors(['name']);

    expect($cat->fresh()->name)->toBe('Cakes');
});

it('renders a full-page detail view for a selected category instead of a modal', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $cat = app(CategoryTreeService::class)->create($site, 'Cakes');

    $html = Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $cat->id)
        ->assertSet('editingId', $cat->id)
        ->html();

    expect($html)->toContain('data-category-detail="'.$cat->id.'"')
        ->and($html)->toContain('id="category-detail-heading"')
        ->and($html)->toContain('Cakes')
        ->and($html)->toContain('Add description')
        ->and($html)->toContain('Settings')
        ->and($html)->toContain('Content')
        ->and($html)->toContain('Parent')
        ->and($html)->toContain('Visibility')
        ->and($html)->toContain('Anchor')
        ->and($html)->toContain('Meta title')
        ->and($html)->toContain('Meta description')
        ->and($html)->toContain('Category items')
        ->and($html)->not->toContain('data-modal="category-editor"')
        ->and($html)->not->toContain('data-category-id="'.$cat->id.'"');
});

it('returns to the tree from the detail breadcrumb without saving edits', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $cat = app(CategoryTreeService::class)->create($site, 'Cakes');

    $component = Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $cat->id)
        ->set('editName', 'Abandoned')
        ->call('closeEditor')
        ->assertSet('editingId', null);

    expect($component->html())->toContain('data-category-id="'.$cat->id.'"')
        ->and($component->html())->not->toContain('data-category-detail="'.$cat->id.'"')
        ->and($cat->fresh()->name)->toBe('Cakes');
});

it('shows View for a visible category with a public host and hides it when the category is hidden', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $service = app(CategoryTreeService::class);
    $visible = $service->create($site, 'Cakes');
    $hidden = $service->create($site, 'Secret', attrs: ['visibility' => 'hidden']);
    $host = $site->fresh()->publicHost();

    expect($host)->not->toBeNull();

    $visibleHtml = Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $visible->id)
        ->html();

    expect($visibleHtml)->toContain('>View<')
        ->and($visibleHtml)->toContain('https://'.$host.'/collections/cakes')
        ->and($visibleHtml)->toContain('target="_blank"');

    $hiddenHtml = Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $hidden->id)
        ->html();

    expect($hiddenHtml)->not->toMatch('/>View</')
        ->and($hiddenHtml)->toContain('Delete');
});

it('keeps rename, visibility and anchor working from the detail view', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $cat = app(CategoryTreeService::class)->create($site, 'Cakes');

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $cat->id)
        ->call('rename', $cat->id, 'Gateaux')
        ->assertSet('editingId', $cat->id)
        ->assertHasNoErrors();

    expect($cat->fresh()->name)->toBe('Gateaux');

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $cat->id)
        ->set('visibility.'.$cat->id, 'hidden')
        ->set('isAnchor.'.$cat->id, false)
        ->call('saveEditor')
        ->assertHasNoErrors();

    expect($cat->fresh()->visibility)->toBe('hidden')
        ->and($cat->fresh()->is_anchor)->toBeFalse();
});

it('loads category products with status and primary image on the detail view', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $service = app(CategoryTreeService::class);
    $cat = $service->create($site, 'Cakes');

    $published = Product::factory()->for($site)->published()->create(['name' => 'Victoria Sponge']);
    ProductImage::query()->create([
        'product_id' => $published->id,
        'path' => 'shop/products/victoria.png',
        'sort_order' => 0,
        'alt' => 'Victoria',
    ]);
    $published->categories()->attach($cat->id, ['is_primary' => true]);

    $draft = Product::factory()->for($site)->create([
        'name' => 'Draft Tart',
        'status' => ProductStatus::Draft,
    ]);
    $draft->categories()->attach($cat->id, ['is_primary' => true]);

    $html = Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $cat->id)
        ->html();

    expect($html)->toContain('Victoria Sponge')
        ->and($html)->toContain('Draft Tart')
        ->and($html)->toContain('shop/products/victoria.png')
        ->and($html)->toContain('data-product-card="'.$published->id.'"')
        ->and($html)->toContain('data-product-card="'.$draft->id.'"')
        ->and($html)->toContain('data-product-status="draft"')
        ->and($html)->toContain('Draft')
        ->and($html)->toContain(route('shop.admin.products.edit', ['site' => $site->id, 'product' => $published->id]));
});

it('includes child-category products in the items count when the category is an anchor', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $service = app(CategoryTreeService::class);
    $cakes = $service->create($site, 'Cakes');
    $wedding = $service->create($site, 'Wedding Cakes', $cakes);

    $own = Product::factory()->for($site)->create(['name' => 'Own Sponge']);
    $child = Product::factory()->for($site)->create(['name' => 'Child Tier']);
    $own->categories()->attach($cakes->id, ['is_primary' => true]);
    $child->categories()->attach($wedding->id, ['is_primary' => true]);

    $html = Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $cakes->id)
        ->html();

    expect($html)->toContain('Own Sponge')
        ->and($html)->toContain('Child Tier')
        ->and($html)->toContain('data-items-count="2"');
});

it('narrows the rendered product set through the status filter pills', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $cat = app(CategoryTreeService::class)->create($site, 'Cakes');

    $published = Product::factory()->for($site)->published()->create(['name' => 'Live Victoria']);
    $draft = Product::factory()->for($site)->create(['name' => 'Draft Lemon', 'status' => ProductStatus::Draft]);
    $archived = Product::factory()->for($site)->create(['name' => 'Archived Mocha', 'status' => ProductStatus::Archived]);
    foreach ([$published, $draft, $archived] as $product) {
        $product->categories()->attach($cat->id, ['is_primary' => true]);
    }

    $component = Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $cat->id);

    $all = $component->html();
    expect($all)->toContain('Live Victoria')
        ->and($all)->toContain('Draft Lemon')
        ->and($all)->toContain('Archived Mocha')
        ->and($all)->toContain('aria-pressed="true"')
        ->and($all)->toContain('>All<')
        ->and($all)->toContain('>Published<')
        ->and($all)->toContain('>Draft<')
        ->and($all)->toContain('>Archived<');

    $draftHtml = $component->set('itemsStatusFilter', 'draft')->html();
    expect($draftHtml)->toContain('Draft Lemon')
        ->and($draftHtml)->not->toContain('Live Victoria')
        ->and($draftHtml)->not->toContain('Archived Mocha');
});

it('persists Sort products by from the detail items toolbar', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $cat = app(CategoryTreeService::class)->create($site, 'Cakes');

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $cat->id)
        ->set('sort.'.$cat->id, 'name')
        ->call('saveTree', $cat->id)
        ->assertHasNoErrors()
        ->assertSet('editingId', $cat->id);

    expect($cat->fresh()->sort)->toBe('name');
});

it('renders a showing-60-of-N line when the category is over the items cap', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $cat = app(CategoryTreeService::class)->create($site, 'Cakes');

    Product::factory()->for($site)->count(61)->create()->each(
        fn (Product $product) => $product->categories()->attach($cat->id, ['is_primary' => true]),
    );

    $html = Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $cat->id)
        ->html();

    expect($html)->toContain('showing 60 of 61')
        ->and($html)->toContain('data-items-cap="60"');
});

it('renders the empty items state with a link to the products list', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $cat = app(CategoryTreeService::class)->create($site, 'Cakes');

    $html = Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $cat->id)
        ->html();

    expect($html)->toContain('No products in this category yet')
        ->and($html)->toContain(route('sites.shop.products', $site));
});

it('toggles items between grid and list with aria-pressed on the view buttons', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $cat = app(CategoryTreeService::class)->create($site, 'Cakes');
    $product = Product::factory()->for($site)->create(['name' => 'Grid Cake', 'price_from' => true]);
    ProductVariant::factory()->for($product)->create(['price_cents' => 1250]);
    $product->categories()->attach($cat->id, ['is_primary' => true]);

    $component = Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $cat->id);

    $grid = $component->html();
    expect($grid)->toContain('data-items-view="grid"')
        ->and($grid)->toContain('aria-pressed="true"')
        ->and($grid)->toContain('Grid Cake');

    $list = $component->set('itemsView', 'list')->html();
    expect($list)->toContain('data-items-view="list"')
        ->and($list)->toContain('Grid Cake')
        ->and($list)->toContain('from £12.50');
});

it('renders a Hero card on the category detail rail between Settings and Content', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $cat = app(CategoryTreeService::class)->create($site, 'Cakes');
    $cat->forceFill(['hero_image_url' => 'https://cdn.example/cakes-hero.jpg'])->save();

    $html = Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $cat->id)
        ->html();

    $settingsPos = strpos($html, '>Settings<');
    $heroPos = strpos($html, 'data-category-hero-card');
    $contentPos = strpos($html, '>Content<');

    expect($html)->toContain('data-category-hero-card')
        ->and($html)->toContain('Hero Height')
        ->and($html)->toContain('Hero Width')
        ->and($html)->toContain("setCategoryHeroHeight({$cat->id}")
        ->and($html)->toContain("setCategoryHeroMode({$cat->id}")
        ->and($html)->not->toContain("setCategoryHeroEnabled({$cat->id}")
        ->and($html)->toContain("setCategoryHeroWidth({$cat->id}")
        ->and($html)->toContain("setCategoryTextZone({$cat->id}")
        ->and($html)->toContain("resetCategoryTextZone({$cat->id})")
        ->and($html)->toContain("generateCategoryHero({$cat->id})")
        ->and($html)->toContain('setCategoryBgPositionY')
        ->and($html)->toContain("setCategoryHeroTextStyle({$cat->id}")
        ->and($html)->toContain("resetCategoryHeroTextStyle({$cat->id})")
        ->and($settingsPos)->not->toBeFalse()
        ->and($heroPos)->not->toBeFalse()
        ->and($contentPos)->not->toBeFalse()
        ->and($settingsPos)->toBeLessThan($heroPos)
        ->and($heroPos)->toBeLessThan($contentPos);
});

it('shows an Intro band toggle on the category Hero card', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $cat = app(CategoryTreeService::class)->create($site, 'Cakes');

    $html = Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $cat->id)
        ->html();

    expect($html)->toContain('data-category-intro-band-toggle')
        ->and($html)->toContain('Intro band')
        ->and($html)->toContain("setCategoryIntroBand({$cat->id}")
        ->and($html)->toContain('Tinted band with the category image and name above the products');
});

it('hides per-category hero image controls unless mode is Custom', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $cat = app(CategoryTreeService::class)->create($site, 'Cakes');

    $sharedHtml = Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $cat->id)
        ->call('setCategoryHeroMode', $cat->id, 'shared')
        ->html();

    expect($sharedHtml)->toContain("setCategoryHeroMode({$cat->id}")
        ->and($sharedHtml)->toContain("setCategoryHeroTextStyle({$cat->id}")
        ->and($sharedHtml)->not->toContain("generateCategoryHero({$cat->id})")
        ->and($sharedHtml)->not->toContain("setCategoryHeroHeight({$cat->id}")
        ->and($sharedHtml)->not->toContain("setCategoryHeroWidth({$cat->id}");

    $customHtml = Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $cat->id)
        ->call('setCategoryHeroMode', $cat->id, 'custom')
        ->html();

    expect($customHtml)->toContain("generateCategoryHero({$cat->id})")
        ->and($customHtml)->toContain("setCategoryHeroHeight({$cat->id}")
        ->and($customHtml)->toContain("setCategoryHeroWidth({$cat->id}");
});

it('renders accent-word chips from the category name', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $cat = app(CategoryTreeService::class)->create($site, 'Wedding Cakes');

    $html = Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $cat->id)
        ->html();

    expect($html)->toContain("setCategoryHeroAccentWord({$cat->id}")
        ->and($html)->toContain("resetCategoryHeroAccentWord({$cat->id})")
        ->and($html)->toMatch('/>\s*Wedding\s*</')
        ->and($html)->toMatch('/>\s*Cakes\s*</');
});

it('persists a category hero control from the detail view and triggers rebuild', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $cat = app(CategoryTreeService::class)->create($site, 'Cakes');
    $snapshot = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => [
            'hero_image_url' => null,
            'categories' => [],
            'products' => [],
            'featured_slugs' => [],
            'meta' => [
                'version' => 1,
                'built_at' => now()->toIso8601String(),
                'site_id' => $site->id,
                'product_count' => 0,
            ],
        ],
        'built_at' => now(),
        'hero_height' => 'medium',
        'bg_position_y' => 50,
        'text_zone' => 'middle-left',
    ]);
    ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $snapshot->id]);

    Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $cat->id)
        ->call('setCategoryHeroHeight', $cat->id, 'small')
        ->assertHasNoErrors();

    expect($cat->fresh()->hero_height)->toBe('small')
        ->and(ShopSnapshot::where('site_id', $site->id)->count())->toBeGreaterThanOrEqual(2);
});

it('opens the same crop flow from the header hero tile as the rail card', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);
    $cat = app(CategoryTreeService::class)->create($site, 'Cakes');
    $cat->forceFill(['hero_image_url' => 'https://cdn.example/cakes-hero.jpg'])->save();

    $html = Livewire::test('shop.category-manager', ['siteId' => $site->id])
        ->call('openEditor', $cat->id)
        ->html();

    expect($html)->toContain('data-category-hero-tile')
        ->and($html)->toContain('x-on:click="cropModal = true"')
        ->and($html)->toContain('https://cdn.example/cakes-hero.jpg');
});


