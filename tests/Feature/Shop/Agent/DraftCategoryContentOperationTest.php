<?php

use App\Models\Shop\Category;
use Tests\Support\CommerceReads;

beforeEach(function () {
    CommerceReads::enableFlags();
});

test('draft category content updates the requested category and records success', function () {
    [$actor, $site, $category] = CommerceReads::shopSite();

    $result = CommerceReads::run($actor, $site, 'draft_category_content', [
        'catalogue_revision' => 0,
        'slug' => $category->slug,
        'description_long' => '<p>Draft copy.</p>',
        'faqs' => [['q' => 'Question?', 'a' => 'Answer.']],
        'meta_title' => 'Draft title',
        'meta_description' => 'Draft description',
    ]);

    expect($result->ok)->toBeTrue()
        ->and($result->data)->toMatchArray([
            'slug' => $category->slug,
            'catalogue_revision' => 1,
        ])
        ->and($category->fresh()->faqs)->toBe([['q' => 'Question?', 'a' => 'Answer.']])
        ->and(CommerceReads::auditCount($site, 'draft_category_content', 'ok'))->toBe(1);
});

test('draft category content exposes service validation fields instead of an internal error', function () {
    [$actor, $site, $category] = CommerceReads::shopSite();

    $result = CommerceReads::run($actor, $site, 'draft_category_content', [
        'catalogue_revision' => 0,
        'slug' => $category->slug,
        'faqs' => array_fill(0, 13, ['q' => 'Question?', 'a' => 'Answer.']),
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($result->error['fields'])->toHaveKey('faqs')
        ->and(CommerceReads::auditCount($site, 'draft_category_content', 'validation'))->toBe(1)
        ->and($category->fresh()->faqs)->toBeNull();
});

test('draft category content returns not found when the shop is disabled', function () {
    [$actor, $site] = CommerceReads::shopSite();
    $site->update(['shop_enabled' => false]);

    $result = CommerceReads::run($actor, $site->fresh(), 'draft_category_content', [
        'catalogue_revision' => 0,
        'slug' => 'candles',
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('not_found')
        ->and(CommerceReads::auditCount($site, 'draft_category_content', 'not_found'))->toBe(1)
        ->and(Category::query()->where('site_id', $site->id)->value('description_long'))->toBeNull();
});
