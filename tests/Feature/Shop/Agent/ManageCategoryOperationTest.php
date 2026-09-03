<?php

use App\Models\Shop\Category;
use App\Services\Shop\CategoryTreeService;
use App\Services\Site\Editor\Operations\ManageCategoryOperation;
use Tests\Support\CommerceReads;

beforeEach(function () {
    CommerceReads::enableFlags();
});

it('upserts a root category and returns slug path depth and parent_slug', function () {
    [$actor, $site] = CommerceReads::shopSite();

    $result = CommerceReads::run($actor, $site, 'manage_category', [
        'catalogue_revision' => 0,
        'action' => 'upsert',
        'name' => 'Wedding Cakes',
    ]);

    expect($result->ok)->toBeTrue()
        ->and($result->data)->toMatchArray([
            'slug' => 'wedding-cakes',
            'path' => 'wedding-cakes',
            'depth' => 1,
            'parent_slug' => null,
        ]);
});

it('upserts under a parent_slug and move relocates the node', function () {
    [$actor, $site] = CommerceReads::shopSite();
    $service = app(CategoryTreeService::class);
    $cakes = $service->create($site, 'Cakes');
    $tarts = $service->create($site, 'Tarts');

    $created = CommerceReads::run($actor, $site, 'manage_category', [
        'catalogue_revision' => 0,
        'action' => 'upsert',
        'name' => 'Wedding Cakes',
        'parent_slug' => 'cakes',
    ]);

    expect($created->ok)->toBeTrue()
        ->and($created->data['path'])->toBe('cakes/wedding-cakes')
        ->and($created->data['depth'])->toBe(2)
        ->and($created->data['parent_slug'])->toBe('cakes');

    $moved = CommerceReads::run($actor, $site, 'manage_category', [
        'catalogue_revision' => (int) ($created->data['catalogue_revision'] ?? 0),
        'action' => 'move',
        'slug' => 'wedding-cakes',
        'parent_slug' => 'tarts',
    ]);

    expect($moved->ok)->toBeTrue()
        ->and($moved->data['path'])->toBe('tarts/wedding-cakes')
        ->and($moved->data['parent_slug'])->toBe('tarts');

    expect($cakes->id)->toBeInt()
        ->and($tarts->fresh()->slug)->toBe('tarts');
});

it('delete re-parents children and returns the deleted node', function () {
    [$actor, $site] = CommerceReads::shopSite();
    $service = app(CategoryTreeService::class);
    $cakes = $service->create($site, 'Cakes');
    $wedding = $service->create($site, 'Wedding Cakes', $cakes);
    $service->create($site, 'Tiered', $wedding);

    $deleted = CommerceReads::run($actor, $site, 'manage_category', [
        'catalogue_revision' => 0,
        'action' => 'delete',
        'slug' => 'wedding-cakes',
    ]);

    expect($deleted->ok)->toBeTrue()
        ->and($deleted->data['slug'])->toBe('wedding-cakes')
        ->and(Category::query()->where('slug', 'wedding-cakes')->exists())->toBeFalse()
        ->and(Category::query()->where('slug', 'tiered')->first()->parent_id)->toBe($cakes->id);
});

it('returns structured cycle depth slug_taken and not_found errors', function (string $action, array $input, string $code) {
    [$actor, $site] = CommerceReads::shopSite();
    $service = app(CategoryTreeService::class);
    $cakes = $service->create($site, 'Cakes');
    $wedding = $service->create($site, 'Wedding Cakes', $cakes);
    $service->create($site, 'Tiered', $wedding);

    $result = CommerceReads::run($actor, $site, 'manage_category', [
        'catalogue_revision' => 0,
        'action' => $action,
        ...$input,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe($code);
})->with([
    'cycle' => ['move', ['slug' => 'cakes', 'parent_slug' => 'tiered'], 'cycle'],
    'depth' => ['upsert', ['name' => 'Fondant', 'parent_slug' => 'tiered'], 'depth'],
    'slug_taken' => ['upsert', ['name' => 'Cakes'], 'slug_taken'],
    'not_found' => ['delete', ['slug' => 'missing'], 'not_found'],
]);

it('is a shop-addressed unwrapped staff-or-client write', function () {
    $operation = app(ManageCategoryOperation::class);

    expect($operation->name())->toBe('manage_category')
        ->and($operation->address())->toBe('shop')
        ->and($operation->readOnly())->toBeFalse()
        ->and($operation->wrapInAdminChange())->toBeFalse()
        ->and($operation->allowedRoles())->toEqualCanonicalizing(['staff', 'client'])
        ->and($operation->requiresApproval())->toBeFalse();
});

it('rejects an invalid visibility or sort value', function (string $field, string $value, string $allowed) {
    [$actor, $site] = CommerceReads::shopSite();

    $result = CommerceReads::run($actor, $site, 'manage_category', [
        'catalogue_revision' => 0,
        'action' => 'upsert',
        'name' => 'Cakes',
        $field => $value,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($result->error['fields'][$field])->toBe([$allowed])
        ->and(Category::query()->where('site_id', $site->id)->where('name', 'Cakes')->exists())->toBeFalse();
})->with([
    'visibility' => ['visibility', 'public', 'visible|hidden'],
    'sort' => ['sort', 'alpha', 'manual|name|newest|price_asc|price_desc'],
]);

it('accepts every allowed visibility value', function (string $visibility) {
    [$actor, $site] = CommerceReads::shopSite();

    $result = CommerceReads::run($actor, $site, 'manage_category', [
        'catalogue_revision' => 0,
        'action' => 'upsert',
        'name' => 'Cakes '.$visibility,
        'visibility' => $visibility,
    ]);

    expect($result->ok)->toBeTrue()
        ->and(Category::query()->where('site_id', $site->id)->where('slug', $result->data['slug'])->value('visibility'))->toBe($visibility);
})->with(['visible', 'hidden']);

it('accepts every allowed sort value', function (string $sort) {
    [$actor, $site] = CommerceReads::shopSite();

    $result = CommerceReads::run($actor, $site, 'manage_category', [
        'catalogue_revision' => 0,
        'action' => 'upsert',
        'name' => 'Cakes '.$sort,
        'sort' => $sort,
    ]);

    expect($result->ok)->toBeTrue()
        ->and(Category::query()->where('site_id', $site->id)->where('slug', $result->data['slug'])->value('sort'))->toBe($sort);
})->with(['manual', 'name', 'newest', 'price_asc', 'price_desc']);

