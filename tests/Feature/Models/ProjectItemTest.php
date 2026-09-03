<?php

use App\Enums\ProjectItemSource;
use App\Enums\ProjectItemStatus;
use App\Enums\ProjectItemType;
use App\Models\ProjectItem;
use App\Models\Site;

it('creates a gallery project item via factory', function () {
    $item = ProjectItem::factory()->gallery()->create();

    expect($item->type)->toBe(ProjectItemType::Gallery);
    expect($item->status)->toBe(ProjectItemStatus::Draft);
    expect($item->source)->toBe(ProjectItemSource::AiGenerated);
    expect($item->site)->toBeInstanceOf(Site::class);
    expect($item->content_hash)->toHaveLength(40);
    expect($item->media_hash)->toHaveLength(40);
});

it('creates a case study project item with metrics', function () {
    $item = ProjectItem::factory()->caseStudy()->create();

    expect($item->type)->toBe(ProjectItemType::CaseStudy);
    expect($item->metrics)->toBeArray();
    expect($item->metrics[0])->toHaveKey('icon');
    expect($item->metrics[0])->toHaveKey('label');
});

it('scopes to gallery items on a site', function () {
    $site = Site::factory()->create();
    ProjectItem::factory()->gallery()->for($site)->count(3)->create();
    ProjectItem::factory()->caseStudy()->for($site)->count(2)->create();

    expect($site->galleryItems()->count())->toBe(3);
    expect($site->caseStudyItems()->count())->toBe(2);
});

it('excludes archived items from scopes', function () {
    $site = Site::factory()->create();
    ProjectItem::factory()->gallery()->for($site)->count(2)->create();
    ProjectItem::factory()->gallery()->for($site)->archived()->create();

    expect($site->galleryItems()->count())->toBe(2);
    expect($site->projectItems()->count())->toBe(3);
});
