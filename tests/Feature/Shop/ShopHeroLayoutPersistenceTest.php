<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\Category;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeShopWithSnapshot(): array
{
    $site = Site::factory()->create();
    $snapshot = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => [
            'hero_image_url' => 'https://cdn.example.com/hero.jpg',
            'hero_alt' => 'Hero',
            'hero_height' => 'medium',
            'bg_position_y' => 50,
            'text_zone' => 'middle-left',
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
        'hero_image_url' => 'https://cdn.example.com/hero.jpg',
        'hero_alt' => 'Hero',
        'hero_height' => 'medium',
        'bg_position_y' => 50,
        'text_zone' => 'middle-left',
    ]);
    ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $snapshot->id]);

    return [$site, $snapshot];
}

// ── Shop-level persistence ────────────────────────────────────────────────────

test('setShopBgPositionY persists to snapshot and triggers rebuild', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('setShopBgPositionY', 72);

    $snapshot->refresh();
    expect($snapshot->bg_position_y)->toBe(72);

    // A rebuild creates a new snapshot version.
    expect(ShopSnapshot::where('site_id', $site->id)->count())->toBeGreaterThanOrEqual(2);
});

test('setShopTextZone persists to snapshot and triggers rebuild', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('setShopTextZone', 'bottom-right');

    $snapshot->refresh();
    expect($snapshot->text_zone)->toBe('bottom-right');

    expect(ShopSnapshot::where('site_id', $site->id)->count())->toBeGreaterThanOrEqual(2);
});

test('setShopHeroHeight persists to snapshot and triggers rebuild', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('setShopHeroHeight', 'large');

    $snapshot->refresh();
    expect($snapshot->hero_height)->toBe('large');

    expect(ShopSnapshot::where('site_id', $site->id)->count())->toBeGreaterThanOrEqual(2);
});

test('setShopHeroWidth persists to snapshot and triggers rebuild', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('setShopHeroWidth', 'full');

    $snapshot->refresh();
    expect($snapshot->hero_width)->toBe('full');

    $rebuilt = ShopSnapshot::where('site_id', $site->id)->orderByDesc('version')->first();
    expect($rebuilt->hero_width)->toBe('full')
        ->and($rebuilt->json['hero_width'] ?? null)->toBe('full')
        ->and(ShopSnapshot::where('site_id', $site->id)->count())->toBeGreaterThanOrEqual(2);
});

test('setShopHeroEnabled persists to snapshot without clearing the image', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('setShopHeroEnabled', false);

    $snapshot->refresh();
    expect($snapshot->hero_enabled)->toBeFalse()
        ->and($snapshot->hero_image_url)->toBe('https://cdn.example.com/hero.jpg');

    $rebuilt = ShopSnapshot::where('site_id', $site->id)->orderByDesc('version')->first();
    expect($rebuilt->hero_enabled)->toBeFalse()
        ->and($rebuilt->json['hero_enabled'] ?? true)->toBeFalse()
        ->and($rebuilt->json['hero_image_url'] ?? null)->toBe('https://cdn.example.com/hero.jpg');
});

test('setShopHeroHeadline persists to snapshot and triggers rebuild', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('setShopHeroHeadline', 'Cakes & Patisserie')
        ->assertHasNoErrors();

    $snapshot->refresh();
    expect($snapshot->hero_headline)->toBe('Cakes & Patisserie');

    $rebuilt = ShopSnapshot::where('site_id', $site->id)->orderByDesc('version')->first();
    expect($rebuilt->hero_headline)->toBe('Cakes & Patisserie')
        ->and($rebuilt->json['hero_headline'] ?? null)->toBe('Cakes & Patisserie')
        ->and(ShopSnapshot::where('site_id', $site->id)->count())->toBeGreaterThanOrEqual(2);
});

test('setShopHeroHeadline rejects values longer than 60 characters', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('setShopHeroHeadline', str_repeat('A', 61))
        ->assertHasErrors(['heroHeadline']);

    $snapshot->refresh();
    expect($snapshot->hero_headline)->toBeNull();
});

