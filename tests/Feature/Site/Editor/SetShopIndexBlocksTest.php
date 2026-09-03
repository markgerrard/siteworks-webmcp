<?php

use App\Services\Shop\ShopIndexBlockSettings;
use App\Services\Site\CompositionService;
use App\Services\Site\Editor\OperationRegistry;
use App\Models\Site\SiteDraft;
use Livewire\Livewire;
use Tests\Support\EditorSeeds;

beforeEach(function () {
    config(['editor.operations.enabled' => true, 'editor.agent_tools.enabled' => true]);
});

function shopIndexBlocksRevision(\App\Models\Site $site): int
{
    app(CompositionService::class)->ensureDraftRow($site, $site->created_by_user_id);

    return (int) SiteDraft::query()->where('site_id', $site->id)->value('admin_revision');
}

it('advertises set_shop_index_blocks as a site-addressed write', function () {
    $operation = OperationRegistry::discover()->get('set_shop_index_blocks');

    expect($operation->address())->toBe('site')
        ->and($operation->readOnly())->toBeFalse()
        ->and($operation->inputSchema()['required'] ?? [])->toContain('blocks')
        ->and($operation->inputSchema()['required'] ?? [])->toContain('blocks_revision')
        ->and($operation->inputSchema()['properties']['blocks_revision']['description'] ?? null)
        ->toBe("Current revision token. Provoke a stale_revision error and retry with its error payload's blocks_revision value.")
        ->and($operation->inputSchema()['required'] ?? [])->toContain('composition_revision');
});

it('writes shop_index_blocks and busts the public page cache', function () {
    config(['site.public_cache_enabled' => true]);
    [$user, $site] = EditorSeeds::homeWithHero();
    $revision = shopIndexBlocksRevision($site);
    $counterKey = "site:{$site->id}:pubcache_counter";
    $before = (int) cache()->get($counterKey, 0);

    $result = EditorSeeds::run($user, $site, 'set_shop_index_blocks', [
        'blocks' => [
            ['source' => 'tag:gift', 'limit' => 8, 'layout' => 'carousel', 'heading' => 'Gift picks'],
        ],
        'blocks_revision' => ShopIndexBlockSettings::revision($site),
        'composition_revision' => $revision,
    ]);

    expect($result->ok)->toBeTrue()
        ->and($site->fresh()->shop_index_blocks[0])->toMatchArray([
            'source' => 'tag:gift',
            'limit' => 8,
            'layout' => 'carousel',
            'heading' => 'Gift picks',
        ])
        ->and((int) cache()->get($counterKey, 0))->toBeGreaterThan($before);
});

