<?php

namespace App\Services\Site\Editor\Operations;

use App\Models\Shop\Category;
use App\Models\Shop\ShopDraft;
use App\Services\Shop\CategoryTreeException;
use App\Services\Shop\CategoryTreeService;
use App\Support\Shop\ShopUrls;
use Illuminate\Support\Str;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationFailed;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\Editor\Shop\ShopEntityResolver;
use App\Services\Site\Editor\Shop\ShopWriteLockset;
use App\Services\Site\Editor\Shop\ShopWriteOperation;

final class ManageCategoryOperation extends ShopWriteOperation
{
    /**
     * @var list<string>
     */
    private const VISIBILITY_VALUES = ['visible', 'hidden'];

    /**
     * @var list<string>
     */
    private const SORT_VALUES = ['manual', 'name', 'newest', 'price_asc', 'price_desc'];

    public function __construct(
        private readonly ShopEntityResolver $resolver,
        private readonly EditorStateFactory $states,
        private readonly CategoryTreeService $tree,
    ) {}

    public function name(): string
    {
        return 'manage_category';
    }

    public function sideEffects(): string
    {
        return 'Creates, moves, or deletes a catalogue category. This does not publish anything — the live storefront updates when the shop snapshot rebuilds.';
    }

    /**
     * @return list<string>|null
     */
    public function allowedRoles(): ?array
    {
        return ['staff', 'client'];
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['action', 'catalogue_revision'],
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['upsert', 'move', 'delete']],
                'name' => ['type' => 'string'],
                'slug' => ['type' => 'string'],
                'parent_slug' => ['type' => ['string', 'null']],
                'is_anchor' => ['type' => 'boolean'],
                'visibility' => ['type' => 'string', 'enum' => self::VISIBILITY_VALUES],
                'meta_title' => ['type' => 'string'],
                'meta_description' => ['type' => 'string'],
                'sort' => ['type' => 'string', 'enum' => self::SORT_VALUES],
                'catalogue_revision' => ['type' => 'integer'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<int>
     */
    public function subjectProductIds(array $input): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function handleShopWrite(EditorContext $ctx, array $input, ShopWriteLockset $locks): OperationResult
    {
        $state = $this->states->for($ctx->site, null);
        $this->resolver->requireShop($ctx->site);

        $action = $input['action'] ?? null;
        if (! in_array($action, ['upsert', 'move', 'delete'], true)) {
            throw new OperationFailed(OperationResult::fail(
                'validation',
                'action must be upsert, move or delete.',
                $state,
                ['fields' => ['action' => ['upsert|move|delete']]],
            ));
        }

        if (array_key_exists('visibility', $input) && ! in_array($input['visibility'], self::VISIBILITY_VALUES, true)) {
            throw new OperationFailed(OperationResult::fail(
                'validation',
                'visibility must be visible or hidden.',
                $state,
                ['fields' => ['visibility' => [implode('|', self::VISIBILITY_VALUES)]]],
            ));
        }

        if (array_key_exists('sort', $input) && ! in_array($input['sort'], self::SORT_VALUES, true)) {
            throw new OperationFailed(OperationResult::fail(
                'validation',
                'sort must be manual, name, newest, price_asc or price_desc.',
                $state,
                ['fields' => ['sort' => [implode('|', self::SORT_VALUES)]]],
            ));
        }

        try {
            $category = match ($action) {
                'upsert' => $this->upsert($ctx->site, $input, $state),
                'move' => $this->move($ctx->site, $input, $state),
                'delete' => $this->delete($ctx->site, $input, $state),
            };
        } catch (CategoryTreeException $e) {
            throw new OperationFailed(OperationResult::fail(
                $e->errorCode,
                $e->getMessage(),
                $state,
            ));
        }

        $locks->draft->catalogue_revision = (int) $locks->draft->catalogue_revision + 1;
        $locks->draft->updated_by_user_id = $ctx->actor->id;
        $locks->draft->save();

        $catalogue = (int) ShopDraft::query()->where('site_id', $ctx->site->id)->value('catalogue_revision');

        return OperationResult::ok([
            'slug' => $category->slug,
            'path' => $category->path,
            'depth' => (int) $category->depth,
            'parent_slug' => $category->parent?->slug,
            'catalogue_revision' => $catalogue,
        ], $state);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function upsert(\App\Models\Site $site, array $input, $state): Category
    {
        $name = $input['name'] ?? null;
        $slug = is_string($input['slug'] ?? null) && $input['slug'] !== '' ? $input['slug'] : null;
        $parent = $this->parentFromSlug($site, $input['parent_slug'] ?? null);

        $attrs = [];
        foreach (['is_anchor', 'visibility', 'meta_title', 'meta_description', 'sort'] as $key) {
            if (array_key_exists($key, $input)) {
                $attrs[$key] = $input[$key];
            }
        }

        if (is_string($slug)) {
            $normalised = Str::slug($slug);
            if (ShopUrls::isReservedSlug($normalised) || ShopUrls::isReservedPath($normalised)) {
                throw new OperationFailed(OperationResult::fail(
                    'validation',
                    'That slug is reserved for a storefront page.',
                    $state,
                    ['fields' => ['slug' => ['reserved']]],
                ));
            }
            $existing = Category::query()->where('site_id', $site->id)->where('slug', $slug)->first();
            if ($existing !== null) {
                if (is_string($name) && $name !== '') {
                    $this->tree->rename($existing, $name);
                }
                $existing = $this->tree->updateAttributes($existing, $attrs);
                if (array_key_exists('parent_slug', $input) && $existing->parent_id !== $parent?->id) {
                    $existing = $this->tree->move($existing, $parent);
                }

                return $existing;
            }
            $attrs['slug'] = $slug;
        }

        if (! is_string($name) || trim($name) === '') {
            throw new OperationFailed(OperationResult::fail(
                'validation',
                'name is required.',
                $state,
                ['fields' => ['name' => ['required string']]],
            ));
        }

        return $this->tree->create($site, $name, $parent, $attrs);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function move(\App\Models\Site $site, array $input, $state): Category
    {
        $category = $this->requireCategory($site, $input['slug'] ?? null, $state);

        return $this->tree->move($category, $this->parentFromSlug($site, $input['parent_slug'] ?? null));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function delete(\App\Models\Site $site, array $input, $state): Category
    {
        $category = $this->requireCategory($site, $input['slug'] ?? null, $state);
        $snapshot = $category->replicate();
        $snapshot->parent_id = $category->parent_id;
        $snapshot->setRelation('parent', $category->parent);
        $this->tree->delete($category);

        return $snapshot;
    }

    private function parentFromSlug(\App\Models\Site $site, mixed $parentSlug): ?Category
    {
        if ($parentSlug === null || $parentSlug === '') {
            return null;
        }
        if (! is_string($parentSlug)) {
            throw CategoryTreeException::notFound();
        }

        return Category::query()
            ->where('site_id', $site->id)
            ->where('slug', $parentSlug)
            ->first() ?? throw CategoryTreeException::notFound();
    }

    private function requireCategory(\App\Models\Site $site, mixed $slug, $state): Category
    {
        if (! is_string($slug) || $slug === '') {
            throw new OperationFailed(OperationResult::fail(
                'validation',
                'slug is required.',
                $state,
                ['fields' => ['slug' => ['required string']]],
            ));
        }

        return Category::query()
            ->where('site_id', $site->id)
            ->where('slug', $slug)
            ->first() ?? throw CategoryTreeException::notFound();
    }
}
