<?php

use App\Enums\AgentRole;
use App\Enums\PageKind;
use App\Enums\Shop\OrderStatus;
use App\Enums\Shop\ProductStatus;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\Shop\Category;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopHeroVersion;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Models\SiteMedia;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\ExpectedRevision;
use App\Services\Site\Editor\OperationFailed;
use App\Services\Site\Editor\OperationRegistry;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\Editor\RevisionScopes;
use App\Services\Site\Editor\Shop\DraftOnlyGuard;
use App\Services\Site\Editor\Shop\ShopEntityResolver;
use App\Services\Site\Editor\Shop\ShopWriteLockset;
use App\Services\Site\Editor\Shop\ShopWriteOperation;
use App\Services\Site\PageRenderer;
use App\Services\Site\SiteClone\SiteCloneCatalog;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\CommerceReads;

it('adds shop_products.revision as unsigned int not null default 0', function () {
    expect(Schema::hasColumn('shop_products', 'revision'))->toBeTrue();

    $column = shopColumn('shop_products', 'revision');
    expect($column->data_type)->toBe('integer')
        ->and($column->is_nullable)->toBe('NO')
        ->and((string) $column->column_default)->toContain('0');

    $site = Site::factory()->create();
    $id = DB::table('shop_products')->insertGetId([
        'site_id' => $site->id,
        'slug' => 'revision-default',
        'name' => 'Revision Default',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect((int) DB::table('shop_products')->where('id', $id)->value('revision'))->toBe(0);

    $factoryProduct = Product::factory()->create();
    expect((int) DB::table('shop_products')->where('id', $factoryProduct->id)->value('revision'))->toBe(0);
});

it('creates shop_drafts with site_id pk, catalogue_revision default 0, and no projection_epoch', function () {
    expect(Schema::hasTable('shop_drafts'))->toBeTrue()
        ->and(Schema::hasColumn('shop_drafts', 'projection_epoch'))->toBeFalse()
        ->and(Schema::hasTable('shop_hero_selections'))->toBeFalse()
        ->and(Schema::hasColumn('shop_snapshots', 'built_from_epoch'))->toBeFalse()
        ->and(Schema::hasColumn('shop_snapshots', 'clears_epoch'))->toBeFalse();

    $columns = collect(shopColumns('shop_drafts'))->keyBy('column_name');
    expect($columns->keys()->all())->toContain(
        'site_id',
        'catalogue_revision',
        'updated_by_user_id',
        'created_at',
        'updated_at',
    );

    $revision = $columns['catalogue_revision'];
    expect($revision->data_type)->toBe('integer')
        ->and($revision->is_nullable)->toBe('NO')
        ->and((string) $revision->column_default)->toContain('0');

    $pk = collect(DB::select(
        "SELECT kcu.column_name
         FROM information_schema.table_constraints tc
         JOIN information_schema.key_column_usage kcu
           ON tc.constraint_name = kcu.constraint_name
          AND tc.table_schema = kcu.table_schema
         WHERE tc.table_schema = current_schema()
           AND tc.table_name = 'shop_drafts'
           AND tc.constraint_type = 'PRIMARY KEY'
         ORDER BY kcu.ordinal_position"
    ))->pluck('column_name')->all();
    expect($pk)->toBe(['site_id']);

    $fks = collect(DB::select(
        "SELECT kcu.column_name, ccu.table_name AS foreign_table, rc.delete_rule
         FROM information_schema.referential_constraints rc
         JOIN information_schema.key_column_usage kcu
           ON rc.constraint_name = kcu.constraint_name
          AND rc.constraint_schema = kcu.constraint_schema
         JOIN information_schema.constraint_column_usage ccu
           ON rc.unique_constraint_name = ccu.constraint_name
          AND rc.unique_constraint_schema = ccu.constraint_schema
         WHERE kcu.table_schema = current_schema()
           AND kcu.table_name = 'shop_drafts'
         ORDER BY kcu.column_name"
    ))->keyBy('column_name');

    expect($fks['site_id']->foreign_table)->toBe('sites')
        ->and($fks['site_id']->delete_rule)->toBe('CASCADE')
        ->and($fks['updated_by_user_id']->foreign_table)->toBe('users')
        ->and($fks['updated_by_user_id']->delete_rule)->toBe('SET NULL');

    $site = Site::factory()->create();
    DB::table('shop_drafts')->insert(['site_id' => $site->id]);
    expect((int) DB::table('shop_drafts')->where('site_id', $site->id)->value('catalogue_revision'))->toBe(0);
});

it('adds nullable subject_type and subject_ref to editor_operation_log', function () {
    expect(Schema::hasColumns('editor_operation_log', ['subject_type', 'subject_ref']))->toBeTrue();

    $type = shopColumn('editor_operation_log', 'subject_type');
    $ref = shopColumn('editor_operation_log', 'subject_ref');

    expect($type->data_type)->toBe('character varying')
        ->and($type->character_maximum_length)->toBe(32)
        ->and($type->is_nullable)->toBe('YES')
        ->and($ref->data_type)->toBe('character varying')
        ->and($ref->character_maximum_length)->toBe(128)
        ->and($ref->is_nullable)->toBe('YES');
});

it('classifies shop_drafts as a documented shop exclusion in SiteCloneCatalog', function () {
    expect(SiteCloneCatalog::EXCLUDED_SITE_ID_TABLES)->toHaveKey('shop_drafts')
        ->and(SiteCloneCatalog::CHILD_TABLES)->not->toContain('shop_drafts');
});

it('renders a corpus site byte-identically with no shop_drafts row', function () {
    [$site, $pages] = commerceCorpusSite();

    expect(DB::table('shop_drafts')->where('site_id', $site->id)->exists())->toBeFalse();

    $html = commerceNormalise(app(PageRenderer::class)->render($site, $pages['home']->id, mode: 'public'));
    $fixture = base_path('tests/Fixtures/ByteIdentity/51-eden-classic-home.html');

    expect(file_get_contents($fixture))->toBe($html)
        ->and(DB::table('shop_drafts')->where('site_id', $site->id)->exists())->toBeFalse();
});

it('leaves EditorOperations.php unmodified', function () {
    $path = app_path('Services/Site/Editor/EditorOperations.php');
    $contents = (string) file_get_contents($path);
    $hash = sha1('blob '.strlen($contents)."\0".$contents);

    expect($hash)->toBe('96e0f1a31ca1c38106ce3b37613c6fb30ad5e6c2')
        ->and(RevisionScopes::inputKey('shop'))->toBe('catalogue_revision')
        ->and(RevisionScopes::scopes())->toContain('shop');
});

it('is a boot failure when a shop-addressed write does not extend ShopWriteOperation', function () {
    $rogue = new class extends \App\Services\Site\Editor\BaseOperation
    {
        public function name(): string
        {
            return 'rogue_shop_write';
        }

        public function address(): string
        {
            return 'shop';
        }

        public function readOnly(): bool
        {
            return false;
        }

        public function wrapInAdminChange(): bool
        {
            return false;
        }

        public function sideEffects(): string
        {
            return 'Rogue shop write.';
        }

        public function inputSchema(): array
        {
            return [
                'type' => 'object',
                'properties' => ['catalogue_revision' => ['type' => 'integer']],
            ];
        }

        public function handle(EditorContext $ctx, array $input): OperationResult
        {
            return OperationResult::ok(['wrote' => true], app(EditorStateFactory::class)->for($ctx->site, null));
        }
    };

    expect(fn () => new OperationRegistry([$rogue]))
        ->toThrow(\InvalidArgumentException::class, 'ShopWriteOperation');
});

it('fails closed when the shop revision check cannot resolve subject products', function () {
    ShopWriteOperation::clearIncoming();
    [$actor, $site] = commerceActorSite();
    $state = app(EditorStateFactory::class)->for($site, null);

    $result = RevisionScopes::check(
        'shop',
        new EditorContext($actor, $site, ActorChannel::Ui),
        0,
        $state,
    );

    expect($result)->toBeInstanceOf(OperationResult::class)
        ->and($result->ok)->toBeFalse();
});

it('refuses a stale catalogue_revision for every commerce write', function (string $operation) {
    CommerceReads::enableFlags();
    $this->seed(\Database\Seeders\Shop\TaxClassSeeder::class);
    [$actor, $site, $category] = CommerceReads::shopSite();
    $product = Product::factory()->for($site)->create(['name' => 'Original', 'slug' => 'original']);
    $product->categories()->attach($category->id, ['is_primary' => true]);
    $media = SiteMedia::factory()->for($site)->create(['s3_key' => 'shop/products/original.png']);
    DB::table('shop_drafts')->updateOrInsert(
        ['site_id' => $site->id],
        ['catalogue_revision' => 4, 'updated_at' => now(), 'created_at' => now()],
    );

    $input = match ($operation) {
        'draft_product' => CommerceReads::draftProductInput(['catalogue_revision' => 0]),
        'update_draft_product' => [
            'slug' => 'original',
            'catalogue_revision' => 0,
            'product_revision' => 0,
            'name' => 'Should Not Land',
        ],
        'set_product_image' => [
            'slug' => 'original',
            'catalogue_revision' => 0,
            'product_revision' => 0,
            'media_id' => $media->id,
        ],
        default => [],
    };

    $result = CommerceReads::run($actor, $site, $operation, $input);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('stale_revision')
        ->and($product->fresh()->name)->toBe('Original')
        ->and($product->images()->count())->toBe(0)
        ->and(Product::query()->where('site_id', $site->id)->where('name', 'Hand-poured Candle')->exists())->toBeFalse();
})->with(['draft_product', 'update_draft_product', 'set_product_image']);

it('returns stale_revision and writes nothing when catalogue_revision is stale', function () {
    [$actor, $site, $product] = commerceActorSiteWithProduct();
    $op = commerceWriteOp();
    commerceBindOperation($op);

    $stale = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Ui),
        'commerce_write',
        ['catalogue_revision' => 1, 'product_id' => $product->id, 'product_revision' => 0, 'name' => 'should-not-land'],
    );

    expect($stale->ok)->toBeFalse()
        ->and($stale->error['code'])->toBe('stale_revision')
        ->and($product->fresh()->name)->toBe('Original Name')
        ->and(DB::table('shop_drafts')->where('site_id', $site->id)->exists())->toBeFalse();

    $ok = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Ui),
        'commerce_write',
        ['catalogue_revision' => 0, 'product_id' => $product->id, 'product_revision' => 0, 'name' => 'First Write'],
    );

    expect($ok->ok)->toBeTrue()
        ->and($product->fresh()->name)->toBe('First Write')
        ->and((int) DB::table('shop_drafts')->where('site_id', $site->id)->value('catalogue_revision'))->toBe(0);
});

