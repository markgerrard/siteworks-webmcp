<?php

use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Site;
use App\Services\Shop\CategoryTreeException;
use App\Services\Shop\CategoryTreeService;

function treeSite(): Site
{
    return Site::factory()->create();
}

function treeService(): CategoryTreeService
{
    return app(CategoryTreeService::class);
}

function expectTreeError(callable $action, string $code): void
{
    try {
        $action();
        expect(false)->toBeTrue('expected CategoryTreeException ['.$code.']');
    } catch (CategoryTreeException $e) {
        expect($e->errorCode)->toBe($code);
    }
}

test('create with no parent stores a depth-1 path equal to the slug', function () {
    $site = treeSite();

    $category = treeService()->create($site, 'Wedding Cakes');

    expect($category->parent_id)->toBeNull()
        ->and($category->slug)->toBe('wedding-cakes')
        ->and($category->path)->toBe('wedding-cakes')
        ->and($category->depth)->toBe(1)
        ->and($category->is_anchor)->toBeTrue()
        ->and($category->visibility)->toBe('visible')
        ->and($category->sort)->toBe('manual');
});

test('create under a parent materialises the slug path and increments depth', function () {
    $site = treeSite();
    $cakes = treeService()->create($site, 'Cakes');

    $wedding = treeService()->create($site, 'Wedding Cakes', $cakes);

    expect($wedding->parent_id)->toBe($cakes->id)
        ->and($wedding->slug)->toBe('wedding-cakes')
        ->and($wedding->path)->toBe('cakes/wedding-cakes')
        ->and($wedding->depth)->toBe(2);
});

test('create rejects a fourth level of nesting', function () {
    $site = treeSite();
    $cakes = treeService()->create($site, 'Cakes');
    $wedding = treeService()->create($site, 'Wedding Cakes', $cakes);
    $tiered = treeService()->create($site, 'Tiered', $wedding);

    expect($tiered->depth)->toBe(3);

    expectTreeError(fn () => treeService()->create($site, 'Fondant', $tiered), 'depth');
});

test('create rejects a slug that is already taken on the site', function () {
    $site = treeSite();
    treeService()->create($site, 'Cakes', null, ['slug' => 'cakes']);

    expectTreeError(
        fn () => treeService()->create($site, 'More Cakes', null, ['slug' => 'cakes']),
        'slug_taken',
    );
});

test('move recomputes the path for the node and its whole subtree', function () {
    $site = treeSite();
    $cakes = treeService()->create($site, 'Cakes');
    $tarts = treeService()->create($site, 'Tarts');
    $wedding = treeService()->create($site, 'Wedding Cakes', $cakes);
    $tiered = treeService()->create($site, 'Tiered', $wedding);

    $moved = treeService()->move($wedding, $tarts);

    expect($moved->parent_id)->toBe($tarts->id)
        ->and($moved->path)->toBe('tarts/wedding-cakes')
        ->and($moved->depth)->toBe(2)
        ->and($tiered->fresh()->path)->toBe('tarts/wedding-cakes/tiered')
        ->and($tiered->fresh()->depth)->toBe(3);
});

test('move to the root clears parent_id and flattens the subtree path', function () {
    $site = treeSite();
    $cakes = treeService()->create($site, 'Cakes');
    $wedding = treeService()->create($site, 'Wedding Cakes', $cakes);

    $moved = treeService()->move($wedding, null);

    expect($moved->parent_id)->toBeNull()
        ->and($moved->path)->toBe('wedding-cakes')
        ->and($moved->depth)->toBe(1);
});

test('move rejects a cycle when the new parent is a descendant', function () {
    $site = treeSite();
    $cakes = treeService()->create($site, 'Cakes');
    $wedding = treeService()->create($site, 'Wedding Cakes', $cakes);
    $tiered = treeService()->create($site, 'Tiered', $wedding);

    expectTreeError(fn () => treeService()->move($cakes, $tiered), 'cycle');
});

