<?php

use App\Models\LayoutPreset;
use App\Models\Site;
use App\Services\Site\PublicPageCache;
use App\Services\Site\ServiceLayoutAssigner;
use Illuminate\Support\Facades\DB;


/**
 * A service/about family with no matching home recipe — the genuine
 * error path now that editorial/precision home recipes exist.
 */
function seedServiceAboutOnlyFamily(): void
{
    config()->set('site_service_layouts.ledger-only', [
        'label' => 'Ledger only',
        'description' => 'Service/about family with no home recipe',
        'schema_version' => 1,
        'variants' => ['intro' => 'editorial', 'features' => 'numbered'],
        'eyebrow_policy' => 'first-only',
        'eyebrow_sections' => ['intro', 'features'],
    ]);
    config()->set('site_about_layouts.ledger-only', [
        'label' => 'Ledger only',
        'description' => 'Service/about family with no home recipe',
        'schema_version' => 1,
        'variants' => ['story' => 'editorial', 'values' => 'ledger'],
        'eyebrow_policy' => 'first-only',
        'eyebrow_sections' => ['story', 'values'],
    ]);
}

it('prints the current key and available options', function () {
    $site = Site::factory()->create(['services_layout' => 'classic']);

    $this->artisan('site:layout', ['site' => (string) $site->id])
        ->expectsOutputToContain('classic')
        ->expectsOutputToContain('editorial')
        ->expectsOutputToContain('showcase')
        ->expectsOutputToContain('precision')
        ->assertSuccessful();
});

it('sets a valid key, invalidates the public cache, and prints old to new', function () {
    $site = Site::factory()->create(['services_layout' => 'classic']);

    $this->mock(PublicPageCache::class)
        ->shouldReceive('invalidate')
        ->once()
        ->withArgs(fn (Site $s): bool => $s->id === $site->id);

    $this->artisan('site:layout', ['site' => (string) $site->id, 'key' => 'precision'])
        ->expectsOutputToContain('classic -> precision')
        ->assertSuccessful();

    expect($site->fresh()->services_layout)->toBe('precision');
});

it('rejects an invalid key with a non-zero exit and lists options', function () {
    $site = Site::factory()->create(['services_layout' => 'classic']);

    $this->artisan('site:layout', ['site' => (string) $site->id, 'key' => 'no-such-preset'])
        ->expectsOutputToContain('editorial')
        ->assertFailed();

    expect($site->fresh()->services_layout)->toBe('classic');
});

it('validate reports registry errors for a bespoke recipe with a bad variant name', function () {
    $site = Site::factory()->create(['services_layout' => 'bad-bespoke']);

    LayoutPreset::factory()->for($site)->active()->create([
        'key' => 'bad-bespoke',
        'recipe' => [
            'schema_version' => 1,
            'variants' => ['intro' => 'Not Valid'],
        ],
    ]);

    $this->artisan('site:layout', ['site' => (string) $site->id, '--validate' => true])
        ->expectsOutputToContain('intro')
        ->assertFailed();
});

it('flush invalidates every site with the given key and does not require a site argument', function () {
    $editorialA = Site::factory()->create(['services_layout' => 'editorial']);
    $editorialB = Site::factory()->create(['services_layout' => 'editorial']);
    Site::factory()->create(['services_layout' => 'classic']);

    $this->mock(PublicPageCache::class)
        ->shouldReceive('invalidate')
        ->twice()
        ->withArgs(fn (Site $s): bool => in_array($s->id, [$editorialA->id, $editorialB->id], true));

    $this->artisan('site:layout', ['--flush' => 'editorial', '--kind' => 'service'])
        ->expectsOutputToContain('2')
        ->assertSuccessful();
});

it('flush without --kind is a hard error and writes nothing', function () {
    $site = Site::factory()->create(['services_layout' => 'editorial']);

    $this->mock(PublicPageCache::class)->shouldReceive('invalidate')->never();

    $this->artisan('site:layout', ['--flush' => 'editorial'])
        ->expectsOutputToContain('--kind')
        ->assertFailed();

    expect($site->fresh()->services_layout)->toBe('editorial');
});

