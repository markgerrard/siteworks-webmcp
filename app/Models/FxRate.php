<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FxRate extends Model
{
    public $timestamps = false;

    protected $fillable = ['base', 'quote', 'rate', 'rate_date', 'source', 'created_at'];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:8',
            'rate_date' => 'date',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Latest known rate for the given base → quote pair, or null if none.
     */
    public static function latest(string $base, string $quote): ?self
    {
        return self::where('base', strtoupper($base))
            ->where('quote', strtoupper($quote))
            ->orderByDesc('rate_date')
            ->first();
    }
}
