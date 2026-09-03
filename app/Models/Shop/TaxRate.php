<?php

namespace App\Models\Shop;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaxRate extends Model
{
    use HasFactory;

    protected $fillable = ['country_code', 'tax_class_id', 'rate_percent', 'valid_from', 'valid_to'];

    protected $casts = [
        'rate_percent' => 'decimal:2',
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];

    public function taxClass()
    {
        return $this->belongsTo(TaxClass::class);
    }
}
