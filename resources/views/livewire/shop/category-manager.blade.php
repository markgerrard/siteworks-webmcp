<?php

use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Livewire\Concerns\ManagesCategoryHeroLayout;
use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Site;
use App\Services\Shop\CategoryTreeException;
use App\Services\Shop\CategoryTreeService;
use App\Services\Shop\CategoryContentService;
use App\Livewire\Concerns\DemoUnavailable;
use App\Models\Shop\ShopHeroVersion;
use App\Services\Shop\ShopHeroGenerator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    use AuthorizesSiteAccess;
    use DemoUnavailable;
    use ManagesCategoryHeroLayout;

    #[Locked]
    public int $siteId;

    #[Locked]
    public ?string $storefrontHost = null;

    #[Locked]
    public string $editRoute = 'shop.admin.products.edit';

    #[Locked]
    public string $listRoute = 'sites.shop.products';

    private const ITEMS_CAP = 60;

    public string $newName = '';

    public ?int $editingId = null;

    public string $editName = '';

    public array $categories = [];

    public array $parentId = [];

    public array $isAnchor = [];

    public array $visibility = [];

    public array $metaTitle = [];

    public array $metaDescription = [];

    public array $sort = [];

    public array $descriptionLong = [];

    public array $faqs = [];

    public string $editorTab = 'settings';

    public ?array $contentDraft = null;

    public string $contentDraftStatus = 'idle';

    public bool $contentDraftApplied = false;

    public array $selected = [];

    public string $itemsView = 'grid';

    public string $itemsStatusFilter = 'all';

    /** @var list<array{id: int, name: string, status: string, price_from: bool, price_cents: ?int, image_url: ?string}> */
    public array $editingProducts = [];

    public int $editingProductsTotal = 0;

    public string $shopCurrency = 'GBP';

    /** @var list<array{id: int, image_url: string, created_at: string}> */
    public array $heroVersions = [];

    /**
     * @param  list<int>  $ids
     * @return array<string, string>
     */
    private function rulesForRowIds(array $ids): array
    {
        $rules = [];
        foreach ($ids as $id) {
            $rules['visibility.'.$id] = 'in:visible,hidden';
            $rules['sort.'.$id] = 'in:manual,name,newest,price_asc,price_desc';
        }

        return $rules;
    }

    private function rowName(int $id): string
    {
        foreach ($this->categories as $cat) {
            if ((int) $cat['id'] === $id) {
                return (string) $cat['name'];
            }
        }

        return 'Category';
    }

    /**
     * Mutations re-check the CURRENT flag: a component mounted while the shop was on must
     * fail closed once a manager disables it, not keep writing through the stale instance.
     */
    private function requireEnabledShop(): void
    {
        $site = Site::query()->find($this->siteId);
        abort_unless($site !== null && $site->shopEnabled(), 404);
    }

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        abort_unless($this->findAuthorizedSite(), 403);
        if (request()->routeIs('client.portal.*')) {
            $this->editRoute = 'client.portal.shop.products.edit';
            $this->listRoute = 'client.portal.shop.products';
        }
        $this->refresh();
    }

    public function hydrate(): void
    {
        $this->normaliseFaqLists();
    }

    public function addCategory(): void
    {
        $this->requireEnabledShop();
        $this->validate(['newName' => 'required|string|min:2|max:64']);

        try {
            app(CategoryTreeService::class)->create(
                $this->findAuthorizedSite(),
                $this->newName,
            );
        } catch (CategoryTreeException $e) {
            // Duplicate name → slug taken (the old -xxxx uniquifier is gone; slugs are now stable URLs).
            $this->addError('newName', 'A category with that name already exists.');

            return;
        }

        $this->newName = '';
        $this->refresh();
    }

    public function rename(int $id, string $name, ?string $slug = null): void
    {
        $this->requireEnabledShop();
        validator(
            ['name' => $name],
            ['name' => ['required', 'string', 'max:120']],
        )->validate();

        $cat = Category::where('site_id', $this->siteId)->findOrFail($id);
        try {
            app(CategoryTreeService::class)->rename($cat, $name, $slug);
        } catch (CategoryTreeException $e) {
            $this->addError($e->errorCode === 'validation' ? 'slug' : 'editName', $e->getMessage());

            return;
        }
        $this->refresh();
    }

    public function delete(int $id): void
    {
        $this->requireEnabledShop();
        $cat = Category::where('site_id', $this->siteId)->findOrFail($id);
        app(CategoryTreeService::class)->delete($cat);
        if ($this->editingId === $id) {
            $this->editingId = null;
            $this->editName = '';
            $this->dispatch('modal-close', name: 'category-editor');
        }
        $this->refresh();
    }

    public function openEditor(int $id): void
    {
        $this->requireEnabledShop();
        $cat = null;
        foreach ($this->categories as $row) {
            if ((int) $row['id'] === $id) {
                $cat = $row;
                break;
            }
        }
        abort_unless($cat !== null, 404);

        $this->editingId = $id;
        $this->editName = (string) $cat['name'];
        $this->editorTab = 'settings';
        $this->contentDraft = null;
        $this->contentDraftStatus = 'idle';
        $this->contentDraftApplied = false;
        $this->reloadRowFromModel($id);
        $this->resetErrorBag();
        $this->loadEditingProducts();
    }

    public function generateCategoryHero(int $categoryId): void
    {
        $this->demoUnavailable('category hero');
    }

    protected function demoNoticeChannel(): string
    {
        return 'shop-hero-msg';
    }

    private function _removedGenerateCategoryHero(int $categoryId): void
    {
        $site = $this->abortUnlessShopEnabled();

        $category = Category::where('site_id', $this->siteId)->findOrFail($categoryId);
        if (! $this->heroGenerationAllowed($site)) {
            return;
        }

        return;
        session()->flash('shop-hero-msg', "Hero for \"{$category->name}\" queued — refresh in a moment.");
    }

    public function selectVersion(int $versionId): void
    {
        $this->abortUnlessShopEnabled();
        $version = ShopHeroVersion::where('site_id', $this->siteId)->findOrFail($versionId);
        app(ShopHeroGenerator::class)->selectVersion($version);
        session()->flash('shop-hero-msg', 'Hero version applied.');
        $this->refresh();
    }

    /**
     * Paid generation is reachable from the client portal (T4), so cap it per site: 6 hero
     * generations per rolling hour across shop + category heroes.
     */
    private function heroGenerationAllowed(Site $site): bool
    {
        $key = 'shop-hero-gen:'.$site->id;
        if (RateLimiter::tooManyAttempts($key, 6)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            session()->flash('shop-hero-msg', "Hero generation limit reached (6 per hour) — try again in about {$minutes} min.");

            return false;
        }
        RateLimiter::hit($key, 3600);

        return true;
    }

    public function commitEditName(): void
    {
        if ($this->editingId === null) {
            return;
        }
        $this->rename($this->editingId, $this->editName);
    }

    public function closeEditor(): void
    {
        $id = $this->editingId;
        if ($id !== null) {
            $this->reloadRowFromModel($id);
            $this->js('document.getElementById('.json_encode('category-row-'.$id).')?.focus()');
        }
        $this->editingId = null;
        $this->editName = '';
        $this->resetErrorBag();
    }

    private function reloadRowFromModel(int $id): void
    {
        $cat = Category::query()->where('site_id', $this->siteId)->findOrFail($id);
        $this->parentId[$id] = $cat->parent_id;
        $this->isAnchor[$id] = (bool) $cat->is_anchor;
        $this->visibility[$id] = $cat->visibility ?: 'visible';
        $this->metaTitle[$id] = $cat->meta_title;
        $this->metaDescription[$id] = $cat->meta_description;
        $this->sort[$id] = $cat->sort ?: 'manual';
        $this->descriptionLong[$id] = $cat->description_long ?? '';
        $this->faqs[$id] = $this->normaliseFaqList($cat->faqs);
    }

    public function cancelEditor(): void
    {
        $this->closeEditor();
        $this->dispatch('modal-close', name: 'category-editor');
    }

    public function saveEditor(): void
    {
        $this->requireEnabledShop();
        abort_unless($this->editingId !== null, 404);
        $id = $this->editingId;
        $this->faqs[$id] = $this->normaliseFaqList($this->faqs[$id] ?? []);
        $content = [
            'description_long' => $this->descriptionLong[$id] ?? null,
            'faqs' => $this->faqs[$id] ?? [],
            'meta_title' => $this->metaTitle[$id] ?? null,
            'meta_description' => $this->metaDescription[$id] ?? null,
        ];
        if ($this->contentDraftApplied) {
            $content['is_ai_seeded'] = true;
        }
        $this->validate(['editName' => 'required|string|min:2|max:64']);
        if (! $this->saveTree($id)) {
            return;
        }
        if ($this->editName !== $this->rowName($id)) {
            $this->rename($id, $this->editName);
        }
        if (! $this->saveContent($id, $content)) {
            return;
        }
        if ($this->contentDraftApplied) {
            Cache::forget($this->draftCacheKey());
        }
        $this->closeEditor();
        $this->dispatch('modal-close', name: 'category-editor');
    }

    public function setParent(int $id, mixed $parentId): void
    {
        $this->requireEnabledShop();
        $cat = Category::where('site_id', $this->siteId)->findOrFail($id);

        try {
            $parent = $this->resolveParent($parentId);
            app(CategoryTreeService::class)->move($cat, $parent);
        } catch (CategoryTreeException $e) {
            $this->openEditor($id);
            $this->parentId[$id] = is_numeric($parentId) && (int) $parentId > 0
                ? (int) $parentId
                : null;
            $this->addError('parentId.'.$id, $e->getMessage());

            return;
        }

        $this->refresh();
    }

    public function toggleVisibility(int $id): void
    {
        $this->requireEnabledShop();
        $cat = Category::where('site_id', $this->siteId)->findOrFail($id);
        $next = ($cat->visibility ?: 'visible') === 'visible' ? 'hidden' : 'visible';
        $this->applyVisibilityToSubtree($cat, $next);
        $this->refresh();
    }

    public function bulkSetVisibility(string $visibility): void
    {
        $this->requireEnabledShop();
        if (! in_array($visibility, ['visible', 'hidden'], true)) {
            return;
        }

        $ids = collect($this->selected)
            ->filter(fn (mixed $value): bool => (bool) $value)
            ->keys()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return;
        }

        $foundCount = Category::query()
            ->where('site_id', $this->siteId)
            ->whereIn('id', $ids)
            ->count();

        if ($foundCount !== count($ids)) {
            $this->addError('selected', 'Every selected category must belong to this site.');

            return;
        }

        DB::transaction(function () use ($ids, $visibility): void {
            foreach ($ids as $id) {
                $cat = Category::query()->where('site_id', $this->siteId)->find($id);
                if ($cat === null) {
                    continue;
                }
                $this->applyVisibilityToSubtree($cat, $visibility);
            }
        });

        $this->selected = [];
        $this->refresh();
    }

    public function saveTree(int $id): bool
    {
        $this->requireEnabledShop();
        $name = $this->rowName($id);
        $this->validate(
            $this->rulesForRowIds([$id]),
            [
                'visibility.'.$id.'.in' => $name.': visibility is invalid.',
                'sort.'.$id.'.in' => $name.': sort is invalid.',
            ],
        );

        $cat = Category::where('site_id', $this->siteId)->findOrFail($id);
        $service = app(CategoryTreeService::class);

        $parentId = $this->parentId[$id] ?? null;
        try {
            $parent = $this->resolveParent($parentId);
        } catch (CategoryTreeException $e) {
            $this->addError('parentId.'.$id, $e->getMessage());

            return false;
        }
        if ($cat->parent_id !== $parent?->id) {
            try {
                $cat = $service->move($cat, $parent);
            } catch (CategoryTreeException $e) {
                $this->addError('parentId.'.$id, $e->getMessage());

                return false;
            }
        }

        $visibility = $this->visibility[$id] ?? 'visible';
        if (($cat->visibility ?: 'visible') !== $visibility) {
            $this->applyVisibilityToSubtree($cat, $visibility);
            $cat = $cat->fresh();
        }

        $service->updateAttributes($cat, [
            'is_anchor' => (bool) ($this->isAnchor[$id] ?? true),
            'visibility' => $visibility,
            'meta_title' => $this->metaTitle[$id] ?? null,
            'meta_description' => $this->metaDescription[$id] ?? null,
            'sort' => $this->sort[$id] ?? 'manual',
        ]);

        $this->refresh();

        return true;
    }

    public function addFaq(): void
    {
        $this->requireEnabledShop();
        abort_unless($this->editingId !== null, 404);
        $id = $this->editingId;
        $items = $this->normaliseFaqList($this->faqs[$id] ?? []);
        if (count($items) < 12) {
            $items[] = ['q' => '', 'a' => ''];
            $this->faqs[$id] = $items;
        }
    }

    public function draftContent(): void
    {
        $this->demoUnavailable('category content');
    }

    public function pollContentDraft(): void
    {
        $this->requireEnabledShop();
        if ($this->contentDraftStatus !== 'drafting') {
            return;
        }
        $state = Cache::get($this->draftCacheKey());
        if (! is_array($state)) {
            return;
        }
        $this->contentDraftStatus = $state['status'] ?? 'failed';
        $this->contentDraft = is_array($state['draft'] ?? null) ? $state['draft'] : null;
    }

    public function useContentDraft(): void
    {
        $this->requireEnabledShop();
        abort_unless($this->editingId !== null && $this->contentDraft !== null, 404);
        $id = $this->editingId;
        $this->descriptionLong[$id] = $this->contentDraft['description_long'] ?? '';
        $this->faqs[$id] = $this->normaliseFaqList($this->contentDraft['faqs'] ?? []);
        $this->metaTitle[$id] = $this->contentDraft['meta_title'] ?? '';
        $this->metaDescription[$id] = $this->contentDraft['meta_description'] ?? '';
        $this->contentDraftApplied = true;
    }

    public function discardContentDraft(): void
    {
        Cache::forget($this->draftCacheKey());
        $this->contentDraft = null;
        $this->contentDraftStatus = 'idle';
    }

    public function removeFaq(int $index): void
    {
        $this->requireEnabledShop();
        abort_unless($this->editingId !== null, 404);
        $id = $this->editingId;
        $items = $this->normaliseFaqList($this->faqs[$id] ?? []);
        if (array_key_exists($index, $items)) {
            unset($items[$index]);
            $this->faqs[$id] = array_values($items);
        }
    }

    public function moveFaq(int $from, int $to): void
    {
        $this->requireEnabledShop();
        abort_unless($this->editingId !== null, 404);
        $id = $this->editingId;
        $items = $this->normaliseFaqList($this->faqs[$id] ?? []);
        if (! array_key_exists($from, $items) || $to < 0 || $to >= count($items)) {
            return;
        }
        $item = array_splice($items, $from, 1);
        array_splice($items, $to, 0, $item);
        $this->faqs[$id] = array_values($items);
    }

    /**
     * @param  array{description_long: ?string, faqs: array, meta_title: ?string, meta_description: ?string, is_ai_seeded?: bool}  $content
     */
    private function saveContent(int $id, array $content): bool
    {
        $this->requireEnabledShop();
        $content['faqs'] = $this->normaliseFaqList($content['faqs'] ?? []);
        $this->faqs[$id] = $content['faqs'];
        validator($content, [
            'description_long' => ['nullable', 'string'],
            'faqs' => ['nullable', 'array', 'max:12'],
            'faqs.*.q' => ['required_with:faqs', 'string', 'max:160'],
            'faqs.*.a' => ['required_with:faqs', 'string', 'max:1200'],
            'meta_title' => ['nullable', 'string', 'max:70'],
            'meta_description' => ['nullable', 'string', 'max:170'],
        ])->validate();

        $category = Category::query()->where('site_id', $this->siteId)->findOrFail($id);
        try {
            app(CategoryContentService::class)->update($category, $content);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->addError('descriptionLong.'.$id, $e->errors()['description_long'][0] ?? 'Category content is invalid.');

            return false;
        }

        return true;
    }

    private function draftCacheKey(): string
    {
        return 'category-copy-draft:'.$this->siteId.':'.$this->editingId.':'.auth()->id();
    }

    /**
     * @return list<mixed>
     */
    private function normaliseFaqList(mixed $faqs): array
    {
        return is_array($faqs) ? array_values($faqs) : [];
    }

    private function normaliseFaqLists(): void
    {
        foreach ($this->faqs as $id => $faqs) {
            $this->faqs[$id] = $this->normaliseFaqList($faqs);
        }
    }

    private function applyVisibilityToSubtree(Category $cat, string $visibility): void
    {
        $service = app(CategoryTreeService::class);
        $service->updateAttributes($cat, ['visibility' => $visibility]);
        foreach ($cat->descendants() as $descendant) {
            $service->updateAttributes($descendant, ['visibility' => $visibility]);
        }
    }

    /**
     * Empty / null / 0 means top level. Any other value must resolve on this site.
     */
    private function resolveParent(mixed $parentId): ?Category
    {
        if ($parentId === null || $parentId === '' || $parentId === 0 || $parentId === '0') {
            return null;
        }

        if (! is_numeric($parentId) || (int) $parentId <= 0) {
            throw CategoryTreeException::notFound();
        }

        $parent = Category::query()->where('site_id', $this->siteId)->find((int) $parentId);
        if ($parent === null) {
            throw CategoryTreeException::notFound();
        }

        return $parent;
    }

    /**
     * Flatten categories so siblings follow sort_order then name under each parent.
     *
     * @param  Collection<int, Category>  $rows
     * @return Collection<int, Category>
     */
    private function orderedTree(Collection $rows): Collection
    {
        $childrenByParent = $rows->groupBy(fn (Category $cat): string => (string) ($cat->parent_id ?? 'root'))
            ->map(fn (Collection $group): Collection => $group
                ->sortBy([
                    ['sort_order', 'asc'],
                    ['name', 'asc'],
                ])
                ->values());

        $flatten = function (string $parentKey) use (&$flatten, $childrenByParent): Collection {
            return ($childrenByParent->get($parentKey) ?? collect())->flatMap(
                fn (Category $cat): Collection => collect([$cat])->concat($flatten((string) $cat->id)),
            );
        };

        return $flatten('root');
    }

    /**
     * Height and descendant ids from the already-loaded tree — no per-row queries.
     *
     * @param  Collection<int, Category>  $rows
     * @return array{0: array<int, int>, 1: array<int, list<int>>}
     */
    private function subtreeStats(Collection $rows): array
    {
        $childrenByParent = [];
        foreach ($rows as $cat) {
            $childrenByParent[(int) ($cat->parent_id ?? 0)][] = $cat;
        }

        $heightById = [];
        $descendantIdsById = [];

        $compute = function (Category $cat) use (&$compute, $childrenByParent, &$heightById, &$descendantIdsById): void {
            $id = (int) $cat->id;
            if (isset($heightById[$id])) {
                return;
            }

            $descendantIds = [];
            $height = 1;
            foreach ($childrenByParent[$id] ?? [] as $child) {
                $compute($child);
                $childId = (int) $child->id;
                $descendantIds[] = $childId;
                foreach ($descendantIdsById[$childId] as $descendantId) {
                    $descendantIds[] = $descendantId;
                }
                $height = max($height, $heightById[$childId] + 1);
            }

            $heightById[$id] = $height;
            $descendantIdsById[$id] = $descendantIds;
        };

        foreach ($rows as $cat) {
            $compute($cat);
        }

        return [$heightById, $descendantIdsById];
    }

    private function refresh(): void
    {
        $rows = $this->orderedTree(
            Category::where('site_id', $this->siteId)
                ->withCount('products')
                ->get(),
        );
        $this->storefrontHost = $this->findAuthorizedSite()?->publicHost();
        [$heightById, $descendantIdsById] = $this->subtreeStats($rows);

        $this->categories = $rows->map(function (Category $cat) use ($rows, $heightById, $descendantIdsById): array {
            $height = $heightById[(int) $cat->id];
            $descendantIds = $descendantIdsById[(int) $cat->id];
            $ancestorIds = [];
            $walkId = $cat->parent_id;
            while ($walkId !== null) {
                $ancestorIds[] = (int) $walkId;
                $walkId = $rows->firstWhere('id', $walkId)?->parent_id;
            }

            return [
                'id' => $cat->id,
                'name' => $cat->name,
                'slug' => $cat->slug,
                'depth' => (int) $cat->depth,
                'parent_id' => $cat->parent_id,
                'path' => $cat->path,
                'storefront_path' => \App\Support\Shop\ShopUrls::collection($cat->path),
                'product_count' => (int) $cat->products_count,
                'hero_image_url' => $cat->hero_image_url,
                'hero_alt' => $cat->hero_alt,
                'hero_height' => $cat->hero_height,
                'bg_position_y' => $cat->bg_position_y,
                'text_zone' => $cat->text_zone,
                'hero_width' => $cat->hero_width,
                'hero_enabled' => (bool) ($cat->hero_enabled ?? true),
                'hero_mode' => $cat->hero_mode,
                'hero_text_style' => $cat->hero_text_style,
                'hero_accent_word' => $cat->hero_accent_word,
                'intro_band' => (bool) ($cat->intro_band ?? false),
                'has_children' => $rows->contains(fn (Category $other): bool => (int) $other->parent_id === (int) $cat->id),
                'ancestor_ids' => array_reverse($ancestorIds),
                'is_anchor' => (bool) $cat->is_anchor,
                'visibility' => $cat->visibility ?: 'visible',
                'descendant_ids' => $descendantIds,
                'parent_options' => $rows
                    ->reject(fn (Category $other) => $other->id === $cat->id || in_array($other->id, $descendantIds, true))
                    ->map(fn (Category $other): array => [
                        'id' => $other->id,
                        'name' => $other->name,
                        'disabled' => ($other->depth + $height) > CategoryTreeService::MAX_DEPTH,
                    ])
                    ->values()
                    ->all(),
            ];
        })->all();

        foreach ($rows as $cat) {
            $this->parentId[$cat->id] = $cat->parent_id;
            $this->isAnchor[$cat->id] = (bool) $cat->is_anchor;
            $this->visibility[$cat->id] = $cat->visibility ?: 'visible';
            $this->metaTitle[$cat->id] = $cat->meta_title;
            $this->metaDescription[$cat->id] = $cat->meta_description;
            $this->sort[$cat->id] = $cat->sort ?: 'manual';
            $this->descriptionLong[$cat->id] = $cat->description_long ?? '';
            $this->faqs[$cat->id] = $this->normaliseFaqList($cat->faqs);
        }

        $this->shopCurrency = $this->findAuthorizedSite()?->shop_currency ?? 'GBP';
        $this->loadEditingProducts();
    }

    private function loadEditingProducts(): void
    {
        $this->editingProducts = [];
        $this->editingProductsTotal = 0;
        $this->heroVersions = [];
        if ($this->editingId === null) {
            return;
        }

        $this->heroVersions = ShopHeroVersion::query()
            ->where('site_id', $this->siteId)
            ->where('scope', 'category')
            ->where('scope_id', $this->editingId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ShopHeroVersion $version): array => [
                'id' => $version->id,
                'image_url' => $version->image_url,
                'created_at' => $version->created_at->format('d M H:i'),
            ])
            ->all();

        $row = null;
        foreach ($this->categories as $cat) {
            if ((int) $cat['id'] === $this->editingId) {
                $row = $cat;
                break;
            }
        }
        if ($row === null) {
            return;
        }

        $ids = [(int) $row['id']];
        if ($row['is_anchor']) {
            foreach ($row['descendant_ids'] ?? [] as $descendantId) {
                $ids[] = (int) $descendantId;
            }
        }

        $base = Product::query()
            ->where('site_id', $this->siteId)
            ->whereHas('categories', fn ($query) => $query->whereIn('shop_categories.id', $ids));

        $this->editingProductsTotal = (clone $base)->count();

        $this->editingProducts = (clone $base)
            ->with(['images', 'variants'])
            ->orderBy('name')
            ->limit(self::ITEMS_CAP)
            ->get()
            ->map(function (Product $product): array {
                $image = $product->primary_image_id
                    ? $product->images->firstWhere('id', $product->primary_image_id)
                    : null;
                $image ??= $product->images->first();
                $minCents = $product->variants->min('price_cents');

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'status' => $product->status->value,
                    'price_from' => (bool) $product->price_from,
                    'price_cents' => $minCents !== null ? (int) $minCents : null,
                    'image_url' => $image?->url('thumb'),
                ];
            })
            ->all();
    }
}; ?>

