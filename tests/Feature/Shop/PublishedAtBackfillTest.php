<?php

use App\Enums\Shop\ProductStatus;
use App\Models\Shop\Product;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

it('backfills published_at from created_at for already-published products, not updated_at', function () {
    $site = Site::factory()->create();
    $published = Product::factory()->for($site)->published()->create();
    $draft = Product::factory()->for($site)->create(['status' => ProductStatus::Draft]);
    $kept = now()->subDays(3);
    $already = Product::factory()->for($site)->published()->create(['published_at' => $kept]);
    $created = now()->subYears(2);
    $edited = now()->subDay();
    DB::table('shop_products')->where('id', $published->id)->update([
        'published_at' => null,
        'created_at' => $created,
        'updated_at' => $edited,
    ]);

    $migration = require database_path('migrations/2026_08_30_182000_backfill_published_at_on_published_products.php');
    $migration->up();

    $published = $published->fresh();
    expect($published->published_at)->not->toBeNull()
        ->and($published->published_at->equalTo($published->created_at))->toBeTrue()
        ->and($published->published_at->equalTo($published->updated_at))->toBeFalse()
        ->and($draft->fresh()->published_at)->toBeNull()
        ->and($already->fresh()->published_at?->toDateTimeString())->toBe($kept->toDateTimeString());

    $stamped = $published->published_at->toDateTimeString();
    $migration->up();

    expect($published->fresh()->published_at->toDateTimeString())->toBe($stamped)
        ->and($already->fresh()->published_at?->toDateTimeString())->toBe($kept->toDateTimeString())
        ->and($draft->fresh()->published_at)->toBeNull();
});
