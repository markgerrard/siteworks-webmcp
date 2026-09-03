<?php

use App\Enums\Shop\OrderStatus;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Site;
use App\Models\User;
use App\Services\Site\Editor\CommerceOperations;
use Tests\Support\CommerceReads;
use Tests\Support\EditorSeeds;

beforeEach(function () {
    CommerceReads::enableFlags();
    $this->withoutVite();
});

/**
 * @return array<string, mixed>
 */
function commerceShellConfig(string $html): array
{
    preg_match("/window\\.__siteworks_editor_shell_config__ = JSON\\.parse\\('(.*)'\\);/", $html, $matches);
    expect($matches)->toHaveKey(1);

    $json = json_decode('"'.$matches[1].'"', true, 512, JSON_THROW_ON_ERROR);

    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

it('seeds the commerce exposure set on the product editor', function () {
    [$actor, $site] = CommerceReads::shopSite();
    $product = Product::factory()->for($site)->create();

    $html = $this->actingAs($actor)
        ->get(route('shop.admin.products.edit', [$site->id, $product->id]))
        ->assertOk()
        ->getContent();

    $config = commerceShellConfig($html);

    expect($config['surface'])->toBe('shop-admin')
        ->and($config['capabilities'])->toContain('agent_tools')
        ->and($config['agentTools'])->toEqualCanonicalizing(CommerceOperations::SANDBOX)
        ->and($config['agentTools'])->toContain('draft_product')
        ->and($config['operationUrl'])->toBe("/sites/{$site->id}/operations/__operation__")
        ->and($html)->not->toContain('id="editor-preview-iframe"');
});

it('seeds the commerce exposure set on the shop storefront page', function () {
    [$actor, $site] = CommerceReads::shopSite();

    $html = $this->actingAs($actor)
        ->get(route('sites.shop.storefront', $site))
        ->assertOk()
        ->getContent();

    $config = commerceShellConfig($html);

    expect($config['surface'])->toBe('shop-admin')
        ->and($config['capabilities'])->toContain('agent_tools')
        ->and($config['agentTools'])->toEqualCanonicalizing(CommerceOperations::SANDBOX)
        ->and($config['agentTools'])->toContain('draft_product')
        ->and($html)->not->toContain('id="editor-preview-iframe"');
});

it('seeds portal_base tools on order detail, not commerce writes', function () {
    [$actor, $site] = CommerceReads::shopSite();
    $order = Order::query()->create([
        'site_id' => $site->id,
        'number' => 'P-4242',
        'email' => 'ava@example.com',
        'name' => 'Ava O\'Neil',
        'status' => OrderStatus::Paid->value,
        'refund_status' => 'none',
        'subtotal_cents' => 1540,
        'shipping_cents' => 0,
        'tax_cents' => 0,
        'shipping_tax_cents' => 0,
        'total_cents' => 1540,
        'tax_country_code' => 'GB',
        'shipping_address_json' => [],
        'shipping_method_label' => 'Std',
        'placed_at' => now(),
    ]);

    $html = $this->actingAs($actor)
        ->get(route('shop.admin.orders.show', [$site->id, $order->id]))
        ->assertOk()
        ->getContent();

    $config = commerceShellConfig($html);

    expect($config['surface'])->toBe('shop-admin')
        ->and($config['agentTools'])->toEqualCanonicalizing(CommerceOperations::PORTAL_BASE)
        ->and($config['agentTools'])->not->toContain('draft_product')
        ->and($config['agentTools'])->not->toContain('edit_field');
});

it('seeds portal_base tools on a brochure overview', function () {
    $actor = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $actor->id]);

    $html = $this->actingAs($actor)
        ->get(route('sites.show', $site))
        ->assertOk()
        ->getContent();

    $config = commerceShellConfig($html);

    expect($config['surface'])->toBe('shop-admin')
        ->and($config['capabilities'])->toContain('agent_tools')
        ->and($config['agentTools'])->toContain('get_brand_system')
        ->and($config['agentTools'])->toContain('get_site_context')
        ->and($config['agentTools'])->not->toContain('draft_product')
        ->and($config['agentTools'])->not->toContain('list_products')
        ->and($config['agentTools'])->not->toContain('edit_field')
        ->and($config['agentTools'])->not->toContain('add_section');
});

it('seeds portal_base tools on the agents Design page', function () {
    [$actor, $site] = CommerceReads::shopSite();

    $html = $this->actingAs($actor)
        ->get(route('sites.section', [$site, 'design']))
        ->assertOk()
        ->getContent();

    $config = commerceShellConfig($html);

    expect($config['surface'])->toBe('shop-admin')
        ->and($config['agentTools'])->toEqualCanonicalizing(CommerceOperations::PORTAL_BASE)
        ->and($config['agentTools'])->not->toContain('draft_product')
        ->and($config['agentTools'])->not->toContain('edit_field');
});

it('does not mount the seed on the multi-site listing', function () {
    $actor = User::factory()->staff()->create();

    $html = $this->actingAs($actor)
        ->get(route('sites.index'))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('__siteworks_editor_shell_config__');
});

it('keeps specialist editor ops on the editor shell, not the portal_base page set', function () {
    [$actor, $site, $page] = EditorSeeds::homeWithHero();

    $html = $this->actingAs($actor)
        ->get(route('site.editor-shell', [$site, $page]))
        ->assertOk()
        ->getContent();

    $config = commerceShellConfig($html);

    expect($config['agentTools'])->toContain('edit_field')
        ->and($config['agentTools'])->toContain('add_section')
        ->and($config['capabilities'])->toContain('agent_tools');
});
