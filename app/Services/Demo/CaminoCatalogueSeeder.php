<?php

namespace App\Services\Demo;

use App\Enums\Shop\InventoryReason;
use App\Enums\Shop\ProductStatus;
use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Shop\ProductImage;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Services\Shop\CategoryTreeService;
use App\Services\Shop\ShopDraftWriter;
use App\Services\Shop\StockService;
use App\Support\MediaStorage;
use Illuminate\Support\Facades\File;

/**
 * Idempotent starter catalogue for the Camino Bakehouse demo: a patisserie
 * with four categories, ten published products each carrying a photograph,
 * and a shop_drafts revision so catalogue write tools have a coherent base.
 *
 * Photos live in the seed bundle's files/ tree under the same relative path
 * they occupy on the media disk, so the bundle import lands them in place
 * and this seeder only has to fill in whatever is missing.
 */
final class CaminoCatalogueSeeder
{
    /**
     * @var list<array{slug: string, name: string, sort_order: int}>
     */
    private const CATEGORIES = [
        ['slug' => 'viennoiserie', 'name' => 'Viennoiserie', 'sort_order' => 1],
        ['slug' => 'tarts-cakes', 'name' => 'Tarts & Cakes', 'sort_order' => 2],
        ['slug' => 'macarons', 'name' => 'Macarons', 'sort_order' => 3],
        ['slug' => 'seasonal-fall', 'name' => 'Seasonal — Fall', 'sort_order' => 4],
    ];

    /**
     * Product photos are keyed by slug: `<slug>.webp` under {@see self::PHOTO_DIR}.
     *
     * @var list<array{slug: string, name: string, description: string, price_cents: int, category: string, sku: string}>
     */
    private const PRODUCTS = [
        [
            'slug' => 'cultured-butter-croissant',
            'name' => 'Cultured Butter Croissant',
            'description' => 'Laminated with cultured butter; crisp outside, open and tender inside.',
            'price_cents' => 350,
            'category' => 'viennoiserie',
            'sku' => 'CB-CBC',
        ],
        [
            'slug' => 'almond-croissant',
            'name' => 'Almond Croissant',
            'description' => 'Twice-baked croissant filled with almond cream and finished with flaked almonds.',
            'price_cents' => 425,
            'category' => 'viennoiserie',
            'sku' => 'CB-ACR',
        ],
        [
            'slug' => 'cardamom-orange-morning-bun',
            'name' => 'Cardamom Orange Morning Bun',
            'description' => 'Croissant dough rolled with cardamom sugar and orange zest, baked until glossy.',
            'price_cents' => 400,
            'category' => 'viennoiserie',
            'sku' => 'CB-COB',
        ],
        [
            'slug' => 'chocolate-hazelnut-babka',
            'name' => 'Chocolate Hazelnut Babka (loaf)',
            'description' => 'Brioche loaf swirled with dark chocolate and topped with toasted hazelnuts.',
            'price_cents' => 950,
            'category' => 'viennoiserie',
            'sku' => 'CB-CHB',
        ],
        [
            'slug' => 'fig-walnut-tart',
            'name' => 'Fig & Walnut Tart',
            'description' => 'Sweet pastry shell with honeyed figs and toasted walnuts.',
            'price_cents' => 550,
            'category' => 'tarts-cakes',
            'sku' => 'CB-FWT',
        ],
        [
            'slug' => 'meyer-lemon-tart',
            'name' => 'Meyer Lemon Tart',
            'description' => 'Meyer lemon curd set in a sweet pastry shell.',
            'price_cents' => 500,
            'category' => 'tarts-cakes',
            'sku' => 'CB-MLT',
        ],
        [
            'slug' => 'chocolate-caramel-tart',
            'name' => 'Chocolate Caramel Tart',
            'description' => 'Salted caramel under a dark chocolate ganache in a cocoa pastry shell.',
            'price_cents' => 525,
            'category' => 'tarts-cakes',
            'sku' => 'CB-CCT',
        ],
        [
            'slug' => 'chocolate-espresso-birthday-cake',
            'name' => 'Chocolate Espresso Birthday Cake (whole, serves 10)',
            'description' => 'Chocolate sponge layered with espresso buttercream under a ganache drip. Whole cake, serves 10; please order two days ahead.',
            'price_cents' => 3800,
            'category' => 'tarts-cakes',
            'sku' => 'CB-CEB',
        ],
        [
            'slug' => 'dark-chocolate-orange-macarons',
            'name' => 'Dark Chocolate Orange Macarons (box of 6)',
            'description' => 'Six almond macarons filled with dark chocolate and orange ganache.',
            'price_cents' => 1200,
            'category' => 'macarons',
            'sku' => 'CB-DCM',
        ],
        [
            'slug' => 'meyer-lemon-macarons',
            'name' => 'Meyer Lemon Macarons (box of 6)',
            'description' => 'Six almond macarons filled with Meyer lemon curd.',
            'price_cents' => 1200,
            'category' => 'macarons',
            'sku' => 'CB-MLM',
        ],
    ];

