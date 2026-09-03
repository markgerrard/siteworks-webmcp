<?php

namespace App\Models\Shop;

use App\Models\Site;
use App\Support\Shop\ShopUrls;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Category extends Model
{
    use HasFactory;

    protected $table = 'shop_categories';

    protected $fillable = [
        'site_id',
        'parent_id',
        'slug',
        'name',
        'path',
        'depth',
        'description',
        'description_long',
        'faqs',
        'sort_order',
        'is_anchor',
        'visibility',
        'meta_title',
        'meta_description',
        'is_ai_seeded',
        'sort',
        'hero_image_url',
        'hero_alt',
        'hero_height',
        'bg_position_y',
        'text_zone',
        'hero_width',
        'hero_enabled',
        'hero_mode',
        'hero_text_style',
        'hero_accent_word',
        'intro_band',
        'hero_prompt',
        'hero_model',
    ];

    protected $attributes = [
        'depth' => 1,
        'is_anchor' => true,
        'visibility' => 'visible',
        'sort' => 'manual',
        'hero_width' => 'boxed',
        'hero_enabled' => true,
        'intro_band' => false,
    ];

    protected function casts(): array
    {
        return [
            'depth' => 'integer',
            'is_anchor' => 'boolean',
            'sort_order' => 'integer',
            'faqs' => 'array',
            'is_ai_seeded' => 'boolean',
            'hero_enabled' => 'boolean',
            'intro_band' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Category $category): void {
            $auto = $category->slug === null || $category->slug === '';
            if ($auto) {
                $category->slug = Str::slug((string) $category->name);
                if ($category->slug === '') {
                    $category->slug = 'category';
                }
            }

            if ($category->path === null || $category->path === '') {
                $parent = $category->parent_id !== null
                    ? static::query()->find($category->parent_id)
                    : null;
                $category->path = $parent === null
                    ? $category->slug
                    : $parent->path.'/'.$category->slug;
                $category->depth = $parent === null ? 1 : $parent->depth + 1;
            }

            if ($auto) {
                $base = (string) $category->slug;
                $n = 2;
                while (ShopUrls::isReservedSlug((string) $category->slug) || ShopUrls::isReservedPath((string) $category->path)) {
                    $category->slug = $base.'-'.$n;
                    $parent = $category->parent_id !== null
                        ? static::query()->find($category->parent_id)
                        : null;
                    $category->path = $parent === null
                        ? $category->slug
                        : $parent->path.'/'.$category->slug;
                    $n++;
                }
            }
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'shop_product_category')
            ->withPivot('is_primary');
    }

    /**
     * @return Collection<int, Category>
     */
    public function ancestors(): Collection
    {
        $segments = explode('/', (string) $this->path);
        array_pop($segments);

        $paths = [];
        $accumulated = '';
        foreach ($segments as $segment) {
            $accumulated = $accumulated === '' ? $segment : $accumulated.'/'.$segment;
            $paths[] = $accumulated;
        }

        if ($paths === []) {
            return new Collection;
        }

        return static::query()
            ->where('site_id', $this->site_id)
            ->whereIn('path', $paths)
            ->orderBy('depth')
            ->get();
    }

    /**
     * @return Collection<int, Category>
     */
    public function descendants(): Collection
    {
        return static::query()
            ->where('site_id', $this->site_id)
            ->where('path', 'like', $this->path.'/%')
            ->orderBy('path')
            ->get();
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('visibility', 'visible');
    }

    public function productsRolledUp(): Builder
    {
        $ids = [$this->id];
        if ($this->is_anchor) {
            $ids = array_merge($ids, $this->descendants()->pluck('id')->all());
        }

        return Product::query()
            ->where('site_id', $this->site_id)
            ->whereHas('categories', fn (Builder $query) => $query->whereIn('shop_categories.id', $ids));
    }
}