test('setShopHeroHeadline normalises newlines and internal whitespace', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('setShopHeroHeadline', "  Cakes   &\n Patisserie  ")
        ->assertHasNoErrors();

    $snapshot->refresh();
    expect($snapshot->hero_headline)->toBe('Cakes & Patisserie');

    $rebuilt = ShopSnapshot::where('site_id', $site->id)->orderByDesc('version')->first();
    expect($rebuilt->hero_headline)->toBe('Cakes & Patisserie')
        ->and($rebuilt->json['hero_headline'] ?? null)->toBe('Cakes & Patisserie');
});

test('setShopHeroHeadline persists an empty string as null', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $snapshot->update(['hero_headline' => 'Cakes & Patisserie']);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('setShopHeroHeadline', '   ')
        ->assertHasNoErrors();

    $snapshot->refresh();
    expect($snapshot->hero_headline)->toBeNull();

    $rebuilt = ShopSnapshot::where('site_id', $site->id)->orderByDesc('version')->first();
    expect($rebuilt->hero_headline)->toBeNull()
        ->and($rebuilt->json)->toHaveKey('hero_headline')
        ->and($rebuilt->json['hero_headline'])->toBeNull();
});

test('using a shop headline draft applies it through setShopHeroHeadline', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->set('headlineDraft', 'Bouquets & Botanicals')
        ->set('headlineDraftStatus', 'ready')
        ->call('useHeadlineDraft')
        ->assertHasNoErrors()
        ->assertSet('headlineDraft', null)
        ->assertSet('headlineDraftStatus', 'idle');

    $rebuilt = ShopSnapshot::where('site_id', $site->id)->orderByDesc('version')->first();
    expect($rebuilt->hero_headline)->toBe('Bouquets & Botanicals')
        ->and($rebuilt->json['hero_headline'] ?? null)->toBe('Bouquets & Botanicals')
        ->and($snapshot->fresh()->hero_headline)->toBe('Bouquets & Botanicals');
});

test('discarding a shop headline draft leaves the snapshot unchanged', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $snapshot->update(['hero_headline' => 'Cakes & Patisserie']);
    $user = \App\Models\User::factory()->admin()->create();
    Cache::put('shop-headline-draft:'.$site->id.':'.$user->id, [
        'status' => 'ready',
        'draft' => 'Bouquets & Botanicals',
    ], now()->addMinutes(15));

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->set('headlineDraft', 'Bouquets & Botanicals')
        ->set('headlineDraftStatus', 'ready')
        ->call('discardHeadlineDraft')
        ->assertSet('headlineDraft', null)
        ->assertSet('headlineDraftStatus', 'idle');

    expect($snapshot->fresh()->hero_headline)->toBe('Cakes & Patisserie');
});

test('resetShopHeroHeadline clears the headline and triggers rebuild', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $snapshot->update(['hero_headline' => 'Cakes & Patisserie']);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('resetShopHeroHeadline');

    $snapshot->refresh();
    expect($snapshot->hero_headline)->toBeNull();

    $rebuilt = ShopSnapshot::where('site_id', $site->id)->orderByDesc('version')->first();
    expect($rebuilt->hero_headline)->toBeNull()
        ->and($rebuilt->json)->toHaveKey('hero_headline')
        ->and($rebuilt->json['hero_headline'])->toBeNull()
        ->and(ShopSnapshot::where('site_id', $site->id)->count())->toBeGreaterThanOrEqual(2);
});

test('setShopHeroAccentWord persists to snapshot and triggers rebuild', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $snapshot->update(['hero_headline' => 'Cakes & Patisserie']);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('setShopHeroAccentWord', 'Patisserie')
        ->assertHasNoErrors();

    $snapshot->refresh();
    expect($snapshot->hero_accent_word)->toBe('Patisserie');

    $rebuilt = ShopSnapshot::where('site_id', $site->id)->orderByDesc('version')->first();
    expect($rebuilt->hero_accent_word)->toBe('Patisserie')
        ->and($rebuilt->json['hero_accent_word'] ?? null)->toBe('Patisserie')
        ->and(ShopSnapshot::where('site_id', $site->id)->count())->toBeGreaterThanOrEqual(2);
});

