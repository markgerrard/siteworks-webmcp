<?php

use App\Enums\ProjectItemSource;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Support\ProjectSectionVocabulary;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    config()->set('site.honest_project_framing', false);
});

it('returns marketing headings when flag is off', function () {
    $site = Site::factory()->create(['honest_project_framing' => false]);
    $aiItem = ProjectItem::factory()->for($site)->create(['source' => ProjectItemSource::AiGenerated]);

    $vocab = ProjectSectionVocabulary::for($site);

    expect($vocab->galleryHeading(collect([$aiItem])))->toBe('Recent Work');
    expect($vocab->galleryEyebrow(collect([$aiItem])))->toBe('Our Work');
    expect($vocab->caseStudyHeading(collect([$aiItem])))->toBe('Case Studies');
    expect($vocab->shouldShowExampleBadge($aiItem))->toBeFalse();
});

it('returns example headings when flag is on AND section has AI items', function () {
    $site = Site::factory()->create(['honest_project_framing' => true]);
    $aiItem = ProjectItem::factory()->for($site)->create(['source' => ProjectItemSource::AiGenerated]);

    $vocab = ProjectSectionVocabulary::for($site);

    expect($vocab->galleryHeading(collect([$aiItem])))->toBe('Example Projects');
    expect($vocab->galleryEyebrow(collect([$aiItem])))->toBe('Examples');
    expect($vocab->caseStudyHeading(collect([$aiItem])))->toBe('Example Project Highlights');
    expect($vocab->shouldShowExampleBadge($aiItem))->toBeTrue();
});

it('returns marketing headings when flag is on but all items are sourced', function () {
    $site = Site::factory()->create(['honest_project_framing' => true]);
    $sourced = ProjectItem::factory()->for($site)->create(['source' => ProjectItemSource::AgentUpload]);

    $vocab = ProjectSectionVocabulary::for($site);

    expect($vocab->galleryHeading(collect([$sourced])))->toBe('Recent Work');
    expect($vocab->galleryEyebrow(collect([$sourced])))->toBe('Our Work');
    expect($vocab->shouldShowExampleBadge($sourced))->toBeFalse();
});

it('uses conservative vocabulary for mixed sections', function () {
    $site = Site::factory()->create(['honest_project_framing' => true]);
    $ai = ProjectItem::factory()->for($site)->create(['source' => ProjectItemSource::AiGenerated]);
    $sourced = ProjectItem::factory()->for($site)->create(['source' => ProjectItemSource::AgentUpload]);

    $vocab = ProjectSectionVocabulary::for($site);

    expect($vocab->galleryHeading(collect([$ai, $sourced])))->toBe('Example Projects');
    expect($vocab->galleryEyebrow(collect([$ai, $sourced])))->toBe('Examples');
});