it('returns stale_revision for a stale product_revision checked under lockForUpdate', function () {
    [$actor, $site, $product] = commerceActorSiteWithProduct();
    DB::table('shop_products')->where('id', $product->id)->update(['revision' => 2]);
    $op = commerceWriteOp();
    commerceBindOperation($op);

    $locked = [];
    DB::listen(function (QueryExecuted $query) use (&$locked): void {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'for update')) {
            $locked[] = $sql;
        }
    });

    $stale = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Ui),
        'commerce_write',
        ['catalogue_revision' => 0, 'product_id' => $product->id, 'product_revision' => 1, 'name' => 'stale-product'],
    );

    expect($stale->ok)->toBeFalse()
        ->and($stale->error['code'])->toBe('stale_revision')
        ->and($product->fresh()->name)->toBe('Original Name')
        ->and($locked)->not->toBeEmpty()
        ->and($locked[0])->toContain('shop_products');
});

it('normalises expected_revision to catalogue_revision for a shop-addressed write', function () {
    [$actor, $site, $product] = commerceActorSiteWithProduct();
    $op = commerceWriteOp();
    commerceBindOperation($op);
    $ctx = new EditorContext($actor, $site, ActorChannel::Ui);

    $aliased = ExpectedRevision::normalise($op, ['expected_revision' => 0, 'product_id' => $product->id]);
    expect($aliased)->toBeArray()
        ->and($aliased['catalogue_revision'])->toBe(0);

    $conflict = ExpectedRevision::normalise($op, [
        'expected_revision' => 0,
        'catalogue_revision' => 1,
        'product_id' => $product->id,
    ]);
    expect($conflict)->toBeInstanceOf(OperationResult::class)
        ->and($conflict->error['code'])->toBe('validation');

    $ok = app(EditorOperations::class)->run($ctx, 'commerce_write', [
        'expected_revision' => 0,
        'product_id' => $product->id,
        'product_revision' => 0,
        'name' => 'From Alias',
    ]);
    expect($ok->ok)->toBeTrue()->and($product->fresh()->name)->toBe('From Alias');
});