test('resetShopHeroAccentWord clears the accent word and triggers rebuild', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $snapshot->update(['hero_headline' => 'Cakes & Patisserie', 'hero_accent_word' => 'Patisserie']);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('resetShopHeroAccentWord');

    $snapshot->refresh();
    expect($snapshot->hero_accent_word)->toBeNull();

    $rebuilt = ShopSnapshot::where('site_id', $site->id)->orderByDesc('version')->first();
    expect($rebuilt->hero_accent_word)->toBeNull()
        ->and($rebuilt->json)->toHaveKey('hero_accent_word')
        ->and($rebuilt->json['hero_accent_word'])->toBeNull()
        ->and(ShopSnapshot::where('site_id', $site->id)->count())->toBeGreaterThanOrEqual(2);
});

test('setShopHeroAccentWord rejects a two-word value', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $snapshot->update(['hero_headline' => 'Cakes & Patisserie']);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('setShopHeroAccentWord', 'Cakes Patisserie')
        ->assertHasErrors(['accentWord']);

    expect($snapshot->fresh()->hero_accent_word)->toBeNull();
});

test('setShopHeroAccentWord rejects a word not present in the headline', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $snapshot->update(['hero_headline' => 'Cakes & Patisserie']);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('setShopHeroAccentWord', 'Bouquets')
        ->assertHasErrors(['accentWord']);

    expect($snapshot->fresh()->hero_accent_word)->toBeNull();
});

test('setShopHeroAccentWord validates against the Shop fallback when headline is empty', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('setShopHeroAccentWord', 'Shop')
        ->assertHasNoErrors();

    expect($snapshot->fresh()->hero_accent_word)->toBe('Shop');
});

test('changing the headline clears a no-longer-matching accent word', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $snapshot->update(['hero_headline' => 'Cakes & Patisserie', 'hero_accent_word' => 'Patisserie']);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('setShopHeroHeadline', 'Bouquets & Botanicals')
        ->assertHasNoErrors();

    $snapshot->refresh();
    expect($snapshot->hero_headline)->toBe('Bouquets & Botanicals')
        ->and($snapshot->hero_accent_word)->toBeNull();

    $rebuilt = ShopSnapshot::where('site_id', $site->id)->orderByDesc('version')->first();
    expect($rebuilt->hero_accent_word)->toBeNull()
        // One rebuild total for the combined headline+accent change.
        ->and(ShopSnapshot::where('site_id', $site->id)->count())->toBe(2);
});

test('changing the headline keeps an accent word that still matches', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $snapshot->update(['hero_headline' => 'Cakes & Patisserie', 'hero_accent_word' => 'Cakes']);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('setShopHeroHeadline', 'Cakes & Bakes')
        ->assertHasNoErrors();

    $snapshot->refresh();
    expect($snapshot->hero_headline)->toBe('Cakes & Bakes')
        ->and($snapshot->hero_accent_word)->toBe('Cakes');
});

test('resetting the headline to empty clears an accent word that no longer matches the Shop fallback', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $snapshot->update(['hero_headline' => 'Cakes & Patisserie', 'hero_accent_word' => 'Patisserie']);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('resetShopHeroHeadline')
        ->assertHasNoErrors();

    $snapshot->refresh();
    expect($snapshot->hero_headline)->toBeNull()
        ->and($snapshot->hero_accent_word)->toBeNull();
});

test('accent word chips trim punctuation, drop empties, dedupe case-insensitively, and mark the selected one', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $snapshot->update([
        'hero_headline' => "Cakes, & Bakes -- (Fresh)  Cakes!  ",
        'hero_accent_word' => 'bakes',
    ]);
    $user = \App\Models\User::factory()->admin()->create();

    $html = Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->html();

    preg_match_all('/<button[^>]*>\s*([^<]*?)\s*<\/button>/', $html, $matches);
    $chipLabels = array_values(array_filter($matches[1], fn ($label) => $label !== ''));

    // Punctuation trimmed from both ends, empty tokens (from the double space
    // and the bare "&"/"--") dropped, and the repeated "Cakes"/"Cakes!"
    // collapsed case-insensitively keeping the first casing seen.
    expect($chipLabels)->toContain('None', 'Cakes', 'Bakes', 'Fresh')
        ->and($chipLabels)->not->toContain('Cakes!', '&', '--')
        ->and(array_count_values($chipLabels)['Cakes'] ?? 0)->toBe(1);

    // The stored accent word "bakes" matches chip "Bakes" case-insensitively,
    // so that chip (not "None") is the one marked pressed.
    preg_match('/<button[^>]*aria-pressed="true"[^>]*>\s*Bakes\s*<\/button>/', $html, $pressedBakes);
    expect($pressedBakes)->not->toBeEmpty();
});

