<?php

namespace App\Models\Shop;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaxClass extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name'];

    public function rates()
    {
        return $this->hasMany(TaxRate::class);
    }
}
