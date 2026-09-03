<?php

use App\Enums\AgentRole;
use App\Enums\LogoConceptSource;
use App\Models\LogoConcept;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->staff = User::factory()->staff(AgentRole::Admin)->create();
    Storage::fake('s3');
});

it('groups logo candidates by source label', function () {
    $site = Site::factory()->create();
    seedLogoConcept($site, LogoConceptSource::Detected, 'logo-detected.png');
    seedLogoConcept($site, LogoConceptSource::Generated, 'logo-generated.png');
    seedLogoConcept($site, LogoConceptSource::Redraw, 'logo-redraw.png');
    seedLogoConcept($site, LogoConceptSource::Trace, 'logo-trace.png');

    Livewire::actingAs($this->staff)
        ->test('logo-picker', ['siteId' => $site->id])
        ->assertSee('Detected')
        ->assertSee('AI Concept')
        ->assertSee('Detected + Redrawn')
        ->assertSee('Detected + Traced');
});

it('keeps one selected logo while selecting across source groups', function () {
    $site = Site::factory()->create();
    $concepts = collect([
        seedLogoConcept($site, LogoConceptSource::Detected, 'logo-detected.png'),
        seedLogoConcept($site, LogoConceptSource::Generated, 'logo-generated.png'),
        seedLogoConcept($site, LogoConceptSource::Redraw, 'logo-redraw.png'),
        seedLogoConcept($site, LogoConceptSource::Trace, 'logo-trace.png'),
    ]);

    $component = Livewire::actingAs($this->staff)
        ->test('logo-picker', ['siteId' => $site->id]);

    foreach ($concepts as $concept) {
        $component->call('select', $concept->id);

        expect(LogoConcept::where('site_id', $site->id)->where('is_selected', true)->count())->toBe(1)
            ->and($concept->fresh()->is_selected)->toBeTrue();
    }
});

it('keeps Detected + Redraw + Trace baseline visible after a v2 AI regen', function () {
    // Regression for a review HIGH: pre-fix, all four sources
    // landed at version=1. The picker filtered by activeVersion, so
    // a regenerate (creating v2 generated rows) would set
    // activeVersion=2 and the baseline (v=1) silently disappeared,
    // breaking the "compare AI concepts against original detected"
    // story. Now: baseline rows live at v=0 and are co-shown with
    // whichever generated batch is active.
    $site = Site::factory()->create();

    seedLogoConcept($site, LogoConceptSource::Detected, 'logo-detected.png');
    seedLogoConcept($site, LogoConceptSource::Redraw, 'logo-redraw.png');
    seedLogoConcept($site, LogoConceptSource::Trace, 'logo-trace.png');

    // First AI batch lands at v=1
    seedLogoConcept($site, LogoConceptSource::Generated, 'logo-generated-v1-a.png', version: 1);

    // Admin clicks "Regenerate concepts" — second batch lands at v=2
    seedLogoConcept($site, LogoConceptSource::Generated, 'logo-generated-v2-a.png', version: 2);

    Livewire::actingAs($this->staff)
        ->test('logo-picker', ['siteId' => $site->id])
        ->assertSee('Detected')
        ->assertSee('Detected + Redrawn')
        ->assertSee('Detected + Traced')
        ->assertSee('AI Concept');
});

function seedLogoConcept(Site $site, LogoConceptSource $source, string $filename, ?int $version = null): LogoConcept
{
    // Baseline batch (Detected / Redraw / Trace) sits at v=0 so the
    // picker always co-shows it alongside any active AI batch. Only
    // Generated rows get positive batch versions (1, 2, 3...).
    $version ??= $source === LogoConceptSource::Generated ? 1 : 0;

    return LogoConcept::create([
        'site_id' => $site->id,
        'source' => $source,
        'version' => $version,
        'path' => "previews/{$site->id}/{$filename}",
        'rank' => 0,
        'metadata' => ['rank_reason' => $source->label()],
    ]);
}