test('setShopHeroTextStyle persists to snapshot and triggers rebuild', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('setShopHeroTextStyle', 'boxed');

    $snapshot->refresh();
    expect($snapshot->hero_text_style)->toBe('boxed');

    $rebuilt = ShopSnapshot::where('site_id', $site->id)->orderByDesc('version')->first();
    expect($rebuilt->hero_text_style)->toBe('boxed')
        ->and($rebuilt->json['hero_text_style'] ?? null)->toBe('boxed')
        ->and(ShopSnapshot::where('site_id', $site->id)->count())->toBeGreaterThanOrEqual(2);
});

test('setShopHeroTextStyle rejects invalid values', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('setShopHeroTextStyle', 'overlay');

    $snapshot->refresh();
    expect($snapshot->hero_text_style)->toBeNull();
});

test('resetShopHeroTextStyle clears the text style and triggers rebuild', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $snapshot->update(['hero_text_style' => 'boxed']);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('resetShopHeroTextStyle');

    $snapshot->refresh();
    expect($snapshot->hero_text_style)->toBeNull();

    $rebuilt = ShopSnapshot::where('site_id', $site->id)->orderByDesc('version')->first();
    expect($rebuilt->hero_text_style)->toBeNull()
        ->and($rebuilt->json)->toHaveKey('hero_text_style')
        ->and($rebuilt->json['hero_text_style'])->toBeNull()
        ->and(ShopSnapshot::where('site_id', $site->id)->count())->toBeGreaterThanOrEqual(2);
});

test('shop snapshot defaults hero_text_style to null', function () {
    [, $snapshot] = makeShopWithSnapshot();

    expect($snapshot->hero_text_style)->toBeNull();
});

test('setShopHeroWidth rejects invalid values', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('setShopHeroWidth', 'wide');

    $snapshot->refresh();
    expect($snapshot->hero_width ?? 'boxed')->toBe('boxed');
});

test('setShopHeroHeight rejects invalid values', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('setShopHeroHeight', 'enormous');

    $snapshot->refresh();
    expect($snapshot->hero_height)->toBe('medium');
});

test('setShopTextZone rejects invalid zone values', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('setShopTextZone', 'invalid-zone');

    $snapshot->refresh();
    expect($snapshot->text_zone)->toBe('middle-left');
});

test('resetShopTextZone sets zone to middle-left', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $snapshot->update(['text_zone' => 'top-right']);

    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('resetShopTextZone');

    $snapshot->refresh();
    expect($snapshot->text_zone)->toBe('middle-left');
});

// ── section prop render-gating (storefront tabs split) ────────────────────────

test('section=shop renders only the Shop Index Hero card', function () {
    [$site] = makeShopWithSnapshot();
    $user = \App\Models\User::factory()->admin()->create();

    $html = Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id, 'section' => 'shop'])
        ->html();

    expect($html)->toContain('Shop Index Hero')
        ->and($html)->not->toContain('Category hero (shared)');
});

test('section=shared renders only the Category hero (shared) card', function () {
    [$site] = makeShopWithSnapshot();
    $user = \App\Models\User::factory()->admin()->create();

    $html = Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id, 'section' => 'shared'])
        ->html();

    expect($html)->toContain('Category hero (shared)')
        ->and($html)->not->toContain('Shop Index Hero');
});

test('section=all (the default) renders both cards', function () {
    [$site] = makeShopWithSnapshot();
    $user = \App\Models\User::factory()->admin()->create();

    $html = Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->html();

    expect($html)->toContain('Shop Index Hero')
        ->and($html)->toContain('Category hero (shared)');
});