it('returns models from ShopEntityResolver and not_found for foreign-site ids slugs and skus', function () {
    [$actor, $site] = commerceActorSite();
    $other = Site::factory()->create();
    $ownProduct = Product::factory()->for($site)->create(['slug' => 'own-slug', 'name' => 'Own']);
    $ownVariant = ProductVariant::factory()->for($ownProduct)->create(['sku' => 'OWN-SKU']);
    $foreignProduct = Product::factory()->for($other)->create(['slug' => 'foreign-slug']);
    ProductVariant::factory()->for($foreignProduct)->create(['sku' => 'FOREIGN-SKU']);
    ProductVariant::factory()->for($foreignProduct)->create(['sku' => 'OWN-SKU-FOREIGN-ONLY']);
    $ownMedia = SiteMedia::factory()->for($site)->create();
    $foreignMedia = SiteMedia::factory()->for($other)->create();
    $ownCategory = Category::factory()->for($site)->create(['slug' => 'own-cat']);
    Category::factory()->for($other)->create(['slug' => 'foreign-cat']);
    $ownVersion = ShopHeroVersion::query()->create([
        'site_id' => $site->id,
        'scope' => 'shop',
        'image_url' => 'https://cdn.test/own.png',
        'created_at' => now(),
    ]);
    $foreignVersion = ShopHeroVersion::query()->create([
        'site_id' => $other->id,
        'scope' => 'shop',
        'image_url' => 'https://cdn.test/foreign.png',
        'created_at' => now(),
    ]);
    $ownOrder = Order::create([
        'site_id' => $site->id, 'number' => 'OWN-1', 'email' => 'a@x.com', 'name' => 'A',
        'status' => OrderStatus::Paid->value, 'refund_status' => 'none',
        'subtotal_cents' => 100, 'shipping_cents' => 0, 'tax_cents' => 0,
        'shipping_tax_cents' => 0, 'total_cents' => 100,
        'tax_country_code' => 'GB', 'shipping_address_json' => [],
        'shipping_method_label' => 'Std', 'placed_at' => now(),
    ]);
    Order::create([
        'site_id' => $other->id, 'number' => 'FOR-1', 'email' => 'b@x.com', 'name' => 'B',
        'status' => OrderStatus::Paid->value, 'refund_status' => 'none',
        'subtotal_cents' => 100, 'shipping_cents' => 0, 'tax_cents' => 0,
        'shipping_tax_cents' => 0, 'total_cents' => 100,
        'tax_country_code' => 'GB', 'shipping_address_json' => [],
        'shipping_method_label' => 'Std', 'placed_at' => now(),
    ]);

    $resolver = app(ShopEntityResolver::class);
    expect($resolver->product($site, ['product_id' => $ownProduct->id]))->toBeInstanceOf(Product::class)
        ->and($resolver->product($site, ['slug' => 'own-slug']))->toBeInstanceOf(Product::class)
        ->and($resolver->media($site, $ownMedia->id))->toBeInstanceOf(SiteMedia::class)
        ->and($resolver->heroVersion($site, $ownVersion->id))->toBeInstanceOf(ShopHeroVersion::class)
        ->and($resolver->category($site, 'own-cat'))->toBeInstanceOf(Category::class)
        ->and($resolver->order($site, 'OWN-1'))->toBeInstanceOf(Order::class)
        ->and($resolver->variant($ownProduct, 'OWN-SKU'))->toBeInstanceOf(ProductVariant::class)
        ->and($resolver->product($site, ['product_id' => $ownProduct->id])->id)->toBe($ownProduct->id);

    $foreign = [
        fn () => $resolver->product($site, ['product_id' => $foreignProduct->id]),
        fn () => $resolver->product($site, ['slug' => 'foreign-slug']),
        fn () => $resolver->media($site, $foreignMedia->id),
        fn () => $resolver->heroVersion($site, $foreignVersion->id),
        fn () => $resolver->category($site, 'foreign-cat'),
        fn () => $resolver->order($site, 'FOR-1'),
        fn () => $resolver->variant($ownProduct, 'FOREIGN-SKU'),
        fn () => $resolver->variant($ownProduct, 'OWN-SKU-FOREIGN-ONLY'),
    ];
    foreach ($foreign as $call) {
        try {
            $call();
            $this->fail('Expected OperationFailed not_found');
        } catch (OperationFailed $exception) {
            expect($exception->result->error['code'])->toBe('not_found')
                ->and($exception->result->ok)->toBeFalse();
        }
    }

    expect($ownOrder->number)->toBe('OWN-1')
        ->and($actor->id)->toBeInt();
});

