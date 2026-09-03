<?php

use App\Enums\Shop\ProductStatus;
use App\Models\Shop\Product;
use App\Models\Site;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('search route returns matching products on public shop domain', function () {
    $site = Site::factory()->create(['custom_domain' => 'flowers.example', 'custom_domain_status' => 'active']);
    Product::factory()->for($site)->create(['name' => 'Red Rose', 'status' => ProductStatus::Published]);
    Product::factory()->for($site)->create(['name' => 'White Lily', 'status' => ProductStatus::Published]);

    $this->get('http://flowers.example/shop/search?q=rose')
        ->assertOk()
        ->assertSee('Red Rose')
        ->assertDontSee('White Lily');
});
