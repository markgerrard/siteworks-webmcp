<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\CartItem;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * These tests submit the form AS RENDERED, instead of posting a hand-written
 * field list to the controller.
 *
 * Every other cart test posts ['product_slug', 'variant_id', 'qty'] directly.
 * That exercises CartController and never the view, so a product page whose
 * form omits a required field passes the whole suite while the Add to cart
 * button does nothing for every shopper on every product.
 */

/**
 * Build a site whose snapshot and whose real catalogue rows agree.
 * The controller resolves the product/variant from the DATABASE; the view
 * renders from the SNAPSHOT. A test that seeds only one of them proves nothing.
 *
 * @param  list<array{label: string, price: int, stock: int}>  $variantSpecs
 */
function shopSiteWithProduct(array $variantSpecs): array
{
    $site = Site::factory()->create([
        'custom_domain' => 'flowers.example',
        'custom_domain_status' => 'active',
    ]);

    $product = Product::factory()->for($site)->create(['slug' => 'rose', 'name' => 'Red Rose']);

    $variants = [];
    foreach ($variantSpecs as $i => $spec) {
        $variant = ProductVariant::factory()->for($product)->create([
            'sku' => 'RR-'.$i,
            'label' => $spec['label'],
            'price_cents' => $spec['price'],
        ]);
        VariantStock::create(['variant_id' => $variant->id, 'on_hand' => $spec['stock']]);
        $variants[] = $variant;
    }

    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => [
            'meta' => ['site_id' => $site->id, 'product_count' => 1],
            'categories' => [],
            'products' => [
                'rose' => [
                    'id' => $product->id, 'slug' => 'rose', 'status' => 'published',
                    'primary_category_slug' => null,
                    'price_cents' => $variantSpecs[0]['price'],
                    'price_display' => '£'.number_format($variantSpecs[0]['price'] / 100, 2),
                    'in_stock_any' => collect($variantSpecs)->contains(fn ($spec) => $spec['stock'] > 0),
                    'variant_in_stock' => collect($variants)->mapWithKeys(
                        fn ($v, $i) => [$v->id => $variantSpecs[$i]['stock'] > 0]
                    )->all(),
                    'image_urls' => ['thumb' => '/a.jpg', 'card' => '/a.jpg', 'full' => '/a.jpg'],
                    'product_card' => ['slug' => 'rose', 'name' => 'Red Rose', 'price_display' => '£45.00'],
                    'product_detail' => ['slug' => 'rose', 'name' => 'Red Rose', 'description' => 'Lovely'],
                    'variants' => collect($variants)->map(fn ($v) => [
                        'id' => $v->id, 'sku' => $v->sku, 'label' => $v->label,
                        'price_cents' => $v->price_cents, 'image_urls' => null,
                    ])->all(),
                    'is_ai_seeded' => false, 'is_ai_reviewed' => false,
                ],
            ],
            'featured_slugs' => [],
        ],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create(['site_id' => $site->id, 'snapshot_id' => $snap->id, 'updated_at' => now()]);

    return [$site, $product, $variants];
}

/**
 * Extract the name/value pairs the add-to-cart form would actually submit:
 * hidden + number inputs, and the selected (else first enabled) option of any
 * select. Disabled options and un-checked radios/checkboxes are skipped —
 * a page that always adds the wrong variant must not pass. Anything outside
 * the <form> is deliberately NOT collected.
 *
 * @return array{action: string, method: string, fields: array<string, string>, inner: string}
 */
