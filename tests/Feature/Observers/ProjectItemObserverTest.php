<?php

use App\Models\ProjectItem;

it('computes content_hash from title/description/category/metrics on save', function () {
    $item = ProjectItem::factory()->create([
        'title' => 'Victorian terrace re-roof',
        'description' => 'Full strip and re-lay with slate replacements',
        'category' => 'Heritage',
        'metrics' => null,
    ]);

    $expected = sha1("Victorian terrace re-roof\nFull strip and re-lay with slate replacements\nHeritage\nnull");
    expect($item->content_hash)->toBe($expected);
});

it('includes metrics json in content_hash', function () {
    $item = ProjectItem::factory()->create([
        'title' => 'City scaffold',
        'description' => '20m access',
        'category' => 'Commercial',
        'metrics' => [['icon' => 'timer', 'label' => '5 days']],
    ]);

    $expected = sha1("City scaffold\n20m access\nCommercial\n".json_encode([['icon' => 'timer', 'label' => '5 days']]));
    expect($item->content_hash)->toBe($expected);
});

it('computes media_hash from image_id', function () {
    $itemNoImage = ProjectItem::factory()->create(['image_id' => null]);
    expect($itemNoImage->media_hash)->toBe(sha1(''));

    // Use an existing site_media row for the FK constraint
    $siteMedia = \App\Models\SiteMedia::factory()->create();
    $itemWithImage = ProjectItem::factory()->create(['image_id' => $siteMedia->id]);
    expect($itemWithImage->media_hash)->toBe(sha1((string) $siteMedia->id));
});

it('recomputes hashes on update', function () {
    $item = ProjectItem::factory()->create(['title' => 'Original']);
    $originalHash = $item->content_hash;

    $item->update(['title' => 'Updated title']);
    expect($item->fresh()->content_hash)->not->toBe($originalHash);
});