test('move rejects placing a subtree deeper than depth 3', function () {
    $site = treeSite();
    $cakes = treeService()->create($site, 'Cakes');
    $wedding = treeService()->create($site, 'Wedding Cakes', $cakes);
    $tiered = treeService()->create($site, 'Tiered', $wedding);
    $tarts = treeService()->create($site, 'Tarts');
    $fruit = treeService()->create($site, 'Fruit Tarts', $tarts);

    expectTreeError(fn () => treeService()->move($wedding, $fruit), 'depth');

    expect($wedding->fresh()->path)->toBe('cakes/wedding-cakes')
        ->and($tiered->fresh()->path)->toBe('cakes/wedding-cakes/tiered');
});

test('rename of a slug recomputes the subtree path', function () {
    $site = treeSite();
    $cakes = treeService()->create($site, 'Cakes');
    $wedding = treeService()->create($site, 'Wedding Cakes', $cakes);
    $tiered = treeService()->create($site, 'Tiered', $wedding);

    $renamed = treeService()->rename($cakes, 'Gateaux', 'gateaux');

    expect($renamed->name)->toBe('Gateaux')
        ->and($renamed->slug)->toBe('gateaux')
        ->and($renamed->path)->toBe('gateaux')
        ->and($wedding->fresh()->path)->toBe('gateaux/wedding-cakes')
        ->and($tiered->fresh()->path)->toBe('gateaux/wedding-cakes/tiered');
});

test('delete re-parents children to the deleted node parent and recomputes their paths', function () {
    $site = treeSite();
    $cakes = treeService()->create($site, 'Cakes');
    $wedding = treeService()->create($site, 'Wedding Cakes', $cakes);
    $tiered = treeService()->create($site, 'Tiered', $wedding);

    treeService()->delete($wedding);

    expect(Category::query()->find($wedding->id))->toBeNull();
    $tiered = $tiered->fresh();
    expect($tiered->parent_id)->toBe($cakes->id)
        ->and($tiered->path)->toBe('cakes/tiered')
        ->and($tiered->depth)->toBe(2);
});

test('delete of a root re-parents children to the root', function () {
    $site = treeSite();
    $cakes = treeService()->create($site, 'Cakes');
    $wedding = treeService()->create($site, 'Wedding Cakes', $cakes);

    treeService()->delete($cakes);

    $wedding = $wedding->fresh();
    expect($wedding->parent_id)->toBeNull()
        ->and($wedding->path)->toBe('wedding-cakes')
        ->and($wedding->depth)->toBe(1);
});

test('delete keeps extra categories on a product and drops the primary when that category is deleted', function () {
    $site = treeSite();
    $cakes = treeService()->create($site, 'Cakes');
    $tarts = treeService()->create($site, 'Tarts');
    $product = Product::factory()->for($site)->create();
    $product->categories()->attach([
        $cakes->id => ['is_primary' => true],
        $tarts->id => ['is_primary' => false],
    ]);

    treeService()->delete($cakes);

    $product->refresh();
    expect($product->categories()->pluck('shop_categories.id')->all())->toBe([$tarts->id])
        ->and($product->primaryCategory()->exists())->toBeFalse();
});

test('delete of a product only category leaves the product with no categories', function () {
    $site = treeSite();
    $cakes = treeService()->create($site, 'Cakes');
    $product = Product::factory()->for($site)->create();
    $product->categories()->attach($cakes->id, ['is_primary' => true]);

    treeService()->delete($cakes);

    expect($product->fresh()->categories()->count())->toBe(0)
        ->and($product->primaryCategory()->exists())->toBeFalse();
});

test('reorder writes sort_order', function () {
    $site = treeSite();
    $cakes = treeService()->create($site, 'Cakes');

    treeService()->reorder($cakes, 7);

    expect($cakes->fresh()->sort_order)->toBe(7);
});

test('ancestors walk the materialised path excluding self', function () {
    $site = treeSite();
    $cakes = treeService()->create($site, 'Cakes');
    $wedding = treeService()->create($site, 'Wedding Cakes', $cakes);
    $tiered = treeService()->create($site, 'Tiered', $wedding);

    expect($tiered->ancestors()->pluck('id')->all())->toBe([$cakes->id, $wedding->id])
        ->and($cakes->ancestors())->toHaveCount(0);
});

