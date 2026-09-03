<?php

use App\Enums\PageStatus;
use App\Exceptions\Site\StaleRevisionException;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Services\Site\FormFieldsWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedFormWriterSite(): Site
{
    $site = Site::factory()->create();
    $content = ['sections' => [
        ['type' => 'hero', 'title' => 'Hi'],
        ['type' => 'contact_form', 'title' => 'Contact us', 'fields' => []],
    ]];

    $page = GeneratedPage::create([
        'site_id' => $site->id, 'page_type' => 'contact', 'content_data' => $content,
        'sort_order' => 1, 'version' => 1, 'status' => PageStatus::Published,
    ]);
    $rev = PageRevision::create([
        'page_id' => $page->id, 'content_data' => $content,
        'ai_generated' => false, 'created_at' => now(),
    ]);
    $page->update(['published_revision_id' => $rev->id]);
    Preview::factory()->create([
        'site_id' => $site->id,
        'snapshot' => ['pages' => ['contact' => ['contact_form' => ['type' => 'contact_form', 'fields' => []]]]],
    ]);

    return $site;
}

test('writing fields lands in the draft revision', function () {
    $site = seedFormWriterSite();
    $page = GeneratedPage::where('site_id', $site->id)->firstOrFail();

    app(FormFieldsWriter::class)->write($page, 1, [
        ['name' => 'job_postcode', 'label' => 'Job postcode', 'type' => 'text', 'required' => true],
    ], userId: null, expectedBaseRevisionId: null);

    $draft = PageRevision::find($page->fresh()->draft_revision_id);
    $section = collect($draft->content_data['sections'])->firstWhere('type', 'contact_form');

    expect($section['fields'][0]['name'])->toBe('job_postcode');
});

test('writing fields also mirrors into the preview snapshot', function () {
    // The public site renders previews.snapshot, the editor renders the
    // revision. If only one is written the change appears to do nothing.
    $site = seedFormWriterSite();
    $page = GeneratedPage::where('site_id', $site->id)->firstOrFail();

    app(FormFieldsWriter::class)->write($page, 1, [
        ['name' => 'job_postcode', 'label' => 'Job postcode', 'type' => 'text'],
    ], userId: null, expectedBaseRevisionId: null);

    $snapshot = $site->fresh()->latestPreview->snapshot;

    expect($snapshot['pages']['contact']['contact_form']['fields'][0]['name'])->toBe('job_postcode');
});

test('a stale base revision is refused', function () {
    $site = seedFormWriterSite();
    $page = GeneratedPage::where('site_id', $site->id)->firstOrFail();

    app(FormFieldsWriter::class)->write($page, 1, [], userId: null, expectedBaseRevisionId: null);

    expect(fn () => app(FormFieldsWriter::class)->write($page->fresh(), 1, [], userId: null, expectedBaseRevisionId: 999999))
        ->toThrow(StaleRevisionException::class);
});
