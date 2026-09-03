<?php

use App\Models\Site;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\User;
use App\Enums\SiteStatus;
use App\Enums\GenerationMode;
use App\Enums\PageType;


it('creates a site with defaults', function () {
    $site = Site::factory()->create();

    expect($site->status)->toBe(SiteStatus::Draft)
        ->and($site->generation_mode)->toBe(GenerationMode::NoSite)
        ->and($site->theme)->toBe('trades-bold');
});

it('site belongs to user', function () {
    $user = User::factory()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);

    expect($site->createdBy->id)->toBe($user->id);
});

it('site has one business profile', function () {
    $site = Site::factory()->create();
    BusinessProfile::factory()->create(['site_id' => $site->id]);

    expect($site->businessProfile)->not->toBeNull()
        ->and($site->businessProfile->profile_data)->toBeArray();
});

it('site has generated pages', function () {
    $site = Site::factory()->create();
    GeneratedPage::factory()->create(['site_id' => $site->id, 'page_type' => PageType::Home]);
    GeneratedPage::factory()->create(['site_id' => $site->id, 'page_type' => PageType::About]);

    expect($site->generatedPages)->toHaveCount(2);
});

it('site has previews with latest accessor', function () {
    $site = Site::factory()->create();
    Preview::factory()->create(['site_id' => $site->id, 'published_at' => now()->subDay()]);
    $latest = Preview::factory()->create(['site_id' => $site->id, 'published_at' => now()]);

    expect($site->latestPreview->id)->toBe($latest->id);
});

it('generated page content data is structured JSON', function () {
    $page = GeneratedPage::factory()->create();

    expect($page->content_data)->toBeArray()
        ->and($page->content_data)->toHaveKey('hero')
        ->and($page->content_data['hero'])->toHaveKey('heading');
});