it('refuses published and archived products with published_product_immutable under the row lock', function () {
    [$actor, $site] = commerceActorSite();
    $published = Product::factory()->for($site)->published()->create(['name' => 'Live']);
    $archived = Product::factory()->for($site)->create([
        'name' => 'Gone',
        'status' => ProductStatus::Archived,
    ]);
    $op = commerceWriteOp();
    commerceBindOperation($op);

    $locked = [];
    DB::listen(function (QueryExecuted $query) use (&$locked): void {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'for update')) {
            $locked[] = $sql;
        }
    });

    $state = app(EditorStateFactory::class)->for($site, null);
    expect(fn () => DraftOnlyGuard::assert($published, $state))
        ->toThrow(OperationFailed::class);
    try {
        DraftOnlyGuard::assert($archived, $state);
        $this->fail('archived should be immutable');
    } catch (OperationFailed $exception) {
        expect($exception->result->error['code'])->toBe('published_product_immutable');
    }

    $publishedResult = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Ui),
        'commerce_write',
        ['catalogue_revision' => 0, 'product_id' => $published->id, 'product_revision' => 0, 'name' => 'mutated'],
    );
    $archivedResult = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Ui),
        'commerce_write',
        ['catalogue_revision' => 0, 'product_id' => $archived->id, 'product_revision' => 0, 'name' => 'mutated'],
    );

    expect($publishedResult->error['code'])->toBe('published_product_immutable')
        ->and($archivedResult->error['code'])->toBe('published_product_immutable')
        ->and($published->fresh()->name)->toBe('Live')
        ->and($archived->fresh()->name)->toBe('Gone')
        ->and($locked)->not->toBeEmpty()
        ->and(implode("\n", $locked))->toContain('shop_products');
});

