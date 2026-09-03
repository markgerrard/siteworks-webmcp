<?php

namespace App\Services\Site\Editor;

use App\Exceptions\UnsupportedImageException;
use App\Models\Site;
use App\Models\SiteMedia;
use App\Services\Images\ImageOptimiserService;
use App\Support\MediaStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class SiteMediaIngestService
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(private readonly ImageOptimiserService $optimiser) {}

    public function ingestUpload(
        Site $site,
        UploadedFile $file,
        ActorChannel $channel,
        string $source = 'upload',
    ): SiteMedia {
        $bytes = file_get_contents($file->getRealPath());

        if ($bytes === false) {
            throw new RuntimeException('Uploaded image could not be read.');
        }

        return $this->ingestBytes($site, $bytes, $channel, $source);
    }

    public function ingestBase64(
        Site $site,
        string $dataBase64,
        ActorChannel $channel,
        string $source = 'agent_uploaded',
    ): SiteMedia {
        if (strlen($dataBase64) > self::MAX_BYTES * 4 / 3 + 4) {
            throw new UnsupportedImageException('too large');
        }

        $encoded = preg_replace('#^data:image/[^;,]+;base64,#i', '', $dataBase64);
        $bytes = base64_decode($encoded, strict: true);

        if ($bytes === false) {
            throw new UnsupportedImageException('invalid base64');
        }

        return $this->ingestBytes($site, $bytes, $channel, $source);
    }

    public function ingestBytes(
        Site $site,
        string $bytes,
        ActorChannel $channel,
        string $source,
    ): SiteMedia {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new UnsupportedImageException('too large');
        }

        $optimised = $this->optimiser->optimise($bytes, maxWidth: 2560);
        $disk = MediaStorage::disk();
        $path = "site-media/{$site->id}/".Str::uuid().'.webp';

        if (! $disk->put($path, $optimised['bytes'], 'public')) {
            throw new RuntimeException('Optimised site media could not be stored.');
        }

        $diskUrl = $disk->url($path);
        $url = preg_match('#^https?://#i', $diskUrl) ? $diskUrl : url($diskUrl);

        try {
            return SiteMedia::create([
                'site_id' => $site->id,
                'source' => $source,
                'actor_channel' => $channel->value,
                'url' => $url,
                's3_key' => $path,
                'mime_type' => 'image/webp',
                'metadata' => [
                    'width' => $optimised['width'],
                    'height' => $optimised['height'],
                    'sanitised' => true,
                ],
            ]);
        } catch (Throwable $exception) {
            $disk->delete($path);

            throw $exception;
        }
    }
}