it('flush fans per kind column', function () {
    $aboutA = Site::factory()->create(['about_layout' => 'editorial', 'services_layout' => 'classic']);
    $aboutB = Site::factory()->create(['about_layout' => 'editorial', 'services_layout' => 'classic']);
    $serviceOnly = Site::factory()->create(['about_layout' => 'classic', 'services_layout' => 'editorial']);

    $this->mock(PublicPageCache::class)
        ->shouldReceive('invalidate')
        ->twice()
        ->withArgs(fn (Site $s): bool => in_array($s->id, [$aboutA->id, $aboutB->id], true));

    $this->artisan('site:layout', ['--flush' => 'editorial', '--kind' => 'about'])
        ->expectsOutputToContain('2')
        ->assertSuccessful();

    expect($serviceOnly->fresh()->services_layout)->toBe('editorial');
});

it('get and set honour --kind=about', function () {
    $site = Site::factory()->create([
        'services_layout' => 'classic',
        'about_layout' => 'classic',
        'home_layout' => 'classic',
    ]);

    $this->artisan('site:layout', ['site' => (string) $site->id, '--kind' => 'about'])
        ->expectsOutputToContain('about_layout')
        ->expectsOutputToContain('classic')
        ->assertSuccessful();

    $this->mock(PublicPageCache::class)
        ->shouldReceive('invalidate')
        ->once()
        ->withArgs(fn (Site $s): bool => $s->id === $site->id);

    $this->artisan('site:layout', [
        'site' => (string) $site->id,
        'key' => 'precision',
        '--kind' => 'about',
    ])->expectsOutputToContain('classic -> precision')
        ->assertSuccessful();

    $fresh = $site->fresh();
    expect($fresh->about_layout)->toBe('precision')
        ->and($fresh->services_layout)->toBe('classic')
        ->and($fresh->home_layout)->toBe('classic');
});

it('get and set honour --kind=home', function () {
    $site = Site::factory()->create([
        'services_layout' => 'classic',
        'about_layout' => 'classic',
        'home_layout' => 'classic',
    ]);

    $this->artisan('site:layout', [
        'site' => (string) $site->id,
        'key' => 'showcase',
        '--kind' => 'home',
    ])->assertSuccessful();

    $fresh = $site->fresh();
    expect($fresh->home_layout)->toBe('showcase')
        ->and($fresh->services_layout)->toBe('classic')
        ->and($fresh->about_layout)->toBe('classic');
});

it('rejects an unknown --kind', function () {
    $site = Site::factory()->create();

    $this->artisan('site:layout', ['site' => (string) $site->id, '--kind' => 'projects'])
        ->assertFailed();
});

it('assign without --independent writes the family to service, about, and home', function () {
    $site = Site::factory()->create([
        'services_layout' => 'classic',
        'about_layout' => 'classic',
        'home_layout' => 'classic',
    ]);

    $this->mock(ServiceLayoutAssigner::class)
        ->shouldReceive('assignFamily')
        ->once()
        ->andReturn('precision');

    $this->mock(PublicPageCache::class)
        ->shouldReceive('invalidate')
        ->once()
        ->withArgs(fn (Site $s): bool => $s->id === $site->id);

    $this->artisan('site:layout', ['site' => (string) $site->id, '--assign' => true])
        ->assertSuccessful();

    $fresh = $site->fresh();
    expect($fresh->services_layout)->toBe('precision')
        ->and($fresh->about_layout)->toBe('precision')
        ->and($fresh->home_layout)->toBe('precision');
});

it('assign invalidates the public cache only after the transaction commits', function () {
    $site = Site::factory()->create([
        'services_layout' => 'classic',
        'about_layout' => 'classic',
        'home_layout' => 'classic',
    ]);

    $this->mock(ServiceLayoutAssigner::class)
        ->shouldReceive('assignFamily')
        ->once()
        ->andReturn('showcase');

    $levelBeforeAssign = DB::transactionLevel();

    $this->mock(PublicPageCache::class)
        ->shouldReceive('invalidate')
        ->once()
        ->withArgs(function (Site $s) use ($site, $levelBeforeAssign): bool {
            expect(DB::transactionLevel())->toBe($levelBeforeAssign);

            return $s->id === $site->id;
        });

    $this->artisan('site:layout', ['site' => (string) $site->id, '--assign' => true])
        ->assertSuccessful();
});

