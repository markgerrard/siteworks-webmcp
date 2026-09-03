<?php

namespace Database\Seeders\Shop;

use App\Models\Shop\TaxClass;
use Illuminate\Database\Seeder;

class TaxClassSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['standard' => 'Standard', 'reduced' => 'Reduced', 'zero' => 'Zero-rated', 'exempt' => 'Exempt'] as $code => $name) {
            TaxClass::updateOrCreate(['code' => $code], ['name' => $name]);
        }
    }
}
