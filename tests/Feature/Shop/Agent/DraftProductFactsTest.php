<?php

use App\Models\Shop\Product;
use App\Support\Shop\ProductFacts;
use Database\Seeders\Shop\TaxClassSeeder;
use Tests\Support\CommerceReads;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    CommerceReads::enableFlags();
    $this->seed(TaxClassSeeder::class);
});

test('draft and update product operations accept facts for the site groups', function () {
    [$actor, $site, $category] = CommerceReads::shopSite();
    $site->update(['product_fact_groups' => ProductFacts::presetGroups('generic-specifications')]);

    $created = CommerceReads::run($actor, $site, 'draft_product', CommerceReads::draftProductInput([
        'facts' => [
            'specifications' => ['pairs' => [['label' => 'Width', 'value' => '12']]],
            'details' => ['text' => 'Handle with care.'],
        ],
    ]));

    $product = Product::query()->where('site_id', $site->id)->first();
    expect($created->ok)->toBeTrue()
        ->and($product->facts['specifications']['pairs'][0]['value'])->toBe('12')
        ->and($product->facts['details']['text'])->toBe('Handle with care.');

    $updated = CommerceReads::run($actor, $site, 'update_draft_product', [
        'catalogue_revision' => $created->data['catalogue_revision'],
        'slug' => $product->slug,
        'product_revision' => (int) $product->revision,
        'facts' => [
            'details' => ['text' => 'Updated notes.'],
        ],
    ]);

    expect($updated->ok)->toBeTrue()
        ->and($product->fresh()->facts['details']['text'])->toBe('Updated notes.');
});

test('unknown fact slugs are rejected with the list of valid slugs', function () {
    [$actor, $site] = CommerceReads::shopSite();
    $site->update(['product_fact_groups' => ProductFacts::presetGroups('generic-specifications')]);

    $result = CommerceReads::run($actor, $site, 'draft_product', CommerceReads::draftProductInput([
        'facts' => [
            'mystery' => ['text' => 'nope'],
        ],
    ]));

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($result->error['message'])->toContain('mystery')
        ->and($result->error['message'])->toContain('specifications')
        ->and($result->error['message'])->toContain('details')
        ->and(Product::query()->where('site_id', $site->id)->exists())->toBeFalse();
});

test('draft_product schema includes facts', function () {
    $schema = app(\App\Services\Site\Editor\Operations\ShopDraftProductOperation::class)->inputSchema();
    $update = app(\App\Services\Site\Editor\Operations\ShopUpdateDraftProductOperation::class)->inputSchema();

    expect($schema['properties'])->toHaveKey('facts')
        ->and($update['properties'])->toHaveKey('facts');
});
