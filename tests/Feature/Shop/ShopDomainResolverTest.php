<?php

use App\Models\Site;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('middleware resolves site from custom domain and exposes it on request', function () {
    $site = Site::factory()->create(['custom_domain' => 'flowers.example']);

    $response = $this->get('http://flowers.example/shop');
    $response->assertOk()->assertSee($site->business_name);
})->skip('needs shop routes wired in Task 5');
