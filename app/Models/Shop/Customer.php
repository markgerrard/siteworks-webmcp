<?php

namespace App\Models\Shop;

use App\Models\Site;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model implements AuthenticatableContract
{
    use Authenticatable, SoftDeletes;

    protected $table = 'shop_customers';

    protected $fillable = [
        'site_id', 'email', 'name', 'password_hash',
        'email_verified_at', 'marketing_consent_at', 'terms_accepted_at', 'last_login_at',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'marketing_consent_at' => 'datetime',
        'terms_accepted_at' => 'datetime',
        'last_login_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $hidden = ['password_hash'];

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash ?? '';
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }
}