it('rolls a partial write back when the refusal is returned rather than thrown from handleShopWrite', function () {
    [$actor, $site, $product] = commerceActorSiteWithProduct();
    $op = commerceWriteOp(afterWrite: 'return_fail');
    $ctx = new EditorContext($actor, $site, ActorChannel::Ui);
    $input = [
        'catalogue_revision' => 0,
        'product_id' => $product->id,
        'product_revision' => 0,
        'name' => 'partial',
    ];

    $caught = null;
    try {
        DB::transaction(function () use ($op, $ctx, $input): void {
            $op->handle($ctx, $input);
        });
    } catch (OperationFailed $exception) {
        $caught = $exception;
    }

    expect($caught)->toBeInstanceOf(OperationFailed::class)
        ->and($caught->result->error['code'])->toBe('published_product_immutable')
        ->and($product->fresh()->name)->toBe('Original Name');
});

it('locks shop_variant_stock after shop_products and shop_drafts', function () {
    [$actor, $site, $product] = commerceActorSiteWithProduct();
    ProductVariant::factory()->for($product)->create(['sku' => 'LOCK-1']);

    $locked = [];
    DB::listen(function (QueryExecuted $query) use (&$locked): void {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'for update')) {
            $locked[] = $sql;
        }
    });

    DB::transaction(function () use ($site, $product, $actor): void {
        ShopWriteOperation::lockSubject($site, [$product->id], $actor->id);
    });

    $productLocks = array_values(array_filter($locked, fn (string $sql): bool => str_contains($sql, 'shop_products')));
    $draftLocks = array_values(array_filter($locked, fn (string $sql): bool => str_contains($sql, 'shop_drafts')));
    $stockLocks = array_values(array_filter($locked, fn (string $sql): bool => str_contains($sql, 'shop_variant_stock')));

    expect($productLocks)->not->toBeEmpty()
        ->and($draftLocks)->not->toBeEmpty()
        ->and($stockLocks)->not->toBeEmpty()
        ->and(array_search($productLocks[0], $locked, true))->toBeLessThan(array_search($draftLocks[0], $locked, true))
        ->and(array_search($draftLocks[0], $locked, true))->toBeLessThan(array_search($stockLocks[0], $locked, true));
});

