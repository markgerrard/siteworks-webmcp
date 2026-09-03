<?php

use App\Models\Shop\Product;
use Tests\Support\CommerceReads;

beforeEach(function () {
    CommerceReads::enableFlags();
});

it('returns the shopless not_found shape for commerce ops when the flag is off', function () {
    [$actor, $site] = CommerceReads::shopSite();
    $site->update(['shop_enabled' => false]);

    expect(app(\App\Services\Site\Editor\Shop\ShopEntityResolver::class)->hasShop($site->fresh()))->toBeFalse();

    foreach (CommerceReads::operations() as $operation) {
        $result = CommerceReads::run($actor, $site->fresh(), $operation, shopFlagCommerceInput($operation));

        expect($result->ok)->toBeFalse()
            ->and($result->error['code'])->toBe('not_found')
            ->and(CommerceReads::auditCount($site, $operation, 'not_found'))->toBe(1);
    }

    expect(Product::query()->where('site_id', $site->id)->exists())->toBeFalse();
});

/**
 * @return array<string, mixed>
 */
function shopFlagCommerceInput(string $operation): array
{
    return match ($operation) {
        'list_products' => ['limit' => 10],
        'get_product' => ['slug' => 'missing'],
        'draft_product' => CommerceReads::draftProductInput(),
        'update_draft_product' => [
            'slug' => 'missing',
            'catalogue_revision' => 0,
            'product_revision' => 0,
            'name' => 'Nope',
        ],
        'set_product_image' => [
            'slug' => 'missing',
            'catalogue_revision' => 0,
            'product_revision' => 0,
            'media_id' => 1,
        ],
        'manage_category' => [
            'action' => 'delete',
            'slug' => 'missing',
            'catalogue_revision' => 0,
        ],
        'draft_category_content' => [
            'slug' => 'missing',
            'catalogue_revision' => 0,
        ],
        default => [],
    };
}
