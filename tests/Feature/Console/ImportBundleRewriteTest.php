<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

it('rewrites absolute URL prefixes in imported revision JSON and plain text columns', function () {
    Storage::fake('local');

    $site = Site::factory()->create();
    $page = GeneratedPage::factory()->for($site)->create();
    $from = 'https://demo-media.lon1.digitaloceanspaces.com/';
    $to = 'http://app.localhost:8090/storage/';

    $bundleDir = storage_path('app/testing/rewrite-bundle-'.uniqid());
    File::ensureDirectoryExists($bundleDir);

    $bundle = [
        'manifest' => [
            'source_site_id' => $site->id + 1000,
            'tables' => ['sites' => 1, 'generated_pages' => 1, 'generated_page_revisions' => 1],
        ],
        'tables' => [
            'sites' => [[
                'id' => $site->id + 1000,
                'business_name' => 'Rewrite Cafe',
                'business_type' => 'bakery',
                'location' => 'Palo Alto, CA',
                'brand_favicon_url' => $from.'sites/99/brand/favicon.png',
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]],
            'generated_pages' => [[
                'id' => $page->id + 1000,
                'site_id' => $site->id + 1000,
                'page_type' => 'home',
                'content_data' => ['sections' => []],
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]],
            'generated_page_revisions' => [[
                'id' => 91001,
                'page_id' => $page->id + 1000,
                'content_data' => [
                    'sections' => [[
                        'type' => 'hero',
                        'image' => [
                            'url' => $from.'demo-previews/99/hero-home.webp',
                        ],
                    ]],
                ],
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]],
        ],
    ];

    File::put($bundleDir.'/bundle.json', json_encode($bundle));

    $this->artisan('site:import-bundle', [
        'path' => $bundleDir,
        '--disk' => 'local',
        '--rewrite' => [$from.'='.$to],
    ])->assertSuccessful();

    $importedSite = Site::query()->find($site->id + 1000);
    expect($importedSite)->not->toBeNull()
        ->and($importedSite->brand_favicon_url)->toBe($to.'sites/99/brand/favicon.png');

    $revision = PageRevision::query()->find(91001);
    expect($revision)->not->toBeNull()
        ->and($revision->content_data['sections'][0]['image']['url'])
        ->toBe($to.'demo-previews/99/hero-home.webp');

    File::deleteDirectory($bundleDir);
});
