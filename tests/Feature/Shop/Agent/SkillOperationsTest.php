<?php

use App\Enums\Shop\ProductStatus;
use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Shop\ShopDraft;
use App\Models\Site;
use App\Models\User;
use App\Services\Site\Editor\OperationRegistry;
use App\Services\Site\Editor\Operations\SkillAddProductWithImageryOperation;
use App\Services\Site\Editor\Operations\SkillExportCatalogueOperation;
use App\Services\Site\Editor\Operations\SkillImportCatalogueFromSourceOperation;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Tool;
use Tests\Support\CommerceReads;

beforeEach(function () {
    CommerceReads::enableFlags();
});

/**
 * @return list<string>
 */
function skillOperationNames(): array
{
    return [
        'skill_import_catalogue_from_source',
        'skill_add_product_with_imagery',
        'skill_export_catalogue',
    ];
}

/**
 * @return array{0: User, 1: Site, 2: Category}
 */
function skillShopSite(): array
{
    [$actor, $site, $category] = CommerceReads::shopSite();
    $site->update([
        'business_name' => 'Acme Candles',
        'shop_currency' => 'GBP',
    ]);

    return [$actor, $site->fresh(), $category];
}

function skillOperationClass(string $name): string
{
    return match ($name) {
        'skill_import_catalogue_from_source' => SkillImportCatalogueFromSourceOperation::class,
        'skill_add_product_with_imagery' => SkillAddProductWithImageryOperation::class,
        'skill_export_catalogue' => SkillExportCatalogueOperation::class,
    };
}

it('is a shop-addressed zero-argument read with staff and client roles', function (string $name) {
    $operation = app(skillOperationClass($name));

    expect($operation->name())->toBe($name)
        ->and($operation->address())->toBe('shop')
        ->and($operation->readOnly())->toBeTrue()
        ->and($operation->wrapInAdminChange())->toBeFalse()
        ->and($operation->allowedRoles())->toEqualCanonicalizing(['staff', 'client'])
        ->and($operation->inputSchema())->toMatchArray([
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [],
        ])
        ->and(app(OperationRegistry::class)->has($name))->toBeTrue();
})->with(skillOperationNames());

it('has a thin Front 3 MCP tool wrapper for each skill', function (string $name) {
    $tool = 'App\\Mcp\\Tools\\Editor\\'.Str::studly($name).'Tool';

    expect(class_exists($tool))->toBeTrue()
        ->and(is_subclass_of($tool, Tool::class))->toBeTrue();
})->with(skillOperationNames());

