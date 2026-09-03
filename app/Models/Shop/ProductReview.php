<?php

namespace App\Models\Shop;

use App\Enums\Shop\ProductReviewSource;
use App\Enums\Shop\ProductReviewStatus;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductReview extends Model
{
    use HasFactory;

    protected $table = 'shop_product_reviews';

    protected $fillable = [
        'site_id',
        'product_id',
        'rating',
        'title',
        'body',
        'author_name',
        'author_email_hash',
        'status',
        'source',
        'invite_token_hash',
        'ip_hash',
    ];

    /** Never serialize visitor hashes or invite tokens into JSON/Livewire payloads. */
    protected $hidden = [
        'author_email_hash',
        'ip_hash',
        'invite_token_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'status' => ProductReviewStatus::class,
            'source' => ProductReviewSource::class,
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @param  array<string, mixed>  $attrs
     *
     * @throws ValidationException
     */
    public static function validatedCreate(array $attrs): self
    {
        $validated = Validator::make($attrs, [
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'product_id' => [
                'required',
                'integer',
                Rule::exists('shop_products', 'id')->where(
                    fn ($query) => $query->where('site_id', $attrs['site_id'] ?? 0),
                ),
            ],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['required', 'string', 'max:80'],
            'body' => ['required', 'string', 'max:2000'],
            'author_name' => ['required', 'string', 'max:60'],
            'author_email_hash' => ['nullable', 'string', 'size:64'],
            'status' => ['required', Rule::enum(ProductReviewStatus::class)],
            'source' => ['required', Rule::enum(ProductReviewSource::class)],
            'invite_token_hash' => ['nullable', 'string', 'size:64'],
            'ip_hash' => ['nullable', 'string', 'max:64'],
        ])->validate();

        return self::query()->create($validated);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ProductReviewStatus::Published->value);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ProductReviewStatus::Pending->value);
    }
}