// ── Category-level persistence ────────────────────────────────────────────────

test('setCategoryBgPositionY persists to category and triggers rebuild', function () {
    [$site] = makeShopWithSnapshot();
    $category = Category::factory()->for($site)->create([
        'hero_image_url' => 'https://cdn.example.com/cat.jpg',
        'bg_position_y' => 50,
        'text_zone' => 'middle-left',
        'hero_height' => 'medium',
    ]);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.category-manager', ['siteId' => $site->id])
        ->call('setCategoryBgPositionY', $category->id, 30);

    $category->refresh();
    expect($category->bg_position_y)->toBe(30);

    expect(ShopSnapshot::where('site_id', $site->id)->count())->toBeGreaterThanOrEqual(2);
});

test('setCategoryTextZone persists to category and triggers rebuild', function () {
    [$site] = makeShopWithSnapshot();
    $category = Category::factory()->for($site)->create([
        'hero_image_url' => 'https://cdn.example.com/cat.jpg',
        'bg_position_y' => 50,
        'text_zone' => 'middle-left',
        'hero_height' => 'medium',
    ]);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.category-manager', ['siteId' => $site->id])
        ->call('setCategoryTextZone', $category->id, 'top-center');

    $category->refresh();
    expect($category->text_zone)->toBe('top-center');

    expect(ShopSnapshot::where('site_id', $site->id)->count())->toBeGreaterThanOrEqual(2);
});

test('setCategoryHeroHeight persists to category and triggers rebuild', function () {
    [$site] = makeShopWithSnapshot();
    $category = Category::factory()->for($site)->create([
        'hero_image_url' => 'https://cdn.example.com/cat.jpg',
        'bg_position_y' => 50,
        'text_zone' => 'middle-left',
        'hero_height' => 'medium',
    ]);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.category-manager', ['siteId' => $site->id])
        ->call('setCategoryHeroHeight', $category->id, 'small');

    $category->refresh();
    expect($category->hero_height)->toBe('small');

    expect(ShopSnapshot::where('site_id', $site->id)->count())->toBeGreaterThanOrEqual(2);
});

test('setCategoryHeroWidth persists to category and triggers rebuild', function () {
    [$site] = makeShopWithSnapshot();
    $category = Category::factory()->for($site)->create([
        'hero_image_url' => 'https://cdn.example.com/cat.jpg',
        'bg_position_y' => 50,
        'text_zone' => 'middle-left',
        'hero_height' => 'medium',
    ]);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.category-manager', ['siteId' => $site->id])
        ->call('setCategoryHeroWidth', $category->id, 'full');

    $category->refresh();
    expect($category->hero_width)->toBe('full');

    $rebuilt = ShopSnapshot::where('site_id', $site->id)->orderByDesc('version')->first();
    expect($rebuilt->json['categories'][$category->slug]['hero_width'] ?? null)->toBe('full');
});

test('setCategoryHeroEnabled persists to category without clearing the image', function () {
    [$site] = makeShopWithSnapshot();
    $category = Category::factory()->for($site)->create([
        'hero_image_url' => 'https://cdn.example.com/cat.jpg',
        'bg_position_y' => 50,
        'text_zone' => 'middle-left',
        'hero_height' => 'medium',
    ]);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.category-manager', ['siteId' => $site->id])
        ->call('setCategoryHeroEnabled', $category->id, false);

    $category->refresh();
    expect($category->hero_enabled)->toBeFalse()
        ->and($category->hero_image_url)->toBe('https://cdn.example.com/cat.jpg');

    $rebuilt = ShopSnapshot::where('site_id', $site->id)->orderByDesc('version')->first();
    expect($rebuilt->json['categories'][$category->slug]['hero_enabled'] ?? true)->toBeFalse()
        ->and($rebuilt->json['categories'][$category->slug]['hero_image_url'] ?? null)->toBe('https://cdn.example.com/cat.jpg');
});

