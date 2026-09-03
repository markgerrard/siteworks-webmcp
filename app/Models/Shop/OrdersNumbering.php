<?php

namespace App\Models\Shop;

use Illuminate\Database\Eloquent\Model;

class OrdersNumbering extends Model
{
    public $incrementing = false;

    protected $table = 'shop_orders_numbering';

    protected $primaryKey = 'site_id';

    public $timestamps = false;

    protected $fillable = ['site_id', 'next_sequence', 'updated_at'];
}
