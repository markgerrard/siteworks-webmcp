<?php

namespace App\Models\Shop;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAddress extends Model
{
    protected $table = 'shop_customer_addresses';

    protected $fillable = [
        'site_id',
        'customer_id',
        'label',
        'name',
        'phone',
        'line1',
        'line2',
        'city',
        'region',
        'postcode',
        'country_code',
        'is_default_shipping',
        'is_default_billing',
    ];

    protected $casts = [
        'is_default_shipping' => 'boolean',
        'is_default_billing' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeForCustomer(Builder $query, Customer $customer): Builder
    {
        return $query
            ->where('site_id', $customer->site_id)
            ->where('customer_id', $customer->id);
    }
}