it('rejects an invalid shop_index_blocks payload', function () {
    [$user, $site] = EditorSeeds::homeWithHero();
    $revision = shopIndexBlocksRevision($site);

    $result = EditorSeeds::run($user, $site, 'set_shop_index_blocks', [
        'blocks' => [
            ['source' => 'nope', 'limit' => 4, 'layout' => 'grid', 'heading' => 'Bad'],
        ],
        'blocks_revision' => ShopIndexBlockSettings::revision($site),
        'composition_revision' => $revision,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($site->fresh()->shop_index_blocks ?? [])->toBeEmpty();
});

it('advertises and writes trust strip shop index knobs', function () {
    [$user, $site] = EditorSeeds::homeWithHero();
    $operation = OperationRegistry::discover()->get('set_shop_index_blocks');
    $properties = $operation->inputSchema()['properties']['blocks']['items']['properties'];

    $result = EditorSeeds::run($user, $site, 'set_shop_index_blocks', [
        'blocks' => [[
            'type' => 'trust_strip',
            'sources' => 'product',
            'layout' => 'carousel',
            'heading' => 'Recent feedback',
            'reviews_label' => 'ratings',
            'min_reviews' => 4,
            'external' => ['label' => 'Independent score', 'url' => 'https://example.test', 'rating' => 4.8, 'count' => 19],
        ]],
        'blocks_revision' => ShopIndexBlockSettings::revision($site),
        'composition_revision' => shopIndexBlocksRevision($site),
    ]);

    expect($properties)->toHaveKeys(['type', 'sources', 'reviews_label', 'min_reviews', 'external'])
        ->and($result->ok)->toBeTrue()
        ->and($site->fresh()->shop_index_blocks[0])->toMatchArray([
            'type' => 'trust_strip',
            'sources' => 'product',
            'layout' => 'carousel',
            'heading' => 'Recent feedback',
            'reviews_label' => 'ratings',
            'min_reviews' => 4,
        ]);
});

it('adds a trust strip through the merchant shop blocks panel', function () {
    [$user, $site] = EditorSeeds::homeWithHero();
    $this->actingAs($user);

    Livewire::test('shop.shop-index-blocks-settings', ['siteId' => $site->id])
        ->set('newType', 'trust_strip')
        ->set('newHeading', 'Customer stories')
        ->set('newTrustSources', 'both')
        ->set('newLayout', 'strip')
        ->set('newReviewsLabel', 'responses')
        ->set('newMinReviews', 5)
        ->set('newExternalLabel', 'Independent score')
        ->set('newExternalUrl', 'https://example.test/score')
        ->set('newExternalRating', '4.7')
        ->set('newExternalCount', '24')
        ->call('addBlock')
        ->assertHasNoErrors();

    expect($site->fresh()->shop_index_blocks[0])->toMatchArray([
        'type' => 'trust_strip',
        'sources' => 'both',
        'layout' => 'strip',
        'heading' => 'Customer stories',
        'reviews_label' => 'responses',
        'min_reviews' => 5,
        'external' => [
            'label' => 'Independent score',
            'url' => 'https://example.test/score',
            'rating' => 4.7,
            'count' => 24,
        ],
    ]);
});

it('refuses a stale agent write after a panel save and keeps the merchant row', function () {
    [$user, $site] = EditorSeeds::homeWithHero();
    $this->actingAs($user);
    $staleToken = ShopIndexBlockSettings::revision($site);

    Livewire::test('shop.shop-index-blocks-settings', ['siteId' => $site->id])
        ->set('newHeading', 'Merchant row')
        ->set('newSource', 'newest')
        ->call('addBlock')
        ->assertHasNoErrors();

    $stale = EditorSeeds::run($user, $site, 'set_shop_index_blocks', [
        'blocks' => [
            ['source' => 'newest', 'limit' => 4, 'layout' => 'grid', 'heading' => 'Agent row'],
        ],
        'blocks_revision' => $staleToken,
        'composition_revision' => shopIndexBlocksRevision($site),
    ]);

    expect($stale->ok)->toBeFalse()
        ->and($stale->error['code'])->toBe('stale_revision')
        ->and($stale->error['blocks_revision'])->toBe(ShopIndexBlockSettings::revision($site->fresh()))
        ->and($stale->error['blocks'][0]['heading'])->toBe('Merchant row')
        ->and(array_column($site->fresh()->shop_index_blocks, 'heading'))->toBe(['Merchant row']);

    $fresh = EditorSeeds::run($user, $site, 'set_shop_index_blocks', [
        'blocks' => [
            ['source' => 'newest', 'limit' => 4, 'layout' => 'grid', 'heading' => 'Agent row'],
        ],
        'blocks_revision' => $stale->error['blocks_revision'],
        'composition_revision' => shopIndexBlocksRevision($site),
    ]);

    expect($fresh->ok)->toBeTrue()
        ->and($fresh->data['blocks'][0]['heading'])->toBe('Agent row')
        ->and($fresh->data['blocks_revision'])->toBe(ShopIndexBlockSettings::revision($site->fresh()))
        ->and(array_column($site->fresh()->shop_index_blocks, 'heading'))->toBe(['Agent row']);
});

it('refuses a second agent write on the same blocks_revision token', function () {
    [$user, $site] = EditorSeeds::homeWithHero();
    $token = ShopIndexBlockSettings::revision($site);
    $revision = shopIndexBlocksRevision($site);
    $payload = fn (string $heading): array => [
        'blocks' => [
            ['source' => 'newest', 'limit' => 4, 'layout' => 'grid', 'heading' => $heading],
        ],
        'blocks_revision' => $token,
        'composition_revision' => $revision,
    ];

    $first = EditorSeeds::run($user, $site, 'set_shop_index_blocks', $payload('Agent A'));
    $second = EditorSeeds::run($user, $site, 'set_shop_index_blocks', $payload('Agent B'));

    expect($first->ok)->toBeTrue()
        ->and($second->ok)->toBeFalse()
        ->and($second->error['code'])->toBe('stale_revision')
        ->and($second->error['blocks'][0]['heading'])->toBe('Agent A')
        ->and(array_column($site->fresh()->shop_index_blocks, 'heading'))->toBe(['Agent A']);
});