it('assign does not invalidate the public cache when the transaction throws', function () {
    $site = Site::factory()->create([
        'services_layout' => 'classic',
        'about_layout' => 'classic',
        'home_layout' => 'classic',
    ]);

    $this->mock(ServiceLayoutAssigner::class)
        ->shouldReceive('assignFamily')
        ->once()
        ->andReturn('precision');

    $this->mock(PublicPageCache::class)->shouldReceive('invalidate')->never();

    $dispatcher = Site::getEventDispatcher();
    Site::flushEventListeners();
    Site::saving(function (): never {
        throw new RuntimeException('assign transaction failed');
    });

    try {
        expect(fn () => $this->artisan('site:layout', ['site' => (string) $site->id, '--assign' => true]))
            ->toThrow(RuntimeException::class, 'assign transaction failed');
    } finally {
        Site::setEventDispatcher($dispatcher);
    }

    $fresh = $site->fresh();
    expect($fresh->services_layout)->toBe('classic')
        ->and($fresh->about_layout)->toBe('classic');
});

it('assign of showcase also sets home_layout=showcase', function () {
    $site = Site::factory()->create([
        'services_layout' => 'classic',
        'about_layout' => 'classic',
        'home_layout' => 'classic',
    ]);

    $this->mock(ServiceLayoutAssigner::class)
        ->shouldReceive('assignFamily')
        ->once()
        ->andReturn('showcase');

    $this->mock(PublicPageCache::class)
        ->shouldReceive('invalidate')
        ->once()
        ->withArgs(fn (Site $s): bool => $s->id === $site->id);

    $this->artisan('site:layout', ['site' => (string) $site->id, '--assign' => true])
        ->assertSuccessful();

    $fresh = $site->fresh();
    expect($fresh->services_layout)->toBe('showcase')
        ->and($fresh->about_layout)->toBe('showcase')
        ->and($fresh->home_layout)->toBe('showcase');
});

it('assign --independent writes exactly one kind', function () {
    $site = Site::factory()->create([
        'services_layout' => 'classic',
        'about_layout' => 'classic',
        'home_layout' => 'classic',
    ]);

    $this->mock(ServiceLayoutAssigner::class)
        ->shouldReceive('assignFamily')
        ->once()
        ->andReturn('precision');

    $this->mock(PublicPageCache::class)
        ->shouldReceive('invalidate')
        ->once()
        ->withArgs(fn (Site $s): bool => $s->id === $site->id);

    $this->artisan('site:layout', [
        'site' => (string) $site->id,
        '--assign' => true,
        '--kind' => 'about',
        '--independent' => true,
    ])->assertSuccessful();

    $fresh = $site->fresh();
    expect($fresh->about_layout)->toBe('precision')
        ->and($fresh->services_layout)->toBe('classic')
        ->and($fresh->home_layout)->toBe('classic');
});

it('assign --independent on home writes the family when a home recipe exists', function () {
    $site = Site::factory()->create([
        'services_layout' => 'classic',
        'about_layout' => 'classic',
        'home_layout' => 'classic',
    ]);

    $this->mock(ServiceLayoutAssigner::class)
        ->shouldReceive('assignFamily')
        ->once()
        ->andReturn('editorial');

    $this->mock(PublicPageCache::class)
        ->shouldReceive('invalidate')
        ->once()
        ->withArgs(fn (Site $s): bool => $s->id === $site->id);

    $this->artisan('site:layout', [
        'site' => (string) $site->id,
        '--assign' => true,
        '--kind' => 'home',
        '--independent' => true,
    ])->assertSuccessful();

    $fresh = $site->fresh();
    expect($fresh->home_layout)->toBe('editorial')
        ->and($fresh->services_layout)->toBe('classic')
        ->and($fresh->about_layout)->toBe('classic');
});