<div class="space-y-4">
    @php
        $editing = $editingId === null
            ? null
            : collect($categories)->firstWhere('id', $editingId);
    @endphp

    @if ($editing)
        @include('livewire.shop.partials.category-detail')
    @else
    <div class="flex flex-col sm:flex-row gap-2 items-start">
        <flux:input wire:model="newName" placeholder="New category name" class="flex-1" />
        <flux:button variant="primary" wire:click="addCategory" wire:target="addCategory">Add category</flux:button>
    </div>
    <flux:error name="newName" />
    <flux:error name="name" />

    <p wire:loading class="text-sm text-zinc-500 dark:text-zinc-400">Loading…</p>

    @if ($categories === [])
        <div class="flex flex-col items-center justify-center rounded-xl border border-neutral-200 p-12 dark:border-neutral-700">
            <flux:heading size="lg">No categories yet — add one to start organising the shop.</flux:heading>
        </div>
    @else
        @if (collect($selected)->contains(fn (mixed $value): bool => (bool) $value))
            <div class="flex items-center gap-2">
                <flux:button size="sm" wire:click="bulkSetVisibility('hidden')" wire:target="bulkSetVisibility">Hide</flux:button>
                <flux:button size="sm" wire:click="bulkSetVisibility('visible')" wire:target="bulkSetVisibility">Show</flux:button>
            </div>
            <flux:error name="selected" />
        @endif
        <div
            class="divide-y divide-zinc-200 dark:divide-zinc-700"
            x-data="{
                key: 'shop-category-tree-{{ $siteId }}',
                collapsed: [],
                init() {
                    try {
                        this.collapsed = JSON.parse(localStorage.getItem(this.key) || '[]')
                    } catch (e) {
                        this.collapsed = []
                    }
                },
                persist() {
                    localStorage.setItem(this.key, JSON.stringify(this.collapsed))
                },
                isCollapsed(id) {
                    return this.collapsed.includes(id)
                },
                toggle(id) {
                    this.collapsed = this.isCollapsed(id)
                        ? this.collapsed.filter((item) => item !== id)
                        : this.collapsed.concat([id])
                    this.persist()
                },
                hiddenByAncestors(ids) {
                    return ids.some((id) => this.collapsed.includes(id))
                },
            }"
        >
            @foreach ($categories as $cat)
                <div
                    wire:key="category-{{ $cat['id'] }}"
                    data-category-id="{{ $cat['id'] }}"
                    data-depth="{{ $cat['depth'] }}"
                    data-product-count="{{ $cat['product_count'] }}"
                    data-is-anchor="{{ $cat['is_anchor'] ? 'true' : 'false' }}"
                    x-show="! hiddenByAncestors({{ \Illuminate\Support\Js::from($cat['ancestor_ids']) }})"
                    class="flex items-center gap-3 py-2"
                >
                    <flux:checkbox wire:model.live="selected.{{ $cat['id'] }}" :aria-label="'Select '.$cat['name']" />
                    @if ($cat['hero_image_url'])
                        <img
                            src="{{ $cat['hero_image_url'] }}"
                            alt=""
                            class="h-10 w-10 shrink-0 rounded object-cover"
                            data-category-thumb="{{ $cat['id'] }}"
                        >
                    @else
                        <span
                            class="h-10 w-10 shrink-0 rounded bg-zinc-200 dark:bg-zinc-700"
                            data-category-thumb-placeholder="{{ $cat['id'] }}"
                            aria-hidden="true"
                        ></span>
                    @endif
                    <div class="flex self-stretch" aria-hidden="true">
                        @for ($rail = 1; $rail < $cat['depth']; $rail++)
                            <span class="category-tree-rail w-4 shrink-0 border-s border-zinc-200 dark:border-zinc-700"></span>
                        @endfor
                    </div>

                    @if ($cat['has_children'])
                        <button
                            type="button"
                            data-disclosure-id="{{ $cat['id'] }}"
                            class="shrink-0 text-zinc-500 dark:text-zinc-400"
                            x-on:click.stop="toggle({{ $cat['id'] }})"
                            aria-expanded="true"
                            x-bind:aria-expanded="(! isCollapsed({{ $cat['id'] }})).toString()"
                            aria-label="Toggle {{ $cat['name'] }}"
                        >
                            <flux:icon.chevron-down class="size-4" x-bind:class="isCollapsed({{ $cat['id'] }}) && '-rotate-90'" />
                        </button>
                    @else
                        <span class="inline-block size-4 shrink-0" aria-hidden="true"></span>
                    @endif

                    <button
                        type="button"
                        id="category-row-{{ $cat['id'] }}"
                        class="min-w-0 truncate text-start text-sm font-semibold text-zinc-900 hover:underline dark:text-zinc-100"
                        wire:click="openEditor({{ $cat['id'] }})"
                    >
                        {{ $cat['name'] }}
                    </button>

                    <flux:text class="tabular-nums text-zinc-500 dark:text-zinc-400">{{ $cat['product_count'] }}</flux:text>

                    <div class="flex flex-wrap items-center gap-1.5">
                        <flux:badge size="sm">{{ $cat['visibility'] === 'hidden' ? 'Hidden' : 'Visible' }}</flux:badge>
                        @if ($cat['is_anchor'])
                            <flux:badge size="sm">Anchor</flux:badge>
                        @endif
                    </div>

                    @if ($storefrontHost)
                        <a
                            href="https://{{ $storefrontHost }}{{ $cat['storefront_path'] }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="ms-auto min-w-0 truncate text-sm text-zinc-500 hover:underline dark:text-zinc-400"
                            x-on:click.stop
                        >{{ $cat['storefront_path'] }}</a>
                    @endif

                    <div @class(['shrink-0', 'ms-auto' => ! $storefrontHost])>
                        <flux:dropdown align="end">
                            <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" aria-label="More actions for {{ $cat['name'] }}"></flux:button>
                            <flux:menu>
                                <flux:menu.submenu heading="Move to…">
                                    <flux:menu.item wire:click="setParent({{ $cat['id'] }}, 0)">Top level</flux:menu.item>
                                    @foreach ($cat['parent_options'] as $option)
                                        <flux:menu.item
                                            wire:click="setParent({{ $cat['id'] }}, {{ $option['id'] }})"
                                            :disabled="$option['disabled']"
                                        >{{ $option['name'] }}</flux:menu.item>
                                    @endforeach
                                </flux:menu.submenu>
                                <flux:menu.item wire:click="toggleVisibility({{ $cat['id'] }})">
                                    {{ $cat['visibility'] === 'hidden' ? 'Show' : 'Hide' }}
                                </flux:menu.item>
                                <flux:menu.separator />
                                <flux:menu.item
                                    variant="danger"
                                    wire:click="delete({{ $cat['id'] }})"
                                    wire:confirm="Delete {{ $cat['name'] }}? {{ $cat['product_count'] }} products will be unassigned."
                                    wire:target="delete({{ $cat['id'] }})"
                                >Delete</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
    @endif
</div>
