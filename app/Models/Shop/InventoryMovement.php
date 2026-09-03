<?php

namespace App\Models\Shop;

use App\Enums\Shop\InventoryReason;
use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    protected $table = 'shop_inventory_movements';

    public $timestamps = false;

    protected $fillable = ['variant_id', 'delta', 'reason', 'reference_type', 'reference_id', 'note', 'created_at'];

    protected $casts = [
        'reason' => InventoryReason::class,
        'created_at' => 'datetime',
    ];
}