it('assign --independent on home fails when the family has no home recipe', function () {
    $site = Site::factory()->create([
        'services_layout' => 'classic',
        'about_layout' => 'classic',
        'home_layout' => 'classic',
    ]);

    seedServiceAboutOnlyFamily();

    $this->mock(ServiceLayoutAssigner::class)
        ->shouldReceive('assignFamily')
        ->once()
        ->andReturn('ledger-only');

    $this->mock(PublicPageCache::class)->shouldReceive('invalidate')->never();

    $this->artisan('site:layout', [
        'site' => (string) $site->id,
        '--assign' => true,
        '--kind' => 'home',
        '--independent' => true,
    ])->expectsOutputToContain('Family [ledger-only] has no home recipe')
        ->assertFailed();

    $fresh = $site->fresh();
    expect($fresh->home_layout)->toBe('classic')
        ->and($fresh->services_layout)->toBe('classic')
        ->and($fresh->about_layout)->toBe('classic');
});

it('assign without --independent leaves home unchanged when the family has no home recipe', function () {
    $site = Site::factory()->create([
        'services_layout' => 'classic',
        'about_layout' => 'classic',
        'home_layout' => 'classic',
    ]);

    seedServiceAboutOnlyFamily();

    $this->mock(ServiceLayoutAssigner::class)
        ->shouldReceive('assignFamily')
        ->once()
        ->andReturn('ledger-only');

    $this->mock(PublicPageCache::class)
        ->shouldReceive('invalidate')
        ->once()
        ->withArgs(fn (Site $s): bool => $s->id === $site->id);

    $this->artisan('site:layout', ['site' => (string) $site->id, '--assign' => true])
        ->assertSuccessful();

    $fresh = $site->fresh();
    expect($fresh->services_layout)->toBe('ledger-only')
        ->and($fresh->about_layout)->toBe('ledger-only')
        ->and($fresh->home_layout)->toBe('classic');
});

it('assign writes nothing when any target kind is invalid', function () {
    $site = Site::factory()->create([
        'services_layout' => 'classic',
        'about_layout' => 'classic',
        'home_layout' => 'classic',
    ]);

    $this->mock(ServiceLayoutAssigner::class)
        ->shouldReceive('assignFamily')
        ->once()
        ->andReturn('editorial');

    LayoutPreset::factory()->for($site)->active()->create([
        'page_kind' => 'about',
        'key' => 'editorial',
        'recipe' => [
            'schema_version' => '1',
            'variants' => ['story' => 'editorial', 'values' => 'ledger'],
            'eyebrow_policy' => 'first-only',
        ],
    ]);

    $this->mock(PublicPageCache::class)->shouldReceive('invalidate')->never();

    $this->artisan('site:layout', ['site' => (string) $site->id, '--assign' => true])
        ->assertFailed();

    $fresh = $site->fresh();
    expect($fresh->services_layout)->toBe('classic')
        ->and($fresh->about_layout)->toBe('classic')
        ->and($fresh->home_layout)->toBe('classic');
});

it('validate honours --kind', function () {
    $site = Site::factory()->create(['about_layout' => 'bad-about']);

    LayoutPreset::factory()->for($site)->active()->create([
        'page_kind' => 'about',
        'key' => 'bad-about',
        'recipe' => [
            'schema_version' => 1,
            'variants' => ['story' => 'Not Valid'],
        ],
    ]);

    $this->artisan('site:layout', [
        'site' => (string) $site->id,
        '--kind' => 'about',
        '--validate' => true,
    ])->expectsOutputToContain('story')
        ->assertFailed();
});


it('validate fails when a named variant partial does not exist', function () {
    $site = Site::factory()->create(['services_layout' => 'magazine-bespoke']);

    LayoutPreset::factory()->for($site)->active()->create([
        'key' => 'magazine-bespoke',
        'recipe' => [
            'schema_version' => 1,
            'variants' => ['intro' => 'magazine', 'features' => 'cards'],
            'eyebrow_policy' => 'first-only',
        ],
    ]);

    $this->artisan('site:layout', ['site' => (string) $site->id, '--validate' => true])
        ->expectsOutputToContain('magazine')
        ->assertFailed();

    expect($site->fresh()->services_layout)->toBe('magazine-bespoke');
});

