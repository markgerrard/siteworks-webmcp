<?php

use App\Models\Shop\Category;
use App\Models\Site;
use App\Services\Shop\CategoryContentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('category content is constrained and sanitised to the public subset', function () {
    $site = Site::factory()->create(['custom_domain' => 'content.example', 'custom_domain_status' => 'active']);
    $category = Category::factory()->for($site)->create();

    app(CategoryContentService::class)->update($category, [
        'description_long' => '<h2 onclick="alert(1)">Hello</h2><p><script>alert(1)</script><strong>Safe</strong> <a href="javascript:alert(1)">bad</a> <a href="/shop">shop</a> <a href="https://content.example/shop">same</a> <a href="https://elsewhere.example/shop">other</a></p>',
        'faqs' => [['q' => 'Question?', 'a' => 'Answer.']],
        'meta_title' => 'A title',
        'meta_description' => 'A description',
    ]);

    $category->refresh();

    expect($category->description_long)
        ->toContain('<h2>Hello</h2>')
        ->toContain('<strong>Safe</strong>')
        ->toContain('href="/shop"')
        ->toContain('href="https://content.example/shop"')
        ->not->toContain('script')
        ->not->toContain('onclick')
        ->not->toContain('javascript:')
        ->not->toContain('elsewhere.example')
        ->and($category->faqs)->toBe([['q' => 'Question?', 'a' => 'Answer.']])
        ->and($category->meta_description)->toBe('A description');
});

test('category content rejects oversized faq and meta fields', function () {
    $category = Category::factory()->for(Site::factory())->create();

    expect(fn () => app(CategoryContentService::class)->update($category, [
        'faqs' => array_fill(0, 13, ['q' => 'Question?', 'a' => 'Answer.']),
        'meta_title' => str_repeat('a', 71),
        'meta_description' => str_repeat('a', 171),
    ]))->toThrow(ValidationException::class);
});

test('category content rejects non-list faq shapes', function () {
    $category = Category::factory()->for(Site::factory())->create();

    expect(fn () => app(CategoryContentService::class)->update($category, [
        'faqs' => ['0,window.__pwned=1' => ['q' => 'Question?', 'a' => 'Answer.']],
    ]))->toThrow(ValidationException::class);
});

test('category content rejects long copy above 20000 sanitised characters', function () {
    $category = Category::factory()->for(Site::factory())->create();
    $copy = str_repeat('<p>Safe copy.</p>', 1_251);

    expect(mb_strlen($copy))->toBeGreaterThan(20_000)
        ->and(fn () => app(CategoryContentService::class)->update($category, [
            'description_long' => $copy,
        ]))->toThrow(ValidationException::class);
});
