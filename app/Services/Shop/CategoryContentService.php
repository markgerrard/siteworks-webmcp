<?php

namespace App\Services\Shop;

use App\Models\Shop\Category;
use App\Models\Site;
use App\Services\Site\PublicPageCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CategoryContentService
{
    public function __construct(private readonly CategoryContentSanitizer $sanitizer) {}

    /**
     * @param  array{description_long?: ?string, faqs?: ?array, meta_title?: ?string, meta_description?: ?string, is_ai_seeded?: bool}  $input
     */
    public function update(Category $category, array $input): Category
    {
        $validated = validator($input, [
            'description_long' => ['nullable', 'string'],
            'faqs' => ['nullable', 'array', 'list', 'max:12'],
            'faqs.*.q' => ['required_with:faqs', 'string', 'max:160'],
            'faqs.*.a' => ['required_with:faqs', 'string', 'max:1200'],
            'meta_title' => ['nullable', 'string', 'max:70'],
            'meta_description' => ['nullable', 'string', 'max:170'],
            'is_ai_seeded' => ['sometimes', 'boolean'],
        ])->validate();

        $site = Site::query()->findOrFail($category->site_id);
        $update = [];
        if (array_key_exists('description_long', $validated)) {
            $descriptionLong = $this->sanitizer->clean($validated['description_long'], $site);
            if (mb_strlen((string) $descriptionLong) > 20_000) {
                throw ValidationException::withMessages([
                    'description_long' => 'Long copy must be at most 20,000 characters after sanitising.',
                ]);
            }
            $update['description_long'] = $descriptionLong;
        }
        foreach (['faqs', 'meta_title', 'meta_description', 'is_ai_seeded'] as $key) {
            if (array_key_exists($key, $validated)) {
                $update[$key] = $key === 'faqs' && is_array($validated[$key])
                    ? array_values($validated[$key])
                    : $validated[$key];
            }
        }

        return DB::transaction(function () use ($category, $update, $site): Category {
            $category = Category::query()->where('site_id', $site->id)->lockForUpdate()->findOrFail($category->id);
            $category->update($update);
            app(PublicPageCache::class)->invalidate($site);

            return $category->fresh();
        });
    }
}
