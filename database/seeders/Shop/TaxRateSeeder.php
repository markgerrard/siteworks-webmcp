<?php

namespace Database\Seeders\Shop;

use App\Models\Shop\TaxClass;
use App\Models\Shop\TaxRate;
use Illuminate\Database\Seeder;

class TaxRateSeeder extends Seeder
{
    public function run(): void
    {
        $rates = [
            'standard' => '20.00',
            'reduced' => '5.00',
            'zero' => '0.00',
            'exempt' => '0.00',
        ];
        foreach ($rates as $code => $rate) {
            $class = TaxClass::where('code', $code)->firstOrFail();
            TaxRate::updateOrCreate(
                ['country_code' => 'GB', 'tax_class_id' => $class->id, 'valid_from' => '2011-01-04'],
                ['rate_percent' => $rate, 'valid_to' => null]
            );
        }
    }
}
