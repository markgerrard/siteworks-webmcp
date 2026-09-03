<?php

namespace App\Models\Shop;

use App\Models\Site;
use Illuminate\Database\Eloquent\Model;

class ShopSnapshotCurrent extends Model
{
    public $incrementing = false;

    protected $table = 'shop_snapshot_current';

    protected $primaryKey = 'site_id';

    public $timestamps = false;

    protected $fillable = ['site_id', 'snapshot_id', 'updated_at'];

    protected $casts = ['updated_at' => 'datetime'];

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function snapshot()
    {
        return $this->belongsTo(ShopSnapshot::class, 'snapshot_id');
    }
}
