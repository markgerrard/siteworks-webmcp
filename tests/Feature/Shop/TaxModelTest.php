<?php

use App\Models\Shop\TaxClass;
use App\Models\Shop\TaxRate;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('tax seeders populate GB standard rate at 20 percent', function () {
    $this->seed(\Database\Seeders\Shop\TaxClassSeeder::class);
    $this->seed(\Database\Seeders\Shop\TaxRateSeeder::class);

    $standard = TaxClass::where('code', 'standard')->first();
    expect($standard)->not->toBeNull();

    $rate = TaxRate::where('country_code', 'GB')
        ->where('tax_class_id', $standard->id)
        ->first();

    expect($rate->rate_percent)->toEqual('20.00');
});
