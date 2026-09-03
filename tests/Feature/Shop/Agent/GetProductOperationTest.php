<?php

use App\Enums\Shop\ProductStatus;
use App\Models\Client;
use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Shop\ProductImage;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Models\Site\EditorOperationLog;
use App\Models\User;
use App\Services\Shop\StockService;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\Operations\ShopGetProductOperation;
use Tests\Support\CommerceReads;

beforeEach(function () {
    CommerceReads::enableFlags();
});

it('returns not_found for a foreign-site product_id and writes nothing', function () {
    [$actor, $site] = CommerceReads::shopSite();
    $foreign = Product::factory()->create(['slug' => 'foreign-product']);
    $before = Product::query()->count();

    $result = CommerceReads::run($actor, $site, 'get_product', ['product_id' => $foreign->id]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('not_found')
        ->and(Product::query()->count())->toBe($before);
});

it('projects the resolved product without search_vector, html, or raw capability ids', function () {
    [$actor, $site, $category] = CommerceReads::shopSite();
    $product = Product::factory()->for($site)->create([
        'name' => 'Pillar Candle',
        'slug' => 'pillar-candle',
        'description' => 'Beeswax pillar.',
        'is_ai_seeded' => true,
        'is_ai_reviewed' => false,
    ]);
    $product->categories()->attach($category->id, ['is_primary' => true]);
    $variant = ProductVariant::factory()->for($product)->create([
        'sku' => 'PILLAR-DEF',
        'label' => 'Default',
        'price_cents' => 1850,
    ]);
    VariantStock::query()->create(['variant_id' => $variant->id, 'on_hand' => 4, 'updated_at' => now()]);
    app(StockService::class)->reserve($variant->id, 1, cartId: 9);
    ProductImage::query()->create([
        'product_id' => $product->id,
        'path' => 'shop/products/pillar.png',
        'sort_order' => 0,
        'alt' => 'Pillar',
    ]);

    $result = CommerceReads::run($actor, $site, 'get_product', ['slug' => 'pillar-candle']);
    $encoded = json_encode($result->data);

    expect($result->ok)->toBeTrue()
        ->and($result->data['catalogue_revision'])->toBe(0)
        ->and($result->data['slug'])->toBe('pillar-candle')
        ->and($result->data['status'])->toBe(ProductStatus::Draft->value)
        ->and($result->data['revision'])->toBe((int) $product->revision)
        ->and($result->data['is_ai_seeded'])->toBeTrue()
        ->and($result->data['is_ai_reviewed'])->toBeFalse()
        ->and($result->data['variants'][0])->toMatchArray([
            'sku' => 'PILLAR-DEF',
            'label' => 'Default',
            'price_pence' => 1850,
            'on_hand' => 4,
            'available' => 3,
        ])
        ->and($result->data['images'][0])->toHaveKeys(['sort_order', 'url', 'alt'])
        ->and($result->data['images'][0]['sort_order'])->toBe(0)
        ->and($result->data['images'][0]['alt'])->toBe('Pillar')
        ->and($result->state->pageId)->toBeNull()
        ->and($result->state->draftRevisionId)->toBeNull()
        ->and($result->state->structureEpoch)->toBeNull()
        ->and($encoded)->not->toContain('search_vector')
        ->and($encoded)->not->toContain('<html')
        ->and($result->data)->not->toHaveKey('html')
        ->and($result->data)->not->toHaveKey('preview_url')
        ->and($result->data)->not->toHaveKeys(['id', 'product_id'])
        ->and($result->data['variants'][0])->not->toHaveKeys(['id', 'variant_id', 'product_id']);
});

it('emits a preview_unavailable warning because P2 is not on this base', function () {
    [$actor, $site] = CommerceReads::shopSite();
    Product::factory()->for($site)->create(['slug' => 'no-preview']);

    $result = CommerceReads::run($actor, $site, 'get_product', ['slug' => 'no-preview']);
    $codes = collect($result->receipt->warnings)->pluck('code')->all();

    expect($result->ok)->toBeTrue()
        ->and($result->data)->not->toHaveKey('preview_url')
        ->and($codes)->toContain('preview_unavailable');
});

it('never consults RenderContext::fromRequest', function () {
    $source = file_get_contents(app_path('Services/Site/Editor/Operations/ShopGetProductOperation.php'));

    expect($source)->not->toContain('RenderContext::fromRequest')
        ->and(app(ShopGetProductOperation::class)->readOnly())->toBeTrue()
        ->and(app(ShopGetProductOperation::class)->address())->toBe('shop');
});

it('writes one audit row and nothing else when the agent-tools flag is off', function () {
    [$actor, $site] = CommerceReads::shopSite();
    Product::factory()->for($site)->create(['slug' => 'flag-off']);
    CommerceReads::exposeOnSandbox('get_product');
    config(['editor.agent_tools.enabled' => false]);
    $before = EditorOperationLog::query()->count();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'get_product',
        ['slug' => 'flag-off'],
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('forbidden')
        ->and(EditorOperationLog::query()->count())->toBe($before + 1)
        ->and(CommerceReads::auditCount($site, 'get_product', 'forbidden'))->toBe(1);
});

it('writes one audit row and nothing else when the exposure set omits the operation', function () {
    [$actor, $site] = CommerceReads::shopSite();
    CommerceReads::omitFromSandbox('get_product');
    $before = EditorOperationLog::query()->count();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'get_product',
        ['slug' => 'anything'],
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('not_found')
        ->and(EditorOperationLog::query()->count())->toBe($before + 1)
        ->and(CommerceReads::auditCount($site, 'get_product', 'not_found'))->toBe(1);
});

it('writes one audit row and nothing else when the actor role is denied', function () {
    $client = Client::factory()->create();
    $actor = User::factory()->create(['client_id' => $client->id, 'role' => null]);
    $site = Site::factory()->create(['client_id' => $client->id]);
    Category::factory()->for($site)->create();
    CommerceReads::giveShop($site);
    CommerceReads::exposeOnSandbox('get_product');
    $before = EditorOperationLog::query()->count();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'get_product',
        ['slug' => 'nope'],
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('forbidden')
        ->and(EditorOperationLog::query()->count())->toBe($before + 1)
        ->and(CommerceReads::auditCount($site, 'get_product', 'forbidden'))->toBe(1);
});

it('writes one audit row and nothing else when the site has no shop', function () {
    $actor = User::factory()->staff(\App\Enums\AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $actor->id]);
    $before = EditorOperationLog::query()->count();

    $result = CommerceReads::run($actor, $site, 'get_product', ['slug' => 'missing']);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('not_found')
        ->and(EditorOperationLog::query()->count())->toBe($before + 1)
        ->and(CommerceReads::auditCount($site, 'get_product', 'not_found'))->toBe(1)
        ->and(ShopSnapshotCurrent::query()->where('site_id', $site->id)->exists())->toBeFalse();
});
