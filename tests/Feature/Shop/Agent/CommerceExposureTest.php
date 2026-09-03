<?php

use App\Enums\AgentRole;
use App\Models\Client;
use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use App\Models\Site\EditorOperationLog;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\OperationRegistry;
use App\Services\Site\Editor\ToolExposure;
use Database\Seeders\Shop\TaxClassSeeder;
use Tests\Support\CommerceReads;

beforeEach(function () {
    CommerceReads::enableFlags();
    $this->seed(TaxClassSeeder::class);
});

it('names the five commerce operations on the internal set and the commerce sandbox set with upload_image', function () {
    $sandbox = config('editor.exposure.sets.sandbox');
    $commerce = config('editor.exposure.sets.commerce');
    $internal = config('editor.exposure.sets.internal');

    expect($sandbox)->toBeArray()
        ->and($commerce)->toBe(CommerceReads::sandboxSet())
        ->and($internal)->not->toBe(['*'])
        ->and($internal)->toBeArray();

    foreach (CommerceReads::sandboxSet() as $operation) {
        expect($internal)->toContain($operation);
    }

    foreach (CommerceReads::operations() as $operation) {
        expect($sandbox)->toContain($operation);
    }

    expect($sandbox)->toContain('set_product_image')
        ->and($sandbox)->toContain('upload_image');
});

it('exposes set_product_image and upload_image together on a shop sandbox site', function () {
    [$actor, $site] = CommerceReads::shopSite();

    $exposed = app(ToolExposure::class)->setFor($site);

    expect($exposed)->toContain('set_product_image')
        ->and($exposed)->toContain('upload_image')
        ->and(app(ToolExposure::class)->exposes($site, 'set_product_image'))->toBeTrue()
        ->and(app(ToolExposure::class)->exposes($site, 'upload_image'))->toBeTrue();
});

it('lets a client-role actor run sandbox commerce operations when the client channel and portal flag are open', function () {
    config([
        'editor.agent_tools.roles' => ['staff', 'client'],
        'editor.agent_tools.client_portal_enabled' => true,
    ]);

    $client = Client::factory()->create();
    $actor = User::factory()->create([
        'client_id' => $client->id,
        'role' => null,
        'email_verified_at' => now(),
    ]);
    $site = Site::factory()->create(['client_id' => $client->id]);
    Category::factory()->for($site)->create(['slug' => 'candles', 'name' => 'Candles']);
    CommerceReads::giveShop($site);

    expect($actor->isClientUser())->toBeTrue();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'draft_product',
        CommerceReads::draftProductInput(),
    );

    expect($result->ok)->toBeTrue()
        ->and(Product::query()->where('site_id', $site->id)->count())->toBe(1)
        ->and(CommerceReads::auditCount($site, 'draft_product', 'ok'))->toBe(1);
});


it('registers zero commerce tools and returns not_found when the site has no shop_snapshot_current row', function () {
    $actor = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $actor->id]);
    Category::factory()->for($site)->create(['slug' => 'candles', 'name' => 'Candles']);

    expect(ShopSnapshotCurrent::query()->where('site_id', $site->id)->exists())->toBeFalse()
        ->and(config('editor.exposure.sets.sandbox'))->toContain('list_products');

    $exposed = app(ToolExposure::class)->setFor($site);
    foreach (CommerceReads::operations() as $operation) {
        expect($exposed)->not->toContain($operation)
            ->and(app(ToolExposure::class)->exposes($site, $operation))->toBeFalse();
    }

    foreach (CommerceReads::operations() as $operation) {
        $result = app(EditorOperations::class)->run(
            new EditorContext($actor, $site, ActorChannel::Webmcp),
            $operation,
            commerceExposureInput($operation),
        );

        expect($result->ok)->toBeFalse()
            ->and($result->error['code'])->toBe('not_found')
            ->and(ShopSnapshotCurrent::query()->where('site_id', $site->id)->exists())->toBeFalse()
            ->and(CommerceReads::auditCount($site, $operation, 'not_found'))->toBe(1);
    }
});

it('registers zero commerce tools and returns not_found when the site has no shop_categories row', function () {
    $actor = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $actor->id]);
    CommerceReads::giveShop($site);

    expect(Category::query()->where('site_id', $site->id)->exists())->toBeFalse();

    $exposed = app(ToolExposure::class)->setFor($site);
    foreach (CommerceReads::operations() as $operation) {
        expect($exposed)->not->toContain($operation);
    }

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'draft_product',
        CommerceReads::draftProductInput(),
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('not_found')
        ->and(ShopSnapshotCurrent::query()->where('site_id', $site->id)->exists())->toBeTrue()
        ->and(Product::query()->where('site_id', $site->id)->exists())->toBeFalse()
        ->and(CommerceReads::auditCount($site, 'draft_product', 'not_found'))->toBe(1);
});

it('refuses an internal-only operation by name from a sandbox site at EditorOperations::run with one audit row', function () {
    [$actor, $site] = CommerceReads::shopSite();

    expect(app(ToolExposure::class)->nameFor($site))->toBe('sandbox')
        ->and(app(ToolExposure::class)->exposes($site, 'manage_video'))->toBeFalse()
        ->and(app(OperationRegistry::class)->has('manage_video'))->toBeTrue();

    $beforeProducts = Product::query()->where('site_id', $site->id)->count();
    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'manage_video',
        ['action' => 'pause', 'composition_revision' => 0],
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('not_found')
        ->and($result->error['message'])->toBe('Unknown operation.')
        ->and(EditorOperationLog::query()->where('site_id', $site->id)->where('operation', 'manage_video')->count())->toBe(1)
        ->and(EditorOperationLog::query()->where('site_id', $site->id)->where('operation', 'manage_video')->where('result_code', 'not_found')->count())->toBe(1)
        ->and(Product::query()->where('site_id', $site->id)->count())->toBe($beforeProducts);
});

/**
 * @return array<string, mixed>
 */
function commerceExposureInput(string $operation): array
{
    return match ($operation) {
        'list_products' => ['limit' => 10],
        'get_product' => ['slug' => 'missing'],
        'draft_product' => CommerceReads::draftProductInput(),
        'update_draft_product' => [
            'slug' => 'missing',
            'catalogue_revision' => 0,
            'product_revision' => 0,
            'name' => 'Nope',
        ],
        'set_product_image' => [
            'slug' => 'missing',
            'catalogue_revision' => 0,
            'product_revision' => 0,
            'media_id' => 1,
        ],
        'manage_category' => [
            'action' => 'delete',
            'slug' => 'missing',
            'catalogue_revision' => 0,
        ],
        default => [],
    };
}
