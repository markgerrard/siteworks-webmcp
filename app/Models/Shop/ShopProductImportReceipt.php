<?php

namespace App\Models\Shop;

use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopProductImportReceipt extends Model
{
    protected $table = 'shop_product_import_receipts';

    protected $fillable = [
        'site_id',
        'idempotency_key',
        'receipt',
    ];

    protected function casts(): array
    {
        return [
            'receipt' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