it('locks subject products ascending by id then shop_drafts so an agent write and human publish do not deadlock', function () {
    [$actor, $site] = commerceActorSite();
    $second = Product::factory()->for($site)->create(['name' => 'B']);
    $first = Product::factory()->for($site)->create(['name' => 'A']);
    expect($first->id)->toBeGreaterThan($second->id);

    $locked = [];
    DB::listen(function (QueryExecuted $query) use (&$locked): void {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'for update')) {
            $locked[] = $sql;
        }
    });

    $op = commerceWriteOp(subjectIds: [$first->id, $second->id]);
    commerceBindOperation($op);
    $ok = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Ui),
        'commerce_write',
        [
            'catalogue_revision' => 0,
            'product_id' => $first->id,
            'product_ids' => [$first->id, $second->id],
            'product_revision' => 0,
            'name' => 'locked',
        ],
    );
    expect($ok->ok)->toBeTrue();

    $productLocks = array_values(array_filter($locked, fn (string $sql): bool => str_contains($sql, 'shop_products')));
    $draftLocks = array_values(array_filter($locked, fn (string $sql): bool => str_contains($sql, 'shop_drafts')));
    expect($productLocks)->not->toBeEmpty()
        ->and($draftLocks)->not->toBeEmpty()
        ->and(array_search($productLocks[0], $locked, true))->toBeLessThan(array_search($draftLocks[0], $locked, true))
        ->and($productLocks[0])->toContain('order by');

    if (! function_exists('pcntl_fork')) {
        $this->fail('The agent-write vs human-publish deadlock test requires pcntl.');
    }

    $product = Product::factory()->for($site)->create(['name' => 'Overlap']);
    DB::table('shop_drafts')->updateOrInsert(
        ['site_id' => $site->id],
        ['catalogue_revision' => 0, 'updated_at' => now(), 'created_at' => now()],
    );
    $productId = $product->id;
    $siteId = $site->id;
    $actorId = $actor->id;
    $revision = (int) $product->revision;

    DB::commit();
    DB::disconnect();

    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($sockets)->not->toBeFalse();
    [$parentSocket, $childSocket] = $sockets;
    $childPid = pcntl_fork();
    if ($childPid === 0) {
        fclose($parentSocket);
        try {
            stream_set_timeout($childSocket, 5);
            if (trim((string) fgets($childSocket)) !== 'go') {
                throw new RuntimeException('Human publish was not released.');
            }
            config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
            DB::setDefaultConnection('pgsql_b');
            DB::purge('pgsql_b');
            fwrite($childSocket, "started\n");
            Livewire::actingAs(User::on('pgsql_b')->findOrFail($actorId))
                ->test('shop.product-editor', ['siteId' => $siteId, 'productId' => $productId])
                ->call('publish');
            fwrite($childSocket, "ok\n");
        } catch (Throwable $exception) {
            @fwrite($childSocket, 'error:'.$exception->getMessage()."\n");
        }
        fclose($childSocket);
        exit(0);
    }
    fclose($childSocket);

    $agentOp = commerceWriteOp();
    $agentUser = User::query()->findOrFail($actorId);
    $agentSite = Site::query()->findOrFail($siteId);
    commerceBindOperation($agentOp);
    fwrite($parentSocket, "go\n");
    stream_set_timeout($parentSocket, 5);
    expect(trim((string) fgets($parentSocket)))->toBe('started');

    $startedAt = microtime(true);
    $agentCode = 'ok';
    try {
        $agentResult = $agentOp->handle(
            new EditorContext($agentUser, $agentSite, ActorChannel::Ui),
            [
                'catalogue_revision' => 0,
                'product_id' => $productId,
                'product_revision' => $revision,
                'name' => 'agent-overlap',
            ],
        );
        $agentCode = $agentResult->ok ? 'ok' : ($agentResult->error['code'] ?? 'internal');
    } catch (OperationFailed $exception) {
        $agentCode = $exception->result->error['code'] ?? 'internal';
    }
    $deadline = microtime(true) + 5;
    do {
        $waited = pcntl_waitpid($childPid, $status, WNOHANG);
        if ($waited === $childPid) {
            break;
        }
        usleep(10_000);
    } while (microtime(true) < $deadline);

    if ($waited !== $childPid) {
        posix_kill($childPid, SIGKILL);
        pcntl_waitpid($childPid, $status);
        $this->fail('The overlapping human publish did not finish within 5 seconds.');
    }

    stream_set_blocking($parentSocket, false);
    $childOutput = (string) stream_get_contents($parentSocket);
    fclose($parentSocket);

    expect(microtime(true) - $startedAt)->toBeLessThan(5.0)
        ->and($childOutput)->toContain('ok')
        ->and($childOutput)->not->toContain('SQLSTATE[40P01')
        ->and(in_array($agentCode, ['ok', 'published_product_immutable', 'stale_revision'], true))->toBeTrue();
});

/**
 * @return object{column_name: string, data_type: string, is_nullable: string, column_default: ?string, character_maximum_length: ?int}
 */
function shopColumn(string $table, string $column): object
{
    $row = collect(shopColumns($table))->firstWhere('column_name', $column);
    expect($row)->not->toBeNull("missing column {$table}.{$column}");

    return $row;
}

/**
 * @return list<object{column_name: string, data_type: string, is_nullable: string, column_default: ?string, character_maximum_length: ?int}>
 */
function shopColumns(string $table): array
{
    return DB::select(
        'SELECT column_name, data_type, is_nullable, column_default, character_maximum_length
         FROM information_schema.columns
         WHERE table_schema = current_schema() AND table_name = ?',
        [$table],
    );
}

/**
 * @return array{0: Site, 1: array<string, GeneratedPage>}
 */
