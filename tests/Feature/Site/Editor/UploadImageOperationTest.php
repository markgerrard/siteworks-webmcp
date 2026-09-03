<?php

use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\SiteMedia;
use App\Services\Site\CompositionService;
use App\Services\Site\PublicPageCache;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EditorSeeds;

function uploadImagePngBytes(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAQAAAAEAQMAAACTPww9AAAAIGNIUk0AAHomAACAhAAA+gAAAIDoAAB1MAAA6mAAADqYAAAXcJy6UTwAAAAGUExURf8AAP///0EdNBEAAAABYktHRAH/Ai3eAAAAB3RJTUUH6ggcBTkHx5/rUAAAAAtJREFUCNdjYIAAAAAIAAEvIN0xAAAAAElFTkSuQmCC');
}

beforeEach(function () {
    $this->withoutVite();
    Storage::fake(config('filesystems.default'));
});

it('uploads sniffed png bytes and records the webmcp actor channel', function () {
    [$actor, $site] = EditorSeeds::site();

    $result = EditorSeeds::run($actor, $site, 'upload_image', [
        'data_base64' => 'data:image/gif;base64,'.base64_encode(uploadImagePngBytes()),
        'composition_revision' => 0,
        'mime' => 'image/gif',
        'filename' => 'untrusted.gif',
    ]);

    $media = SiteMedia::query()->where('site_id', $site->id)->sole();

    expect($result->ok)->toBeTrue()
        ->and($result->data)->toMatchArray(['media_id' => $media->id, 'url' => $media->url])
        ->and($media->actor_channel)->toBe('webmcp')
        ->and($media->mime_type)->toBe('image/webp');
});

it('assigns an upload to a hero image field and advances only the draft', function () {
    [$actor, $site, $page] = EditorSeeds::site();
    $publishedRevisionId = $page->published_revision_id;
    $cache = app(PublicPageCache::class);
    $cacheGeneration = $cache->generation($site);

    $result = EditorSeeds::run($actor, $site, 'upload_image', [
        'data_base64' => base64_encode(uploadImagePngBytes()),
        'composition_revision' => 0,
        'page_id' => $page->id,
        'stored_index' => 0,
        'field_path' => 'background_image',
        'revision_base' => $publishedRevisionId,
        'structure_epoch' => $page->structure_epoch,
    ]);

    $page->refresh();
    $media = SiteMedia::query()->where('site_id', $site->id)->sole();
    $draft = PageRevision::query()->findOrFail($page->draft_revision_id);

    expect($result->ok)->toBeTrue()
        ->and($result->data['media_id'])->toBe($media->id)
        ->and($result->data['url'])->toBe($media->url)
        ->and($result->data['draft_revision_id'])->toBe($page->draft_revision_id)
        ->and($result->data['html'])->toContain('data-editable')
        ->and($draft->content_data['sections'][0]['background_image'])->toBe($media->url)
        ->and($page->published_revision_id)->toBe($publishedRevisionId)
        ->and($cache->generation($site))->toBe($cacheGeneration);
});

it('returns validation for gif bytes without leaving a media row', function () {
    [$actor, $site] = EditorSeeds::site();

    $result = EditorSeeds::run($actor, $site, 'upload_image', [
        'data_base64' => base64_encode("GIF89a\x01\x00\x01\x00\x00\xff\x00,"),
        'composition_revision' => 0,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($result->error['fields'])->toHaveKey('data_base64')
        ->and(SiteMedia::query()->where('site_id', $site->id)->count())->toBe(0);
});

it('returns validation for oversized input without leaving a media row', function () {
    [$actor, $site] = EditorSeeds::site();

    $result = EditorSeeds::run($actor, $site, 'upload_image', [
        'data_base64' => str_repeat('A', intdiv(5 * 1024 * 1024 * 4, 3) + 1000),
        'composition_revision' => 0,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($result->error['fields'])->toHaveKey('data_base64')
        ->and(SiteMedia::query()->where('site_id', $site->id)->count())->toBe(0);
});

it('requires every assignment input together and does not ingest a partial assignment', function () {
    [$actor, $site, $page] = EditorSeeds::site();

    $result = EditorSeeds::run($actor, $site, 'upload_image', [
        'data_base64' => base64_encode(uploadImagePngBytes()),
        'composition_revision' => 0,
        'page_id' => $page->id,
        'stored_index' => 0,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and(SiteMedia::query()->where('site_id', $site->id)->count())->toBe(0);
});

it('refuses a non-image assignment target without ingesting media', function () {
    [$actor, $site, $page] = EditorSeeds::site();

    $result = EditorSeeds::run($actor, $site, 'upload_image', [
        'data_base64' => base64_encode(uploadImagePngBytes()),
        'composition_revision' => 0,
        'page_id' => $page->id,
        'stored_index' => 0,
        'field_path' => 'title',
        'revision_base' => $page->published_revision_id,
        'structure_epoch' => $page->structure_epoch,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('unsupported_field')
        ->and(SiteMedia::query()->where('site_id', $site->id)->count())->toBe(0);
});

it('rejects a stale composition revision before ingesting media', function () {
    [$actor, $site, $page] = EditorSeeds::site();
    app(CompositionService::class)->ensureDraftRow($site, $actor->id);
    SiteDraft::query()->where('site_id', $site->id)->update(['admin_revision' => 3]);
    $publishedRevisionId = $page->published_revision_id;

    $result = EditorSeeds::run($actor, $site, 'upload_image', [
        'data_base64' => base64_encode(uploadImagePngBytes()),
        'composition_revision' => 2,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('stale_revision')
        ->and($result->error['current_composition_revision'])->toBe(3)
        ->and(SiteMedia::query()->where('site_id', $site->id)->count())->toBe(0)
        ->and($page->fresh()->published_revision_id)->toBe($publishedRevisionId)
        ->and($page->fresh()->draft_revision_id)->toBeNull();
});

it('a stale assignment base leaves neither a media row nor a stored object', function () {
    [$actor, $site, $page] = EditorSeeds::site();
    $publishedRevisionId = $page->published_revision_id;
    $cache = app(PublicPageCache::class);
    $cacheGeneration = $cache->generation($site);

    $result = EditorSeeds::run($actor, $site, 'upload_image', [
        'data_base64' => base64_encode(uploadImagePngBytes()),
        'composition_revision' => 0,
        'page_id' => $page->id,
        'stored_index' => 0,
        'field_path' => 'background_image',
        'revision_base' => 999_999,
        'structure_epoch' => $page->structure_epoch,
    ]);

    $page->refresh();

    expect($result->ok)->toBeFalse()->and($result->error['code'])->toBe('stale_revision')
        ->and(SiteMedia::query()->where('site_id', $site->id)->count())->toBe(0)
        ->and(Storage::disk(config('filesystems.default'))->allFiles("site-media/{$site->id}"))->toBe([]);
});
