<?php

namespace App\Models\Shop;

use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    public $incrementing = false;

    protected $table = 'shop_webhook_events';

    protected $primaryKey = 'stripe_event_id';

    protected $keyType = 'string';

    protected $fillable = ['stripe_event_id', 'event_type', 'payload_json', 'received_at', 'processed_at', 'error'];

    protected $casts = [
        'payload_json' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
