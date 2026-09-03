<?php

use App\Models\Shop\Customer;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('customer scoped unique per site', function () {
    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();

    Customer::create(['site_id' => $siteA->id, 'email' => 'x@y.com']);
    Customer::create(['site_id' => $siteB->id, 'email' => 'x@y.com']);
    expect(Customer::count())->toBe(2);

    expect(fn () => Customer::create(['site_id' => $siteA->id, 'email' => 'x@y.com']))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

test('soft delete populates deleted_at', function () {
    $c = Customer::create(['site_id' => Site::factory()->create()->id, 'email' => 'x@y.com']);
    $c->delete();
    expect($c->fresh()->deleted_at)->not->toBeNull();
});