it('rejects an about key whose recipe stamps a hero family', function () {
    $site = Site::factory()->create(['about_layout' => 'classic']);

    LayoutPreset::factory()->for($site)->active()->create([
        'page_kind' => 'about',
        'key' => 'hero-bespoke',
        'recipe' => [
            'schema_version' => 1,
            'variants' => ['hero' => 'boxed-left', 'story' => 'editorial', 'values' => 'ledger'],
            'eyebrow_policy' => 'first-only',
            'eyebrow_sections' => ['story', 'values'],
        ],
    ]);

    $this->artisan('site:layout', [
        'site' => (string) $site->id,
        'key' => 'hero-bespoke',
        '--kind' => 'about',
    ])->assertFailed();

    expect($site->fresh()->about_layout)->toBe('classic');
});

it('rejects an about key whose recipe names an unknown family', function () {
    $site = Site::factory()->create(['about_layout' => 'classic']);

    LayoutPreset::factory()->for($site)->active()->create([
        'page_kind' => 'about',
        'key' => 'typo-bespoke',
        'recipe' => [
            'schema_version' => 1,
            'variants' => ['storys' => 'editorial', 'values' => 'ledger'],
            'eyebrow_policy' => 'first-only',
            'eyebrow_sections' => ['story', 'values'],
        ],
    ]);

    $this->artisan('site:layout', [
        'site' => (string) $site->id,
        'key' => 'typo-bespoke',
        '--kind' => 'about',
    ])->assertFailed();

    expect($site->fresh()->about_layout)->toBe('classic');
});

it('rejects a hard-invalid recipe key without persisting', function () {
    $site = Site::factory()->create(['services_layout' => 'classic']);

    LayoutPreset::factory()->for($site)->active()->create([
        'key' => 'broken-recipe',
        'recipe' => [
            'schema_version' => '1',
            'variants' => ['intro' => 'editorial', 'features' => 'numbered'],
            'eyebrow_policy' => 'first-only',
        ],
    ]);

    $this->artisan('site:layout', ['site' => (string) $site->id, 'key' => 'broken-recipe'])
        ->assertFailed();

    expect($site->fresh()->services_layout)->toBe('classic');
});

it('rejects editorial when the sites active editorial row is hard-invalid', function () {
    $site = Site::factory()->create(['services_layout' => 'classic']);
    $other = Site::factory()->create(['services_layout' => 'classic']);

    LayoutPreset::factory()->for($site)->active()->create([
        'key' => 'editorial',
        'recipe' => [
            'schema_version' => '1',
            'variants' => ['intro' => 'editorial', 'features' => 'numbered'],
            'eyebrow_policy' => 'first-only',
        ],
    ]);

    $this->artisan('site:layout', ['site' => (string) $site->id, 'key' => 'editorial'])
        ->assertFailed();

    expect($site->fresh()->services_layout)->toBe('classic');

    $this->artisan('site:layout', ['site' => (string) $other->id, 'key' => 'editorial'])
        ->assertSuccessful();

    expect($other->fresh()->services_layout)->toBe('editorial');
});

it('validate on a hard-invalid active recipe fails without touching the column', function () {
    $site = Site::factory()->create(['services_layout' => 'broken-recipe']);

    LayoutPreset::factory()->for($site)->active()->create([
        'key' => 'broken-recipe',
        'recipe' => [
            'schema_version' => '1',
            'variants' => ['intro' => 'editorial', 'features' => 'numbered'],
            'eyebrow_policy' => 'first-only',
        ],
    ]);

    $this->artisan('site:layout', ['site' => (string) $site->id, '--validate' => true])
        ->assertFailed();

    expect($site->fresh()->services_layout)->toBe('broken-recipe');
});

