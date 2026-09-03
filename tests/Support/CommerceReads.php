<?php

namespace Tests\Support;

use App\Enums\AgentRole;
use App\Enums\Shop\ShopSnapshotStatus;
use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\Category;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use App\Models\Site\EditorOperationLog;
use App\Models\User;
use App\Services\Shop\RenderContext;
use App\Services\Shop\SnapshotBuilder;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\OperationResult;
use Illuminate\Support\Facades\DB;

final class CommerceReads
{
    /**
     * Flyer-leg commerce operations (spec v7 § 7).
     *
     * @return list<string>
     */
    public static function operations(): array
    {
        return [
            'list_products',
            'get_product',
            'draft_product',
            'update_draft_product',
            'set_product_image',
            'manage_category',
            'draft_category_content',
        ];
    }

    /**
     * Commerce sandbox set: the flyer-leg operations plus the single upload path.
     *
     * @return list<string>
     */
    public static function sandboxSet(): array
    {
        return [...self::operations(), 'upload_image', 'export_products', 'describe_import_products', 'import_products'];
    }

    /**
     * @return array{0: User, 1: Site, 2: Category}
     */
    public static function shopSite(): array
    {
        $actor = User::factory()->staff(AgentRole::Agent)->create();
        $site = Site::factory()->create(['created_by_user_id' => $actor->id]);
        $category = Category::factory()->for($site)->create(['slug' => 'candles', 'name' => 'Candles']);
        self::giveShop($site);

        return [$actor, $site, $category];
    }

    public static function drainRebuild(int $siteId): void
    {
        (new RebuildShopSnapshot($siteId))->handle(app(SnapshotBuilder::class));
    }

    public static function giveShop(Site $site): void
    {
        if (ShopSnapshotCurrent::query()->where('site_id', $site->id)->exists()) {
            return;
        }

        $snapshot = ShopSnapshot::query()->create([
            'site_id' => $site->id,
            'version' => 1,
            'status' => ShopSnapshotStatus::Success,
            'json' => ['products' => [], 'categories' => []],
            'built_at' => now(),
        ]);
        ShopSnapshotCurrent::query()->create([
            'site_id' => $site->id,
            'snapshot_id' => $snapshot->id,
            'updated_at' => now(),
        ]);
    }

    public static function enableFlags(): void
    {
        config([
            'editor.operations.enabled' => true,
            'editor.agent_tools.enabled' => true,
            'editor.agent_tools.roles' => ['staff'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function run(
        User $actor,
        Site $site,
        string $operation,
        array $input = [],
        ActorChannel $channel = ActorChannel::Ui,
    ): OperationResult {
        self::enableFlags();

        return app(EditorOperations::class)->run(
            new EditorContext($actor, $site, $channel),
            $operation,
            $input,
        );
    }

    public static function exposeOnSandbox(string ...$operations): void
    {
        config([
            'editor.exposure.sets.sandbox' => array_values(array_unique([
                ...(array) config('editor.exposure.sets.sandbox'),
                ...$operations,
            ])),
        ]);
    }

    public static function omitFromSandbox(string ...$operations): void
    {
        config([
            'editor.exposure.sets.sandbox' => array_values(array_diff(
                (array) config('editor.exposure.sets.sandbox'),
                $operations,
            )),
        ]);
    }

    public static function auditCount(Site $site, string $operation, ?string $resultCode = null): int
    {
        return EditorOperationLog::query()
            ->where('site_id', $site->id)
            ->where('operation', $operation)
            ->when($resultCode !== null, fn ($query) => $query->where('result_code', $resultCode))
            ->count();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function draftProductInput(array $overrides = []): array
    {
        return array_merge([
            'catalogue_revision' => 0,
            'name' => 'Hand-poured Candle',
            'description' => 'Soy wax, cotton wick.',
            'category_slug' => 'candles',
            'variants' => [
                ['sku' => 'CNDL-DEF', 'label' => 'Default', 'price_pence' => 1299],
            ],
        ], $overrides);
    }

    /**
     * PublicCatalogueProjection: filterSnapshot() output minus meta, at the
     * pointed-at snapshot, as spec §9 defines it.
     *
     * @return array{categories: mixed, products: mixed, featured_slugs: mixed, hero_image_url: mixed, hero_alt: mixed, hero_height: mixed, bg_position_y: mixed, text_zone: mixed, hero_width: mixed, hero_enabled: mixed, hero_headline: mixed, hero_text_style: mixed, shared_category_hero: mixed}
     */
    public static function publicProjection(Site $site): array
    {
        $pointer = ShopSnapshotCurrent::query()->where('site_id', $site->id)->first();
        $json = [];
        $version = 0;

        if ($pointer !== null) {
            $snapshot = ShopSnapshot::query()->find($pointer->snapshot_id);
            $json = is_array($snapshot?->json) ? $snapshot->json : [];
            $version = (int) ($snapshot?->version ?? 0);
        }

        $filtered = (new RenderContext(false))->filterSnapshot($json);
        unset($filtered['meta']);

        return [
            'categories' => $filtered['categories'] ?? [],
            'products' => $filtered['products'] ?? [],
            'featured_slugs' => $filtered['featured_slugs'] ?? [],
            'hero_image_url' => $filtered['hero_image_url'] ?? null,
            'hero_alt' => $filtered['hero_alt'] ?? null,
            'hero_height' => $filtered['hero_height'] ?? null,
            'bg_position_y' => $filtered['bg_position_y'] ?? null,
            'text_zone' => $filtered['text_zone'] ?? null,
            'hero_width' => $filtered['hero_width'] ?? null,
            'hero_enabled' => $filtered['hero_enabled'] ?? null,
            'hero_headline' => $filtered['hero_headline'] ?? null,
            'hero_text_style' => $filtered['hero_text_style'] ?? null,
            'shared_category_hero' => $filtered['shared_category_hero'] ?? null,
            '_pointer_version' => $version,
        ];
    }

    /**
     * @return array<string, int>
     */
    public static function commerceSideTableCounts(): array
    {
        return [
            'shop_orders' => DB::table('shop_orders')->count(),
            'shop_order_items' => DB::table('shop_order_items')->count(),
            'shop_customers' => DB::table('shop_customers')->count(),
            'shop_carts' => DB::table('shop_carts')->count(),
            'shop_cart_items' => DB::table('shop_cart_items')->count(),
            'shop_stock_reservations' => DB::table('shop_stock_reservations')->count(),
            'shop_inventory_movements' => DB::table('shop_inventory_movements')->count(),
            'shop_categories' => DB::table('shop_categories')->count(),
        ];
    }
}