function renderedAddToCartForm(string $html): array
{
    preg_match('#<form\b([^>]*action="[^"]*/shop/cart/add"[^>]*)>(.*?)</form>#s', $html, $m);
    expect($m)->not->toBeEmpty();

    preg_match('#\baction="([^"]+)"#', $m[1], $action);
    preg_match('#\bmethod="([^"]+)"#i', $m[1], $method);

    $form = $m[2];
    $fields = [];

    preg_match_all('#<input\b[^>]*>#s', $form, $inputs);
    foreach ($inputs[0] ?? [] as $input) {
        if (preg_match('#\bdisabled\b#i', $input)) {
            continue;
        }
        if (preg_match('#\btype="(?i:checkbox|radio)"#', $input) && ! preg_match('#\bchecked\b#i', $input)) {
            continue;
        }
        if (preg_match('#name="([^"]+)"#', $input, $n)) {
            preg_match('#value="([^"]*)"#', $input, $v);
            $fields[$n[1]] = $v[1] ?? '';
        }
    }

    preg_match_all('#<select[^>]*name="([^"]+)"[^>]*>(.*?)</select>#s', $form, $selects, PREG_SET_ORDER);
    foreach ($selects as $select) {
        preg_match_all('#<option\b([^>]*)>#is', $select[2], $options, PREG_SET_ORDER);
        $chosen = null;
        $firstEnabled = null;
        foreach ($options as $option) {
            $attrs = $option[1];
            if (preg_match('#\bdisabled\b#i', $attrs)) {
                continue;
            }
            preg_match('#\bvalue="([^"]*)"#', $attrs, $value);
            $value = $value[1] ?? '';
            $firstEnabled ??= $value;
            if (preg_match('#\bselected\b#i', $attrs)) {
                $chosen = $value;
                break;
            }
        }
        $fields[$select[1]] = $chosen ?? $firstEnabled ?? '';
    }

    return [
        'action' => html_entity_decode($action[1] ?? '', ENT_QUOTES | ENT_HTML5),
        'method' => strtoupper($method[1] ?? 'GET'),
        'fields' => $fields,
        'inner' => $form,
    ];
}

/**
 * @return array<string, string>
 */
function renderedAddToCartFields(string $html): array
{
    return renderedAddToCartForm($html)['fields'];
}

test('the rendered Add to cart form actually adds the item', function () {
    [$site, $product, $variants] = shopSiteWithProduct([
        ['label' => 'Standard', 'price' => 4500, 'stock' => 5],
    ]);

    $html = $this->get('http://flowers.example/products/rose')->assertOk()->getContent();
    $form = renderedAddToCartForm($html);

    expect($form['method'])->toBe('POST')
        ->and($form['action'])->toContain('/shop/cart/add')
        ->and($form['fields']['_token'] ?? '')->not->toBeEmpty();

    $this->post('http://flowers.example/shop/cart/add', $form['fields'])
        ->assertRedirect('http://flowers.example/shop/cart');

    expect(CartItem::where('variant_id', $variants[0]->id)->first())->not->toBeNull();
});

test('the rendered form carries a quantity', function () {
    shopSiteWithProduct([['label' => 'Standard', 'price' => 4500, 'stock' => 5]]);

    $html = $this->get('http://flowers.example/products/rose')->assertOk()->getContent();

    expect(renderedAddToCartFields($html))->toHaveKey('qty');
});

test('a multi-variant product submits the selected in-stock variant, not the first option', function () {
    [$site, $product, $variants] = shopSiteWithProduct([
        ['label' => 'Small', 'price' => 4500, 'stock' => 0],
        ['label' => 'Large', 'price' => 6500, 'stock' => 5],
    ]);

    $html = $this->get('http://flowers.example/products/rose')->assertOk()->getContent();
    $form = renderedAddToCartForm($html);

    expect($form['fields']['_token'] ?? '')->not->toBeEmpty()
        ->and($form['fields'])->toHaveKey('variant_id')
        ->and($form['inner'])->toMatch('/<input\b[^>]*type="radio"[^>]*name="variant_id"/i')
        ->and($form['inner'])->not->toMatch('/<select\b[^>]*name="variant_id"/i');

    preg_match_all('#<input\b(?=[^>]*\btype="radio")(?=[^>]*\bname="variant_id")[^>]*>#i', $form['inner'], $radios);
    expect($radios[0])->not->toBeEmpty();
    $selectedValue = [1 => null];
    foreach ($radios[0] as $input) {
        if (preg_match('#\bchecked\b#i', $input) === 1) {
            preg_match('#\bvalue="([^"]+)"#', $input, $selectedValue);
            break;
        }
    }
    expect($selectedValue[1])->not->toBeNull();

    expect($form['fields']['variant_id'])->toBe($selectedValue[1])
        ->and($form['fields']['variant_id'])->toBe((string) $variants[1]->id)
        ->and($form['fields']['variant_id'])->not->toBe((string) $variants[0]->id);

    $this->post('http://flowers.example/shop/cart/add', $form['fields'])
        ->assertRedirect('http://flowers.example/shop/cart');

    $item = CartItem::whereIn('variant_id', collect($variants)->pluck('id'))->first();
    expect($item)->not->toBeNull()
        ->and($item->variant_id)->toBe($variants[1]->id);
});
