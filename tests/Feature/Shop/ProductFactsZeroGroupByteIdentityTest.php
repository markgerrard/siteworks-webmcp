<?php

use App\Jobs\Shop\RebuildShopSnapshot;
use App\Services\Shop\SnapshotBuilder;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Support\ProductFactsFixtures;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Head 843d8303 emits this description block on a zero-group PDP (no @php/@if
 * wrapper, no extra newline). Orphan fact text must not appear.
 */
function zeroGroupHeadPdpDescription(string $description): string
{
    return "</div>\n                <div class=\"mb-6\">".e($description)."</div>\n";
}

test('a zero-group site with orphan product facts matches head 843d8303 on snapshot, PDP, card and editor', function () {
    $fixture = ProductFactsFixtures::empty();
    $site = $fixture['site'];
    $product = $fixture['products'][0];
    $orphan = 'ORPHAN-FACT-TEXT-MUST-NOT-LEAK';
    $product->update([
        'facts' => [
            'removed-tab' => ['text' => $orphan],
            'also-gone' => ['pairs' => [['label' => 'Serves', 'value' => '12']]],
        ],
    ]);
    $site->update(['product_fact_groups' => null]);

    $json = app(SnapshotBuilder::class)->build($site->id);
    $row = $json['products'][$product->slug];
    $detail = $row['product_detail'];
    $card = $row['product_card'];

    expect($detail)->not->toHaveKey('facts')
        ->and($card)->not->toHaveKey('facts_line')
        ->and(json_encode($detail))->not->toContain($orphan)
        ->and(json_encode($card))->not->toContain('Serves')
        ->and($detail)->toBe([
            'slug' => $product->slug,
            'name' => $product->name,
            'description' => $product->description,
        ]);

    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));
    URL::forceRootUrl('http://'.$site->custom_domain);
    URL::forceScheme('http');

    $pdp = $this->get('http://'.$site->custom_domain.'/products/'.$product->slug)->assertOk()->getContent();
    expect($pdp)->not->toContain($orphan)
        ->not->toContain('role="tablist"')
        ->toContain(zeroGroupHeadPdpDescription((string) $product->description));

    $cardHtml = view('shop.partials.product-card', [
        'site' => $site,
        'product' => $row,
    ])->render();
    expect($cardHtml)->not->toContain($orphan)
        ->not->toContain('color: var(--color-text-muted)')
        ->toContain("</div>\n                            <div class=\"mt-1 text-sm\">");

    $this->actingAs($fixture['user']);
    $editor = Livewire::test('shop.product-editor', [
        'siteId' => $site->id,
        'productId' => $product->id,
    ])->html();
    expect($editor)->not->toContain($orphan)
        ->not->toContain('>Facts</')
        ->and(substr_count($editor, '>Facts<'))->toBe(0);

    $descToMedia = [];
    preg_match('/label="Description".{0,400}?Media/s', $editor, $descToMedia);
    expect($descToMedia[0] ?? '')->not->toContain('Facts')
        ->and($descToMedia[0] ?? '')->not->toMatch('/\n{3,}/');
});

test('snapshot and PDP keep only facts whose slugs are in the site\'s current groups', function () {
    $fixture = ProductFactsFixtures::empty();
    $site = $fixture['site'];
    $product = $fixture['products'][0];
    $site->update([
        'product_fact_groups' => [
            ['slug' => 'notes', 'label' => 'Notes', 'kind' => 'text', 'show_on_card' => false, 'schema' => null],
        ],
    ]);
    $product->update([
        'facts' => [
            'notes' => ['text' => 'Keep me.'],
            'removed-tab' => ['text' => 'ORPHAN-FACT-TEXT-MUST-NOT-LEAK'],
        ],
    ]);

    $json = app(SnapshotBuilder::class)->build($site->id);
    $facts = $json['products'][$product->slug]['product_detail']['facts'] ?? null;

    expect($facts)->toBe(['notes' => ['text' => 'Keep me.']]);

    (new RebuildShopSnapshot($site->id))->handle(app(SnapshotBuilder::class));
    $html = $this->get('http://'.$site->custom_domain.'/products/'.$product->slug)->assertOk()->getContent();
    expect($html)->toContain('Keep me.')
        ->not->toContain('ORPHAN-FACT-TEXT-MUST-NOT-LEAK');
});