test('setCategoryIntroBand persists to category and snapshot without clearing the image', function () {
    [$site] = makeShopWithSnapshot();
    $category = Category::factory()->for($site)->create([
        'hero_image_url' => 'https://cdn.example.com/cat.jpg',
        'hero_mode' => 'custom',
    ]);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.category-manager', ['siteId' => $site->id])
        ->call('setCategoryIntroBand', $category->id, true);

    $category->refresh();
    expect($category->intro_band)->toBeTrue()
        ->and($category->hero_image_url)->toBe('https://cdn.example.com/cat.jpg')
        ->and($category->hero_mode)->toBe('custom');

    $rebuilt = ShopSnapshot::where('site_id', $site->id)->orderByDesc('version')->first();
    expect($rebuilt->json['categories'][$category->slug]['intro_band'] ?? false)->toBeTrue();
});

test('resetCategoryTextZone resets to middle-left', function () {
    [$site] = makeShopWithSnapshot();
    $category = Category::factory()->for($site)->create([
        'text_zone' => 'bottom-right',
        'hero_height' => 'medium',
        'bg_position_y' => 50,
    ]);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.category-manager', ['siteId' => $site->id])
        ->call('resetCategoryTextZone', $category->id);

    $category->refresh();
    expect($category->text_zone)->toBe('middle-left');
});

test('setCategoryBgPositionY clamps out-of-range values', function () {
    [$site] = makeShopWithSnapshot();
    $category = Category::factory()->for($site)->create([
        'bg_position_y' => 50,
        'text_zone' => 'middle-left',
        'hero_height' => 'medium',
    ]);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.category-manager', ['siteId' => $site->id])
        ->call('setCategoryBgPositionY', $category->id, 150);

    $category->refresh();
    expect($category->bg_position_y)->toBe(100);
});

// ── Shared category hero persistence ──────────────────────────────────────────

test('setSharedCategoryHeroHeight persists to the shared block and triggers rebuild', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('setSharedCategoryHeroHeight', 'large');

    $snapshot->refresh();
    expect($snapshot->shared_category_hero['height'] ?? null)->toBe('large');

    $rebuilt = ShopSnapshot::where('site_id', $site->id)->orderByDesc('version')->first();
    expect($rebuilt->shared_category_hero['height'] ?? null)->toBe('large')
        ->and($rebuilt->json['shared_category_hero']['height'] ?? null)->toBe('large');
});

test('setSharedCategoryHeroWidth persists to the shared block and triggers rebuild', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('setSharedCategoryHeroWidth', 'full');

    $snapshot->refresh();
    expect($snapshot->shared_category_hero['width'] ?? null)->toBe('full');

    $rebuilt = ShopSnapshot::where('site_id', $site->id)->orderByDesc('version')->first();
    expect($rebuilt->shared_category_hero['width'] ?? null)->toBe('full')
        ->and($rebuilt->json['shared_category_hero']['width'] ?? null)->toBe('full');
});

test('setSharedCategoryHeroTextZone persists to the shared block and triggers rebuild', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('setSharedCategoryHeroTextZone', 'bottom-right');

    $snapshot->refresh();
    expect($snapshot->shared_category_hero['text_zone'] ?? null)->toBe('bottom-right');
});

test('resetSharedCategoryHeroTextZone resets the shared zone to middle-left', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $snapshot->update(['shared_category_hero' => ['text_zone' => 'top-right']]);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('resetSharedCategoryHeroTextZone');

    $snapshot->refresh();
    expect($snapshot->shared_category_hero['text_zone'] ?? null)->toBe('middle-left');
});

test('setSharedCategoryHeroBgPositionY persists to the shared block and clamps', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('setSharedCategoryHeroBgPositionY', 150);

    $snapshot->refresh();
    expect($snapshot->shared_category_hero['bg_position_y'] ?? null)->toBe(100);
});

test('setSharedCategoryHeroTextStyle persists to the shared block and triggers rebuild', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('setSharedCategoryHeroTextStyle', 'boxed');

    $snapshot->refresh();
    expect($snapshot->shared_category_hero['text_style'] ?? null)->toBe('boxed');

    $rebuilt = ShopSnapshot::where('site_id', $site->id)->orderByDesc('version')->first();
    expect($rebuilt->shared_category_hero['text_style'] ?? null)->toBe('boxed')
        ->and($rebuilt->json['shared_category_hero']['text_style'] ?? null)->toBe('boxed');
});

