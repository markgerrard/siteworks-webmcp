<?php

namespace App\Models;

use App\Models\Shop\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteEnquiry extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'customer_id',
        'name',
        'email',
        'payload',
        'field_labels',
        'page_type',
        'status',
        'ip_hash',
    ];

    /** Never serialize visitor IP hashes into JSON/Livewire payloads. */
    protected $hidden = [
        'ip_hash',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'field_labels' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Enquiries for this customer on this site. A stamped customer_id wins;
     * otherwise a case-insensitive match on a verified email.
     */
    public function scopeForCustomer(Builder $query, Customer $customer): void
    {
        $query->where('site_id', $customer->site_id)
            ->where(function (Builder $inner) use ($customer): void {
                $inner->where('customer_id', $customer->id);
                if ($customer->email_verified_at !== null) {
                    $inner->orWhereRaw('LOWER(email) = ?', [mb_strtolower($customer->email)]);
                }
            });
    }
}
