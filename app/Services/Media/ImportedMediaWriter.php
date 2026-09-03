<?php

namespace App\Services\Media;

use App\Models\ImportedMedia;
use App\Models\Site;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportedMediaWriter
{
    /**
     * Store a single imported Facebook photo, mirroring expiring fbcdn
     * bytes to Spaces before persisting the row.
     *
     * @param  array{url: string, fbid: string, bytes_b64: string, width?: ?int, height?: ?int, caption?: ?string, alt_text?: ?string, source_permalink?: string}  $photoPayload
     */
    public function store(Site $site, array $photoPayload): ImportedMedia
    {
        $fbid = (string) $photoPayload['fbid'];

        $existing = ImportedMedia::query()
            ->where('site_id', $site->id)
            ->where('source', 'facebook')
            ->where('external_id', $fbid)
            ->first();

        if ($existing) {
            return $existing;
        }

        $bytes = base64_decode((string) $photoPayload['bytes_b64'], true);
        if ($bytes === false) {
            throw new \InvalidArgumentException("Invalid base64 payload for Facebook photo {$fbid}");
        }

        $path = sprintf(
            'sites/%d/imported/facebook/%s-%s.jpg',
            $site->id,
            Str::slug($fbid),
            substr(sha1($bytes), 0, 10),
        );

        Storage::disk('s3')->put($path, $bytes, 'public');
        $url = Storage::disk('s3')->url($path);

        return ImportedMedia::create([
            'site_id' => $site->id,
            'source' => 'facebook',
            'external_id' => $fbid,
            'url' => $url,
            'width' => $photoPayload['width'] ?? null,
            'height' => $photoPayload['height'] ?? null,
            'caption' => $photoPayload['caption'] ?? null,
            'imported_at' => now(),
            'placement' => [
                'source_url' => $photoPayload['url'] ?? null,
                'source_permalink' => $photoPayload['source_permalink'] ?? null,
                'alt_text' => $photoPayload['alt_text'] ?? null,
            ],
        ]);
    }
}
