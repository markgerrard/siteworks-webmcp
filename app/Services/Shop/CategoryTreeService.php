<?php

namespace App\Services\Shop;

use App\Models\Shop\Category;
use App\Models\Site;
use App\Support\Shop\ShopUrls;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CategoryTreeService
{
    public const MAX_DEPTH = 3;

    /**
     * @param  array{
     *     slug?: string,
     *     description?: ?string,
     *     is_anchor?: bool,
     *     visibility?: string,
     *     meta_title?: ?string,
     *     meta_description?: ?string,
     *     sort?: string,
     *     sort_order?: int
     * }  $attrs
     */
    public function create(Site $site, string $name, ?Category $parent = null, array $attrs = []): Category
    {
        return DB::transaction(function () use ($site, $name, $parent, $attrs): Category {
            if ($parent !== null && (int) $parent->site_id !== (int) $site->id) {
                throw CategoryTreeException::notFound(); // parent must belong to the same site
            }
            $slug = $this->resolveSlug($site, $name, $attrs['slug'] ?? null);
            $depth = $parent === null ? 1 : $parent->depth + 1;
            $this->assertDepth($depth);

            $category = Category::query()->create([
                'site_id' => $site->id,
                'parent_id' => $parent?->id,
                'slug' => $slug,
                'name' => $name,
                'path' => $this->pathFor($slug, $parent),
                'depth' => $depth,
                'description' => $attrs['description'] ?? null,
                'is_anchor' => $attrs['is_anchor'] ?? true,
                'visibility' => $attrs['visibility'] ?? 'visible',
                'meta_title' => isset($attrs['meta_title']) && is_string($attrs['meta_title']) ? mb_substr($attrs['meta_title'], 0, 70) : null,
                'meta_description' => isset($attrs['meta_description']) && is_string($attrs['meta_description']) ? mb_substr($attrs['meta_description'], 0, 170) : null,
                'sort' => $attrs['sort'] ?? 'manual',
                'sort_order' => $attrs['sort_order'] ?? ((int) (Category::query()->where('site_id', $site->id)->max('sort_order') ?? 0) + 1),
            ]);

            return $category;
        });
    }

    public function move(Category $category, ?Category $newParent): Category
    {
        return DB::transaction(function () use ($category, $newParent): Category {
            $category = Category::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();
            if ($newParent !== null) {
                $newParent = Category::query()->whereKey($newParent->id)->lockForUpdate()->firstOrFail();
            }

            $this->assertSameSite($category, $newParent);
            $this->assertNotCycle($category, $newParent);

            $newDepth = $newParent === null ? 1 : $newParent->depth + 1;
            $this->assertDepth($newDepth + $this->subtreeHeight($category) - 1);

            $oldPath = $category->path;
            $category->parent_id = $newParent?->id;
            $category->path = $this->pathFor($category->slug, $newParent);
            $category->depth = $newDepth;
            $category->save();

            $this->recomputeDescendantPaths($category, $oldPath);

            return $category->fresh();
        });
    }

    public function rename(Category $category, string $name, ?string $slug = null): Category
    {
        return DB::transaction(function () use ($category, $name, $slug): Category {
            $category = Category::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();
            $category->name = $name;
            $accent = $category->hero_accent_word;
            if (is_string($accent) && $accent !== '' && mb_stripos($name, $accent) === false) {
                $category->hero_accent_word = null;
            }

            $slug = $slug !== null && $slug !== '' ? Str::slug($slug) : null;
            if ($slug !== null && $slug !== $category->slug) {
                $this->assertSlugWritable($slug);
                $this->assertSlugAvailable($category->site_id, $slug, $category->id);
                $oldPath = $category->path;
                $category->slug = $slug;
                $parent = $category->parent_id !== null
                    ? Category::query()->whereKey($category->parent_id)->first()
                    : null;
                $category->path = $this->pathFor($slug, $parent);
                $category->save();
                $this->recomputeDescendantPaths($category, $oldPath);
            } else {
                $category->save();
            }

            return $category->fresh();
        });
    }

    public function delete(Category $category): void
    {
        DB::transaction(function () use ($category): void {
            $category = Category::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();
            $parent = $category->parent_id !== null
                ? Category::query()->whereKey($category->parent_id)->lockForUpdate()->first()
                : null;

            foreach ($category->children()->orderBy('id')->lockForUpdate()->get() as $child) {
                $this->move($child, $parent);
            }

            $category->delete();
        });
    }

    public function reorder(Category $category, int $sortOrder): Category
    {
        $category->update(['sort_order' => $sortOrder]);

        return $category->fresh();
    }

    /**
     * @param  array{
     *     is_anchor?: bool,
     *     visibility?: string,
     *     meta_title?: ?string,
     *     meta_description?: ?string,
     *     sort?: string,
     *     description?: ?string
     * }  $attrs
     */
    public function updateAttributes(Category $category, array $attrs): Category
    {
        $update = [];
        foreach (['is_anchor', 'visibility', 'meta_title', 'meta_description', 'sort', 'description'] as $key) {
            if (array_key_exists($key, $attrs)) {
                $update[$key] = $attrs[$key];
            }
        }

        if (array_key_exists('meta_title', $update) && is_string($update['meta_title'])) {
            $update['meta_title'] = mb_substr($update['meta_title'], 0, 70);
        }
        if (array_key_exists('meta_description', $update) && is_string($update['meta_description'])) {
            $update['meta_description'] = mb_substr($update['meta_description'], 0, 170);
        }

        if ($update !== []) {
            $category->update($update);
        }

        return $category->fresh();
    }

    public function subtreeHeight(Category $category): int
    {
        $maxDepth = Category::query()
            ->where('site_id', $category->site_id)
            ->where('path', 'like', $category->path.'/%')
            ->max('depth');

        if ($maxDepth === null) {
            return 1;
        }

        return ((int) $maxDepth) - $category->depth + 1;
    }

    private function resolveSlug(Site $site, string $name, ?string $slug): string
    {
        // Always normalise: a supplied slug must be ONE lowercase URL segment (review — "Wedding
        // Cakes" and "a/b/c/d" were stored verbatim, producing unroutable paths and wrong depth).
        $explicit = $slug !== null && $slug !== '';
        $resolved = Str::slug($explicit ? $slug : $name);
        if ($resolved === '') {
            $resolved = 'category';
        }

        if ($explicit) {
            $this->assertSlugWritable($resolved);
            $this->assertSlugAvailable($site->id, $resolved);

            return $resolved;
        }

        $base = $resolved;
        $n = 2;
        while (ShopUrls::isReservedSlug($resolved) || ShopUrls::isReservedPath($resolved)) {
            $resolved = $base.'-'.$n;
            $n++;
        }

        $this->assertSlugAvailable($site->id, $resolved);

        return $resolved;
    }

    private function assertSlugWritable(string $slug): void
    {
        if (ShopUrls::isReservedSlug($slug) || ShopUrls::isReservedPath($slug)) {
            throw CategoryTreeException::reservedSlug();
        }
    }

    private function assertSlugAvailable(int $siteId, string $slug, ?int $ignoreId = null): void
    {
        if ($this->slugTaken($siteId, $slug, $ignoreId)) {
            throw CategoryTreeException::slugTaken();
        }
    }

    private function slugTaken(int $siteId, string $slug, ?int $ignoreId = null): bool
    {
        return Category::query()
            ->where('site_id', $siteId)
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    private function pathFor(string $slug, ?Category $parent): string
    {
        return $parent === null ? $slug : $parent->path.'/'.$slug;
    }

    private function assertDepth(int $depth): void
    {
        if ($depth > self::MAX_DEPTH || $depth < 1) {
            throw CategoryTreeException::depth();
        }
    }

    private function assertNotCycle(Category $category, ?Category $newParent): void
    {
        if ($newParent === null) {
            return;
        }

        if ($newParent->id === $category->id || str_starts_with($newParent->path, $category->path.'/')) {
            throw CategoryTreeException::cycle();
        }
    }

    private function assertSameSite(Category $category, ?Category $parent): void
    {
        if ($parent !== null && $parent->site_id !== $category->site_id) {
            throw CategoryTreeException::notFound();
        }
    }

    private function recomputeDescendantPaths(Category $category, string $oldPath): void
    {
        if ($oldPath === $category->path) {
            return;
        }

        $descendants = Category::query()
            ->where('site_id', $category->site_id)
            ->where('path', 'like', $oldPath.'/%')
            ->orderBy('depth')
            ->lockForUpdate()
            ->get();

        foreach ($descendants as $descendant) {
            $suffix = substr($descendant->path, strlen($oldPath) + 1);
            $descendant->path = $category->path.'/'.$suffix;
            $descendant->depth = substr_count($descendant->path, '/') + 1;
            $descendant->save();
        }
    }
}