test('resetSharedCategoryHeroTextStyle clears the shared text style', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $snapshot->update(['shared_category_hero' => ['text_style' => 'boxed', 'height' => 'medium']]);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('resetSharedCategoryHeroTextStyle');

    $snapshot->refresh();
    expect($snapshot->shared_category_hero['text_style'] ?? null)->toBeNull()
        ->and($snapshot->shared_category_hero['height'] ?? null)->toBe('medium');
});

test('setSharedCategoryHeroEnabled persists to the shared block without clearing other keys', function () {
    [$site, $snapshot] = makeShopWithSnapshot();
    $snapshot->update(['shared_category_hero' => ['image_url' => '/shared.jpg', 'height' => 'large']]);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('setSharedCategoryHeroEnabled', false);

    $snapshot->refresh();
    expect($snapshot->shared_category_hero['enabled'] ?? null)->toBeFalse()
        ->and($snapshot->shared_category_hero['image_url'] ?? null)->toBe('/shared.jpg')
        ->and($snapshot->shared_category_hero['height'] ?? null)->toBe('large');

    $rebuilt = ShopSnapshot::where('site_id', $site->id)->orderByDesc('version')->first();
    expect($rebuilt->shared_category_hero['enabled'] ?? null)->toBeFalse()
        ->and($rebuilt->json['shared_category_hero']['enabled'] ?? null)->toBeFalse()
        ->and(ShopSnapshot::where('site_id', $site->id)->count())->toBeGreaterThanOrEqual(2);

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call('setSharedCategoryHeroEnabled', true);

    $rebuiltAgain = ShopSnapshot::where('site_id', $site->id)->orderByDesc('version')->first();
    expect($rebuiltAgain->shared_category_hero['enabled'] ?? null)->toBeTrue();
});

test('setCategoryHeroMode persists the mode, syncs hero_enabled, and triggers rebuild', function (string $mode, bool $enabled) {
    [$site] = makeShopWithSnapshot();
    $category = Category::factory()->for($site)->create([
        'hero_image_url' => 'https://cdn.example.com/cat.jpg',
        'hero_enabled' => true,
        'hero_mode' => 'custom',
    ]);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.category-manager', ['siteId' => $site->id])
        ->call('setCategoryHeroMode', $category->id, $mode);

    $category->refresh();
    expect($category->hero_mode)->toBe($mode)
        ->and($category->hero_enabled)->toBe($enabled)
        ->and($category->hero_image_url)->toBe('https://cdn.example.com/cat.jpg');

    $rebuilt = ShopSnapshot::where('site_id', $site->id)->orderByDesc('version')->first();
    expect($rebuilt->json['categories'][$category->slug]['hero_mode'] ?? null)->toBe($mode)
        ->and($rebuilt->json['categories'][$category->slug]['hero_enabled'] ?? null)->toBe($enabled);
})->with([
    ['none', false],
    ['shared', true],
    ['custom', true],
]);

test('setCategoryHeroMode rejects invalid values', function () {
    [$site] = makeShopWithSnapshot();
    $category = Category::factory()->for($site)->create(['hero_mode' => 'shared']);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.category-manager', ['siteId' => $site->id])
        ->call('setCategoryHeroMode', $category->id, 'banner');

    expect($category->fresh()->hero_mode)->toBe('shared');
});

test('setCategoryHeroTextStyle persists to the category and triggers rebuild', function () {
    [$site] = makeShopWithSnapshot();
    $category = Category::factory()->for($site)->create(['name' => 'Cakes']);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.category-manager', ['siteId' => $site->id])
        ->call('setCategoryHeroTextStyle', $category->id, 'boxed');

    expect($category->fresh()->hero_text_style)->toBe('boxed');

    $rebuilt = ShopSnapshot::where('site_id', $site->id)->orderByDesc('version')->first();
    expect($rebuilt->json['categories'][$category->slug]['hero_text_style'] ?? null)->toBe('boxed');
});

