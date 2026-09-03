<?php

use App\Models\ImportedMedia;
use App\Models\Preview;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not render the facebook disclaimer when the site has no imported media', function () {
    $preview = previewForFacebookDisclaimer();

    $this->get(route('preview.show', $preview->slug))
        ->assertOk()
        ->assertDontSee('Some imagery sourced from public Facebook pages');
});

it('does not render the facebook disclaimer for unassigned imported media', function () {
    $preview = previewForFacebookDisclaimer();

    ImportedMedia::factory()->forSite($preview->site)->create([
        'assigned_to' => null,
    ]);

    $this->get(route('preview.show', $preview->slug))
        ->assertOk()
        ->assertDontSee('Some imagery sourced from public Facebook pages');
});

it('renders the facebook disclaimer when imported media is assigned as a hero', function () {
    $preview = previewForFacebookDisclaimer();

    ImportedMedia::factory()->forSite($preview->site)->create([
        'assigned_to' => 'hero',
    ]);

    $this->get(route('preview.show', $preview->slug))
        ->assertOk()
        ->assertSee('Some imagery sourced from public Facebook pages')
        ->assertSee('Not affiliated with or endorsed by Meta Platforms, Inc.');
});

function previewForFacebookDisclaimer(): Preview
{
    $site = Site::factory()->create([
        'business_name' => 'Ballymena Bespoke Tiling',
        'business_type' => 'Tiler',
        'location' => 'Ballymena',
    ]);

    return Preview::factory()->for($site)->create([
        'snapshot' => [
            'pages' => [
                'home' => [
                    'seo' => [
                        'meta_title' => 'Ballymena Bespoke Tiling',
                    ],
                ],
            ],
            'profile' => [
                'name' => 'Ballymena Bespoke Tiling',
            ],
            'theme' => [
                'primary_color' => '#0f172a',
                'accent_color' => '#06b6d4',
            ],
            'layout' => 'one_page',
        ],
    ]);
}