it('returns current_state and protocol without bumping catalogue revision or writing rows', function (string $name) {
    [$actor, $site] = skillShopSite();
    $productsBefore = Product::query()->where('site_id', $site->id)->count();
    $categoriesBefore = Category::query()->where('site_id', $site->id)->count();
    $revisionBefore = (int) (ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision') ?? 0);

    $result = CommerceReads::run($actor, $site, $name);

    expect($result->ok)->toBeTrue()
        ->and($result->data['current_state'])->toBeString()->not->toBeEmpty()
        ->and($result->data['protocol'])->toBeString()->not->toBeEmpty()
        ->and($result->data['current_state'])->toContain('Acme Candles')
        ->and($result->data['current_state'])->toContain('GBP')
        ->and($result->data['current_state'])->toContain('candles')
        ->and($result->data['current_state'])->toContain('has_logo')
        ->and(Product::query()->where('site_id', $site->id)->count())->toBe($productsBefore)
        ->and(Category::query()->where('site_id', $site->id)->count())->toBe($categoriesBefore)
        ->and((int) (ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision') ?? 0))->toBe($revisionBefore);
})->with(skillOperationNames());

it('clamps merchant-controlled text in the current_state prefix', function (string $name) {
    [$actor, $site, $category] = skillShopSite();
    // Multi-line, over-long site name with Unicode separators and quote-outs:
    // must land single-line, truncated, quote-safe.
    $site->update(['business_name' => "Acme\u{2028}\"Candles\"\u{2029}".str_repeat('and wax things ', 13)]);
    // 25 categories: the slug list must cap at 20 with a "+N more" tail.
    foreach (range(1, 24) as $i) {
        Category::factory()->for($site)->create(['slug' => sprintf('extra-%02d', $i), 'name' => "Extra {$i}"]);
    }

    $result = CommerceReads::run($actor, $site, $name);

    expect($result->ok)->toBeTrue()
        ->and($result->data['current_state'])->not->toContain("\n")
        ->and($result->data['current_state'])->not->toContain("\u{2028}")
        ->and($result->data['current_state'])->not->toContain("\u{2029}")
        ->and($result->data['current_state'])->toContain("'Candles'")
        ->and(substr_count($result->data['current_state'], '"'))->toBe(2)
        ->and($result->data['current_state'])->toContain('…')
        ->and($result->data['current_state'])->toContain('Categories (25)')
        ->and($result->data['current_state'])->toContain('+5 more')
        ->and($result->data['current_state'])->not->toContain('extra-21');

    // A name that sanitizes away entirely falls back to slug/domain, never "".
    $site->update(['business_name' => "\u{2028}\u{2029}\u{200B}", 'slug' => 'acme-candles']);
    $fallback = CommerceReads::run($actor, $site, $name);
    expect($fallback->ok)->toBeTrue()
        ->and($fallback->data['current_state'])->not->toContain('Site: ""')
        ->and($fallback->data['current_state'])->toContain('Site: "acme-candles"');
})->with(skillOperationNames());

it('keeps the current_state prefix free of the unproducible warnings sentence', function (string $name) {
    [$actor, $site, $category] = skillShopSite();
    $draft = Product::factory()->for($site)->create([
        'name' => 'Ask-price Tealight',
        'slug' => 'ask-price-tealight',
        'status' => ProductStatus::Draft,
        'facts' => ['warnings' => ['price missing — merchant review required']],
    ]);
    $draft->categories()->attach($category->id, ['is_primary' => true]);

    $result = CommerceReads::run($actor, $site, $name);

    // facts.warnings is not a contract the write path produces;
    // the prefix must not advertise it even when seeded.
    expect($result->ok)->toBeTrue()
        ->and($result->data['current_state'])->toContain('(1 drafts)')
        ->and($result->data['current_state'])->not->toContain('warnings');
})->with(skillOperationNames());

it('names the import protocol tools verbatim and mentions publish only as something not to do', function () {
    [$actor, $site] = skillShopSite();

    $result = CommerceReads::run($actor, $site, 'skill_import_catalogue_from_source');
    $protocol = $result->data['protocol'];

    expect($result->ok)->toBeTrue()
        ->and($protocol)->toContain('describe_import_products')
        ->and($protocol)->toContain('manage_category')
        ->and($protocol)->toContain('dry_run')
        ->and($protocol)->toContain('idempotency_key')
        ->and($protocol)->toContain('get_site_context')
        ->and($protocol)->toContain('get_brand_system')
        ->and($protocol)->toContain('list_products')
        ->and($protocol)->toContain('import_products')
        ->and($protocol)->toContain('expected_revision');

    preg_match_all('/publish(?:ing|ed|es)?/i', $protocol, $matches);
    expect($matches[0])->not->toBeEmpty();

    foreach ($matches[0] as $match) {
        $offset = stripos($protocol, $match);
        $window = strtolower(substr($protocol, max(0, $offset - 80), 160));
        expect(
            str_contains($window, 'do not')
            || str_contains($window, 'not attempt')
            || str_contains($window, 'never')
            || str_contains($window, 'no publish')
            || str_contains($window, 'rejects')
            || str_contains($window, 'merchant')
        )->toBeTrue();
    }
});

it('names the add-with-imagery protocol tools including the single upload path', function () {
    [$actor, $site] = skillShopSite();

    $protocol = CommerceReads::run($actor, $site, 'skill_add_product_with_imagery')->data['protocol'];

    expect($protocol)->toContain('get_site_context')
        ->and($protocol)->toContain('list_products')
        ->and($protocol)->toContain('draft_product')
        ->and($protocol)->toContain('upload_image')
        ->and($protocol)->toContain('media_id')
        ->and($protocol)->toContain('set_product_image')
        ->and($protocol)->toContain('update_draft_product')
        ->and($protocol)->toContain('product_revision')
        ->and($protocol)->toContain('get_product');
});

it('names the export protocol tools including hash verification', function () {
    [$actor, $site] = skillShopSite();

    $protocol = CommerceReads::run($actor, $site, 'skill_export_catalogue')->data['protocol'];

    expect($protocol)->toContain('get_site_context')
        ->and($protocol)->toContain('get_brand_system')
        ->and($protocol)->toContain('get_logo_assets')
        ->and($protocol)->toContain('export_products')
        ->and($protocol)->toContain('sha256')
        ->and($protocol)->toContain('requires_current_session');
});