it('validate-before-persist does not write a hard-invalid key', function () {
    $site = Site::factory()->create(['services_layout' => 'classic']);

    LayoutPreset::factory()->for($site)->active()->create([
        'key' => 'broken-recipe',
        'recipe' => [
            'schema_version' => '1',
            'variants' => ['intro' => 'editorial', 'features' => 'numbered'],
            'eyebrow_policy' => 'first-only',
        ],
    ]);

    $this->artisan('site:layout', [
        'site' => (string) $site->id,
        'key' => 'broken-recipe',
        '--validate' => true,
    ])->assertFailed();

    expect($site->fresh()->services_layout)->toBe('classic');
});

it('validate-before-persist writes a valid bespoke key', function () {
    $site = Site::factory()->create(['services_layout' => 'classic']);

    LayoutPreset::factory()->for($site)->active()->create([
        'key' => 'roof-special',
        'recipe' => [
            'schema_version' => 1,
            'variants' => ['intro' => 'editorial', 'features' => 'numbered'],
            'eyebrow_policy' => 'first-only',
        ],
    ]);

    $this->artisan('site:layout', [
        'site' => (string) $site->id,
        'key' => 'roof-special',
        '--validate' => true,
    ])->assertSuccessful();

    expect($site->fresh()->services_layout)->toBe('roof-special');
});

it('validate-before-persist refuses a site-scoped recipe that fails validate without writing', function () {
    $site = Site::factory()->create(['services_layout' => 'classic']);

    LayoutPreset::factory()->for($site)->active()->create([
        'key' => 'editorial',
        'recipe' => [
            'schema_version' => 1,
            'variants' => ['intro' => 'magazine', 'features' => 'numbered'],
            'eyebrow_policy' => 'first-only',
        ],
    ]);

    $this->artisan('site:layout', [
        'site' => (string) $site->id,
        'key' => 'editorial',
        '--validate' => true,
    ])->assertFailed();

    expect($site->fresh()->services_layout)->toBe('classic');
});

it('resolves a site by custom domain', function () {
    $site = Site::factory()->create([
        'services_layout' => 'showcase',
        'custom_domain' => 'acme.example.test',
    ]);

    $this->artisan('site:layout', ['site' => 'acme.example.test'])
        ->expectsOutputToContain('showcase')
        ->assertSuccessful();
});

it('get and set honour --kind=chrome', function () {
    $site = Site::factory()->create(['chrome_layout' => 'classic']);
    LayoutPreset::factory()->for($site)->active()->create([
        'page_kind' => 'chrome',
        'key' => 'centred-badge',
        'label' => 'Centred badge',
        'recipe' => [
            'schema_version' => 1,
            'layout' => 'centred',
            'top_bar' => 'off',
            'nav_row' => 'beneath',
            'nav_case' => 'caps',
            'logo_height' => 'md',
            'store_controls' => 'icons+labels',
            'sticky_shrink' => 'on',
        ],
    ]);

    $this->artisan('site:layout', ['site' => (string) $site->id, '--kind' => 'chrome'])
        ->expectsOutputToContain('chrome_layout')
        ->expectsOutputToContain('classic')
        ->expectsOutputToContain('centred-badge')
        ->assertSuccessful();

    $this->mock(PublicPageCache::class)
        ->shouldReceive('invalidate')
        ->once()
        ->withArgs(fn (Site $s): bool => $s->id === $site->id);

    $this->artisan('site:layout', [
        'site' => (string) $site->id,
        'key' => 'centred-badge',
        '--kind' => 'chrome',
    ])->expectsOutputToContain('classic -> centred-badge')
        ->assertSuccessful();

    expect($site->fresh()->chrome_layout)->toBe('centred-badge');
});

it('validates the classic chrome recipe', function () {
    $site = Site::factory()->create(['chrome_layout' => 'classic']);

    $this->artisan('site:layout', [
        'site' => (string) $site->id,
        '--kind' => 'chrome',
        '--validate' => true,
    ])->expectsOutputToContain('valid')
        ->assertSuccessful();
});

it('rejects --assign for chrome', function () {
    $site = Site::factory()->create();

    $this->artisan('site:layout', [
        'site' => (string) $site->id,
        '--kind' => 'chrome',
        '--assign' => true,
    ])->expectsOutputToContain('--assign is not supported for chrome presets.')
        ->assertFailed();
});