test('resetCategoryHeroTextStyle clears the category text style', function () {
    [$site] = makeShopWithSnapshot();
    $category = Category::factory()->for($site)->create(['hero_text_style' => 'boxed']);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.category-manager', ['siteId' => $site->id])
        ->call('resetCategoryHeroTextStyle', $category->id);

    expect($category->fresh()->hero_text_style)->toBeNull();
});

test('setCategoryHeroTextStyle rejects invalid values', function () {
    [$site] = makeShopWithSnapshot();
    $category = Category::factory()->for($site)->create(['hero_text_style' => 'plain']);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.category-manager', ['siteId' => $site->id])
        ->call('setCategoryHeroTextStyle', $category->id, 'overlay');

    expect($category->fresh()->hero_text_style)->toBe('plain');
});

test('setCategoryHeroAccentWord persists to the category and triggers rebuild', function () {
    [$site] = makeShopWithSnapshot();
    $category = Category::factory()->for($site)->create(['name' => 'Wedding Cakes']);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.category-manager', ['siteId' => $site->id])
        ->call('setCategoryHeroAccentWord', $category->id, 'Cakes')
        ->assertHasNoErrors();

    expect($category->fresh()->hero_accent_word)->toBe('Cakes');

    $rebuilt = ShopSnapshot::where('site_id', $site->id)->orderByDesc('version')->first();
    expect($rebuilt->json['categories'][$category->slug]['hero_accent_word'] ?? null)->toBe('Cakes');
});

test('resetCategoryHeroAccentWord clears the accent word', function () {
    [$site] = makeShopWithSnapshot();
    $category = Category::factory()->for($site)->create([
        'name' => 'Wedding Cakes',
        'hero_accent_word' => 'Cakes',
    ]);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.category-manager', ['siteId' => $site->id])
        ->call('resetCategoryHeroAccentWord', $category->id);

    expect($category->fresh()->hero_accent_word)->toBeNull();
});

test('setCategoryHeroAccentWord rejects a word that is not in the category name', function () {
    [$site] = makeShopWithSnapshot();
    $category = Category::factory()->for($site)->create(['name' => 'Wedding Cakes']);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.category-manager', ['siteId' => $site->id])
        ->call('setCategoryHeroAccentWord', $category->id, 'Bouquets')
        ->assertHasErrors(['accentWord']);

    expect($category->fresh()->hero_accent_word)->toBeNull();
});

test('renaming a category clears a no-longer-matching accent word', function () {
    [$site] = makeShopWithSnapshot();
    $category = Category::factory()->for($site)->create([
        'name' => 'Wedding Cakes',
        'hero_accent_word' => 'Wedding',
    ]);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.category-manager', ['siteId' => $site->id])
        ->call('rename', $category->id, 'Birthday Bakes')
        ->assertHasNoErrors();

    $category->refresh();
    expect($category->name)->toBe('Birthday Bakes')
        ->and($category->hero_accent_word)->toBeNull();
});

test('renaming a category keeps an accent word that still matches', function () {
    [$site] = makeShopWithSnapshot();
    $category = Category::factory()->for($site)->create([
        'name' => 'Wedding Cakes',
        'hero_accent_word' => 'Cakes',
    ]);
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.category-manager', ['siteId' => $site->id])
        ->call('rename', $category->id, 'Birthday Cakes')
        ->assertHasNoErrors();

    $category->refresh();
    expect($category->name)->toBe('Birthday Cakes')
        ->and($category->hero_accent_word)->toBe('Cakes');
});

test('shared category hero setters reject invalid values', function (string $method, mixed $value) {
    [$site, $snapshot] = makeShopWithSnapshot();
    $user = \App\Models\User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('shop.shop-hero-picker', ['siteId' => $site->id])
        ->call($method, $value);

    $snapshot->refresh();
    expect($snapshot->shared_category_hero)->toBeNull();
})->with([
    ['setSharedCategoryHeroHeight', 'enormous'],
    ['setSharedCategoryHeroWidth', 'wide'],
    ['setSharedCategoryHeroTextZone', 'invalid-zone'],
    ['setSharedCategoryHeroTextStyle', 'overlay'],
]);