    /** Media-disk directory holding the product photos, relative to the bundle's files/ tree. */
    private const PHOTO_DIR = 'site-media/64/products';

    public function __construct(
        private readonly ShopDraftWriter $writer,
        private readonly CategoryTreeService $categories,
        private readonly StockService $stock,
    ) {}

    /**
     * @param  string  $bundlePath  Seed bundle directory (contains bundle.json and files/).
     */
    public function seed(Site $site, string $bundlePath): void
    {
        $changed = false;

        $deferred = $this->writer->write($site, [], null, function () use ($site, $bundlePath, &$changed): void {
            $bySlug = $this->ensureCategories($site, $changed);

            foreach (self::PRODUCTS as $spec) {
                $category = $bySlug[$spec['category']] ?? null;
                if ($category === null) {
                    continue;
                }
                if ($this->upsertProduct($site, $category, $spec, $bundlePath)) {
                    $changed = true;
                }
            }

            if ($changed) {
                $this->writer->bumpCatalogue($site, null);
            }
        });

        $deferred();

        if ($changed || ! $this->snapshotImagesMatchDisk($site)) {
            dispatch_sync(new RebuildShopSnapshot($site->id));
        }
    }

    /**
     * The stored snapshot carries image addresses as the media disk emitted them
     * when it was built. If the disk's address base has since changed shape, those
     * strings are stale although no product changed, and only a rebuild brings the
     * storefront's images back in line with the disk. A missing snapshot counts as
     * stale.
     */
    private function snapshotImagesMatchDisk(Site $site): bool
    {
        $json = ShopSnapshotCurrent::query()->where('site_id', $site->id)->first()?->snapshot?->json;
        if (! is_array($json)) {
            return false;
        }

        $base = rtrim(MediaStorage::disk()->url(''), '/').'/';
        foreach ($json['products'] ?? [] as $product) {
            $urls = is_array($product) ? ($product['image_urls'] ?? null) : null;
            if (! is_array($urls)) {
                continue;
            }
            foreach (['thumb', 'card', 'full'] as $size) {
                $url = $urls[$size] ?? null;
                if (is_string($url) && $url !== '' && ! str_starts_with($url, $base)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param-out bool $changed
     * @return array<string, Category>
     */
    private function ensureCategories(Site $site, bool &$changed): array
    {
        $bySlug = [];

        foreach (self::CATEGORIES as $spec) {
            $category = Category::query()
                ->where('site_id', $site->id)
                ->where('slug', $spec['slug'])
                ->first();

            if ($category === null) {
                $category = $this->categories->create($site, $spec['name'], null, [
                    'slug' => $spec['slug'],
                    'sort_order' => $spec['sort_order'],
                ]);
                $changed = true;
            } else {
                if ($category->name !== $spec['name']) {
                    $category = $this->categories->rename($category, $spec['name']);
                    $changed = true;
                }
                if ((int) $category->sort_order !== $spec['sort_order']) {
                    $category->forceFill(['sort_order' => $spec['sort_order']])->save();
                    $changed = true;
                }
            }

            $bySlug[$spec['slug']] = $category;
        }

        return $bySlug;
    }

    /**
     * @param  array{slug: string, name: string, description: string, price_cents: int, category: string, sku: string}  $spec
     */
    private function upsertProduct(Site $site, Category $category, array $spec, string $bundlePath): bool
    {
        $product = Product::query()
            ->where('site_id', $site->id)
            ->where('slug', $spec['slug'])
            ->first();

        if ($product === null) {
            $product = Product::query()->create([
                'site_id' => $site->id,
                'slug' => $spec['slug'],
                'name' => $spec['name'],
                'description' => $spec['description'],
                'status' => ProductStatus::Published,
                'published_at' => now(),
                'revision' => 1,
            ]);
            $variant = ProductVariant::query()->create([
                'product_id' => $product->id,
                'sku' => $spec['sku'],
                'label' => null,
                'price_cents' => $spec['price_cents'],
            ]);
            $this->stock->initialiseVariant($variant->id);
            $this->stock->recordMovement($variant->id, 20, InventoryReason::Import, 'demo:seed');
            $product->categories()->sync([$category->id => ['is_primary' => true]]);
            $this->ensurePhoto($product, $spec, $bundlePath);

            return true;
        }

        $changed = false;
        $attrs = [];
        if ($product->name !== $spec['name']) {
            $attrs['name'] = $spec['name'];
        }
        if ((string) $product->description !== $spec['description']) {
            $attrs['description'] = $spec['description'];
        }
        if ($product->status !== ProductStatus::Published) {
            $attrs['status'] = ProductStatus::Published;
            if ($product->published_at === null) {
                $attrs['published_at'] = now();
            }
        }
        if ($attrs !== []) {
            $product->forceFill($attrs)->save();
            $changed = true;
        }

        $variant = $product->variants()->orderBy('id')->first();
        if ($variant === null) {
            $variant = ProductVariant::query()->create([
                'product_id' => $product->id,
                'sku' => $spec['sku'],
                'label' => null,
                'price_cents' => $spec['price_cents'],
            ]);
            $this->stock->initialiseVariant($variant->id);
            $this->stock->recordMovement($variant->id, 20, InventoryReason::Import, 'demo:seed');
            $changed = true;
        } else {
            $variantAttrs = [];
            if ((int) $variant->price_cents !== $spec['price_cents']) {
                $variantAttrs['price_cents'] = $spec['price_cents'];
            }
            if ($variant->sku !== $spec['sku']) {
                $variantAttrs['sku'] = $spec['sku'];
            }
            if ($variantAttrs !== []) {
                $variant->forceFill($variantAttrs)->save();
                $changed = true;
            }
            $onHand = (int) (VariantStock::query()->where('variant_id', $variant->id)->value('on_hand') ?? 0);
            if ($onHand === 0) {
                $this->stock->initialiseVariant($variant->id);
                $this->stock->recordMovement($variant->id, 20, InventoryReason::Import, 'demo:seed');
                $changed = true;
            }
        }

        $primaryId = $product->categories()->wherePivot('is_primary', true)->value('shop_categories.id');
        if ((int) $primaryId !== (int) $category->id) {
            $product->categories()->sync([$category->id => ['is_primary' => true]]);
            $changed = true;
        }

        if ($this->ensurePhoto($product, $spec, $bundlePath)) {
            $changed = true;
        }

        return $changed;
    }

    /**
     * Guarantee the product's primary image: the photo bytes on the media
     * disk and a shop_product_images row pointing at them. Returns true only
     * when the catalogue row was added; restoring missing bytes is not a
     * catalogue change.
     *
     * @param  array{slug: string, name: string}  $spec
     */
    private function ensurePhoto(Product $product, array $spec, string $bundlePath): bool
    {
        $path = self::PHOTO_DIR.'/'.$spec['slug'].'.webp';
        $source = rtrim($bundlePath, '/').'/files/'.$path;

        $disk = MediaStorage::disk();
        if (! $disk->exists($path) && File::isFile($source)) {
            $disk->put($path, File::get($source), 'public');
        }

        if ($product->images()->exists()) {
            return false;
        }

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => $path,
            'sort_order' => 0,
            'alt' => $spec['name'],
        ]);

        return true;
    }
}