function commerceCorpusSite(): array
{
    $path = base_path('tests/fixtures/home-themes/demo-site-themes.json');
    $decoded = json_decode((string) file_get_contents($path), true);
    $palette = $decoded['51-eden'];

    $brief = [
        'mood' => 'refined-minimal',
        'display_font' => 'space-grotesk',
        'body_font' => 'inter',
        'heading_scale' => 'balanced',
        'spacing_density' => 'balanced',
        'corner_style' => 'soft',
        'palette' => [
            'primary' => $palette['primary_color'],
            'accent' => $palette['accent_color'],
            'tertiary' => $palette['tertiary_color'],
            'surface' => $palette['surface_color'],
            'surface_alt' => $palette['surface_alt_color'],
            'border' => $palette['border_color'],
            'text' => $palette['text_color'],
            'text_muted' => $palette['text_muted_color'],
        ],
    ];

    $site = Site::factory()->create([
        'business_name' => 'Apex Developments',
        'business_type' => 'Builder',
        'location' => 'Cheshire',
        'theme' => 'trades-bold',
        'home_layout' => 'classic',
        'services_layout' => 'classic',
        'about_layout' => 'classic',
        'design_brief' => $brief,
    ]);

    BusinessProfile::factory()->for($site)->create([
        'profile_data' => [
            'archetype' => 'local_service',
            'lead_form_policy' => 'all',
            'contact' => ['phones' => ['0161 555 0199'], 'emails' => ['info@apex.test']],
            'geo' => ['service_area' => 'Cheshire'],
        ],
    ]);

    $home = commerceCorpusPage($site, 'home', [
        ['type' => 'hero', 'title' => 'Welcome to Apex'],
        ['type' => 'services', 'title' => 'Our Core Services', 'items' => [
            ['title' => 'Home Extensions', 'body' => 'High quality residential extensions.'],
            ['title' => 'Renovations', 'body' => 'Complete home transformations.'],
        ]],
        ['type' => 'trust', 'title' => 'Why Choose Us', 'items' => [
            ['title' => 'Guaranteed Work', 'body' => '10-year insurance backed guarantee.'],
            ['title' => 'Certified Master Builders', 'body' => 'Accredited and vetted.'],
        ]],
        ['type' => 'process', 'title' => 'Our 4-Step Process', 'items' => [
            ['title' => 'Initial Consultation', 'body' => 'We meet on site to discuss your vision.'],
            ['title' => 'Design & Planning', 'body' => 'Architectural plans and structural drawings.'],
        ]],
        ['type' => 'lead_form', 'title' => 'Request a Quote'],
        ['type' => 'cta', 'title' => 'Ready to build your dream home?'],
    ]);

    $service = commerceCorpusPage($site, 'extensions', [
        ['type' => 'intro', 'title' => 'Bespoke Home Extensions', 'body' => 'We design and build premium extensions.'],
        ['type' => 'features', 'title' => "What's Included", 'items' => [
            ['icon' => 'hammer', 'title' => 'Full Project Management', 'body' => 'From planning through handover.'],
            ['icon' => 'check', 'title' => 'Building Regs Compliance', 'body' => 'Signed off by local authorities.'],
        ]],
        ['type' => 'cta', 'title' => 'Get in touch today'],
    ]);

    $about = commerceCorpusPage($site, 'about', [
        ['type' => 'story', 'title' => 'Building Excellence Since 2008', 'body' => 'Founded with a dedication to craft and integrity.'],
        ['type' => 'values', 'title' => 'Our Values', 'items' => [
            ['title' => 'Craftsmanship', 'body' => 'We never cut corners.'],
            ['title' => 'Transparency', 'body' => 'Clear, fixed itemised pricing.'],
        ]],
        ['type' => 'cta', 'title' => 'Work with us'],
    ]);

    $projects = commerceCorpusPage($site, 'projects', [
        ['type' => 'project_gallery', 'title' => 'Featured Projects', 'items' => [
            ['title' => 'Modern Kitchen Extension', 'body' => 'Open-plan living space.'],
        ]],
        ['type' => 'cta', 'title' => 'Start your project'],
    ]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => [
                'key' => 'trades-bold',
                'primary_override' => $palette['primary_color'],
                'accent_override' => $palette['accent_color'],
                'tertiary_override' => $palette['tertiary_color'],
                'surface_override' => $palette['surface_color'],
                'surface_alt_override' => $palette['surface_alt_color'],
                'border_override' => $palette['border_color'],
                'text_override' => $palette['text_color'],
                'text_muted_override' => $palette['text_muted_color'],
            ],
            'homepage_page_id' => $home->id,
        ],
        'page_revisions' => [
            ['page_id' => $home->id, 'revision_id' => $home->published_revision_id],
            ['page_id' => $service->id, 'revision_id' => $service->published_revision_id],
            ['page_id' => $about->id, 'revision_id' => $about->published_revision_id],
            ['page_id' => $projects->id, 'revision_id' => $projects->published_revision_id],
        ],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    return [$site, [
        'home' => $home,
        'service' => $service,
        'about' => $about,
        'projects' => $projects,
    ]];
}

