<?php

use App\Models\Client;
use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Shop\ShopDraft;
use App\Models\Site;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use Tests\Support\CommerceReads;

beforeEach(function () {
    CommerceReads::enableFlags();
});

/**
 * @return list<string>
 */
function skillGatingNames(): array
{
    return [
        'skill_import_catalogue_from_source',
        'skill_add_product_with_imagery',
        'skill_export_catalogue',
    ];
}

/**
 * @return array{0: User, 1: Site}
 */
function skillGatingClientShop(): array
{
    $tenant = Client::factory()->create();
    $actor = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
        'email_verified_at' => now(),
    ]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);
    Category::factory()->for($site)->create(['slug' => 'candles', 'name' => 'Candles']);
    CommerceReads::giveShop($site);

    return [$actor, $site];
}

function openSkillGatingClientChannel(): void
{
    config([
        'editor.agent_tools.roles' => ['staff', 'client'],
        'editor.agent_tools.client_portal_enabled' => true,
    ]);
}

it('lets a same-tenant client run each skill when the portal channel is open', function (string $name) {
    openSkillGatingClientChannel();
    [$actor, $site] = skillGatingClientShop();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        $name,
        [],
    );

    expect($result->ok)->toBeTrue()
        ->and($result->data['current_state'])->toBeString()->not->toBeEmpty()
        ->and($result->data['protocol'])->toBeString()->not->toBeEmpty()
        ->and(CommerceReads::auditCount($site, $name, 'ok'))->toBe(1);
})->with(skillGatingNames());

it('forbids a client of a different tenant from each skill at Layer 0', function (string $name) {
    openSkillGatingClientChannel();
    [, $site] = skillGatingClientShop();
    [$stranger] = skillGatingClientShop();
    $productsBefore = Product::query()->where('site_id', $site->id)->count();

    $result = app(EditorOperations::class)->run(
        new EditorContext($stranger, $site, ActorChannel::Webmcp),
        $name,
        [],
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('forbidden')
        ->and($result->error['message'])->toBe('Not allowed on this site.')
        ->and(Product::query()->where('site_id', $site->id)->count())->toBe($productsBefore)
        ->and(CommerceReads::auditCount($site, $name, 'forbidden'))->toBe(1);
})->with(skillGatingNames());

it('refuses a client skill when the portal flag is off even with the role allowlist open', function (string $name) {
    config(['editor.agent_tools.roles' => ['staff', 'client']]);
    [$actor, $site] = skillGatingClientShop();
    $revisionBefore = (int) (ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision') ?? 0);

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        $name,
        [],
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('forbidden')
        ->and($result->error['message'])->toBe('Agent tools are disabled for this actor.')
        ->and((int) (ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision') ?? 0))->toBe($revisionBefore)
        ->and(CommerceReads::auditCount($site, $name, 'forbidden'))->toBe(1);
})->with(skillGatingNames());
