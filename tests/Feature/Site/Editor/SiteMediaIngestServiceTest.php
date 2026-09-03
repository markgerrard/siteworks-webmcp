<?php

use App\Exceptions\UnsupportedImageException;
use App\Models\Site;
use App\Models\SiteMedia;
use App\Services\Site\Editor\{ActorChannel, SiteMediaIngestService};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function pngBytes(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAQAAAAEAQMAAACTPww9AAAAIGNIUk0AAHomAACAhAAA+gAAAIDoAAB1MAAA6mAAADqYAAAXcJy6UTwAAAAGUExURf8AAP///0EdNBEAAAABYktHRAH/Ai3eAAAAB3RJTUUH6ggcBTkHx5/rUAAAAAtJREFUCNdjYIAAAAAIAAEvIN0xAAAAAElFTkSuQmCC');
}

beforeEach(fn () => Storage::fake(config('filesystems.default')));

it('ingests base64 png into an optimised webp site_media row with actor_channel', function () {
    $site = Site::factory()->create();
    $media = app(SiteMediaIngestService::class)->ingestBase64($site, 'data:image/png;base64,'.base64_encode(pngBytes()), ActorChannel::Webmcp);
    expect($media)->toBeInstanceOf(SiteMedia::class)
        ->and($media->source)->toBe('agent_uploaded')->and($media->actor_channel)->toBe('webmcp')
        ->and($media->mime_type)->toBe('image/webp')->and($media->s3_key)->toEndWith('.webp')
        ->and($media->url)->toStartWith('http');
    Storage::disk(config('filesystems.default'))->assertExists($media->s3_key);
    $stored = Storage::disk(config('filesystems.default'))->get($media->s3_key);
    expect($stored)->not->toBe(pngBytes())            // never the original bytes
        ->and(substr($stored, 0, 4))->toBe('RIFF');  // re-encoded webp container
});

it('rejects gif and non-base64 bodies', function () {
    $site = Site::factory()->create();
    $svc = app(SiteMediaIngestService::class);
    expect(fn () => $svc->ingestBase64($site, base64_encode("GIF89a\x01\x00\x01\x00\x00\xff\x00,"), ActorChannel::Mcp))->toThrow(UnsupportedImageException::class)
        ->and(fn () => $svc->ingestBase64($site, '%%%not-base64%%%', ActorChannel::Mcp))->toThrow(UnsupportedImageException::class);
});

it('rejects oversized encoded bodies before decoding', function () {
    $site = Site::factory()->create();
    $huge = str_repeat('A', intdiv(5 * 1024 * 1024 * 4, 3) + 1000);
    app(SiteMediaIngestService::class)->ingestBase64($site, $huge, ActorChannel::Mcp);
})->throws(UnsupportedImageException::class);

it('removes the stored object when the site_media row cannot be created', function () {
    $site = Site::factory()->create();
    SiteMedia::creating(fn () => throw new \RuntimeException('forced'));
    try { app(SiteMediaIngestService::class)->ingestBase64($site, base64_encode(pngBytes()), ActorChannel::Mcp); } catch (\RuntimeException) {}
    expect(Storage::disk(config('filesystems.default'))->allFiles("site-media/{$site->id}"))->toBe([])
        ->and(SiteMedia::query()->where('site_id', $site->id)->count())->toBe(0);
});

it('keeps the legacy upload response and adds id', function () {
    $user = \App\Models\User::factory()->staff(\App\Enums\AgentRole::Admin)->create();
    $site = Site::factory()->create();
    $body = $this->actingAs($user)
        ->post(route('site.admin.media-upload', $site, false), ['file' => UploadedFile::fake()->createWithContent('a.png', pngBytes())])
        ->assertOk()->assertJsonStructure(['path', 'url', 'id'])->json();
    expect($body['url'])->toStartWith('http');
    expect(SiteMedia::query()->where('site_id', $site->id)->where('source', 'upload')->where('actor_channel', 'ui')->count())->toBe(1);
});