/**
 * @param  list<array<string, mixed>>  $sections
 */
function commerceCorpusPage(Site $site, string $pageType, array $sections): GeneratedPage
{
    $kind = match ($pageType) {
        'home', 'about', 'projects' => PageKind::Core,
        default => PageKind::Service,
    };

    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => $pageType,
        'kind' => $kind,
        'nav_label' => ucfirst($pageType),
    ]);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => $sections],
    ]);
    $page->update(['published_revision_id' => $rev->id]);

    return $page->fresh();
}

function commerceNormalise(string $html): string
{
    $html = (string) preg_replace('/csrfToken:\s*"[^"]*"/', 'csrfToken: "__CSRF__"', $html);
    $html = (string) preg_replace('/name="_token" value="[^"]*"/', 'name="_token" value="__CSRF__"', $html);
    $html = (string) preg_replace('/content="[^"]*"([^>]*name="csrf-token")/', 'content="__CSRF__"$1', $html);
    $html = (string) preg_replace('/(id|for)="([a-z]+-)\d+(-[a-z0-9_-]*)"/', '$1="$2PAGEID$3"', $html);
    $html = (string) preg_replace('/data-editable="page\.\d+\./', 'data-editable="page.PAGEID.', $html);
    $html = (string) preg_replace('/\/build(?:-[a-z0-9]+)?\/assets\/[A-Za-z0-9._-]+\.(css|js)/', '/build/assets/HASH.$1', $html);

    return $html;
}

/**
 * @return array{0: User, 1: Site}
 */
function commerceActorSite(): array
{
    $actor = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $actor->id]);

    return [$actor, $site];
}

/**
 * @return array{0: User, 1: Site, 2: Product}
 */
function commerceActorSiteWithProduct(): array
{
    [$actor, $site] = commerceActorSite();
    $product = Product::factory()->for($site)->create(['name' => 'Original Name']);

    return [$actor, $site, $product];
}

/**
 * @param  list<int>  $subjectIds
 */
function commerceWriteOp(array $subjectIds = [], string $afterWrite = 'ok'): ShopWriteOperation
{
    return new class($subjectIds, $afterWrite) extends ShopWriteOperation
    {
        /**
         * @param  list<int>  $fixedIds
         */
        public function __construct(
            private readonly array $fixedIds,
            private readonly string $afterWrite,
        ) {}

        public function name(): string
        {
            return 'commerce_write';
        }

        public function sideEffects(): string
        {
            return 'Test commerce write.';
        }

        public function inputSchema(): array
        {
            return [
                'type' => 'object',
                'properties' => [
                    'catalogue_revision' => ['type' => 'integer'],
                    'product_id' => ['type' => 'integer'],
                    'product_revision' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                ],
            ];
        }

        public function subjectProductIds(array $input): array
        {
            if ($this->fixedIds !== []) {
                return $this->fixedIds;
            }

            if (isset($input['product_ids']) && is_array($input['product_ids'])) {
                return array_map(intval(...), $input['product_ids']);
            }

            return isset($input['product_id']) ? [(int) $input['product_id']] : [];
        }

        protected function handleShopWrite(EditorContext $ctx, array $input, ShopWriteLockset $locks): OperationResult
        {
            if (isset($input['name']) && $locks->products !== []) {
                $locks->products[0]->update(['name' => $input['name']]);
            }

            $state = app(EditorStateFactory::class)->for($ctx->site, null);

            if ($this->afterWrite === 'return_fail') {
                return OperationResult::fail(
                    'published_product_immutable',
                    'refused after partial write',
                    $state,
                );
            }

            return OperationResult::ok(['wrote' => true], $state);
        }
    };
}

function commerceBindOperation(ShopWriteOperation $operation): void
{
    config([
        'editor.agent_tools.enabled' => true,
        'editor.operations.enabled' => true,
        'editor.exposure.sets.sandbox' => array_merge(
            (array) config('editor.exposure.sets.sandbox'),
            ['commerce_write'],
        ),
    ]);
    app()->instance(OperationRegistry::class, new OperationRegistry([$operation]));
}