test('descendants match the materialised path prefix', function () {
    $site = treeSite();
    $cakes = treeService()->create($site, 'Cakes');
    $wedding = treeService()->create($site, 'Wedding Cakes', $cakes);
    $tiered = treeService()->create($site, 'Tiered', $wedding);
    treeService()->create($site, 'Tarts');

    expect($cakes->descendants()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$wedding->id, $tiered->id])->sort()->values()->all())
        ->and($tiered->descendants())->toHaveCount(0);
});

test('scopeVisible excludes hidden categories', function () {
    $site = treeSite();
    $visible = treeService()->create($site, 'Cakes');
    treeService()->create($site, 'Secret', null, ['visibility' => 'hidden']);

    expect(Category::query()->where('site_id', $site->id)->visible()->pluck('id')->all())
        ->toBe([$visible->id]);
});

test('productsRolledUp includes descendant products when the category is an anchor', function () {
    $site = treeSite();
    $cakes = treeService()->create($site, 'Cakes');
    $wedding = treeService()->create($site, 'Wedding Cakes', $cakes);
    $own = Product::factory()->for($site)->create(['slug' => 'victoria']);
    $child = Product::factory()->for($site)->create(['slug' => 'three-tier']);
    $own->categories()->attach($cakes->id, ['is_primary' => true]);
    $child->categories()->attach($wedding->id, ['is_primary' => true]);

    expect($cakes->productsRolledUp()->pluck('slug')->sort()->values()->all())
        ->toBe(['three-tier', 'victoria']);
});

test('productsRolledUp is own products only when is_anchor is false', function () {
    $site = treeSite();
    $cakes = treeService()->create($site, 'Cakes', null, ['is_anchor' => false]);
    $wedding = treeService()->create($site, 'Wedding Cakes', $cakes);
    $own = Product::factory()->for($site)->create(['slug' => 'victoria']);
    $child = Product::factory()->for($site)->create(['slug' => 'three-tier']);
    $own->categories()->attach($cakes->id, ['is_primary' => true]);
    $child->categories()->attach($wedding->id, ['is_primary' => true]);

    expect($cakes->productsRolledUp()->pluck('slug')->all())->toBe(['victoria']);
});

test('supplied slugs are normalised to one lowercase segment', function () {
    $site = Site::factory()->create();
    $svc = app(CategoryTreeService::class);
    $a = $svc->create($site, 'Wedding Cakes', null, ['slug' => 'Wedding Cakes']);
    $b = $svc->create($site, 'Nested', null, ['slug' => 'a/b/c/d']);
    expect($a->slug)->toBe('wedding-cakes')->and($a->path)->toBe('wedding-cakes')->and($a->depth)->toBe(1)
        ->and($b->slug)->toBe('abcd')->and($b->depth)->toBe(1);
    $renamed = $svc->rename($a, 'Wedding Cakes', 'Big Day/Cakes');
    expect($renamed->slug)->toBe('big-daycakes');
});

test('a parent from another site is rejected', function () {
    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();
    $svc = app(CategoryTreeService::class);
    $parentB = $svc->create($siteB, 'Cakes');
    expect(fn () => $svc->create($siteA, 'Wedding', $parentB))->toThrow(CategoryTreeException::class);
});

test('create truncates meta fields like update does', function () {
    $site = Site::factory()->create();
    $c = app(CategoryTreeService::class)->create($site, 'Long', null, ['meta_title' => str_repeat('t', 90), 'meta_description' => str_repeat('d', 200)]);
    expect(mb_strlen((string) $c->meta_title))->toBe(70)->and(mb_strlen((string) $c->meta_description))->toBe(170);
});

test('snapshot rebuilds from tree writes are dispatched after commit, not inside the transaction', function () {
    \Illuminate\Support\Facades\Bus::fake();
    $site = Site::factory()->create();
    app(CategoryTreeService::class)->create($site, 'Cakes');
    \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\Shop\RebuildShopSnapshot::class, function ($job) use ($site) {
        return ($job->siteId ?? null) === $site->id;
    });
});
