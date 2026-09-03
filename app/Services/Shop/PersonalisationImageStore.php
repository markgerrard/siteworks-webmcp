<?php

namespace App\Services\Shop;

use App\Models\Shop\Cart;
use App\Models\Shop\CartItem;
use App\Models\Shop\OrderItem;
use App\Models\Site;
use App\Models\SiteEnquiry;
use App\Support\MediaStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PersonalisationImageStore
{
    /**
     * Customer artwork is private media: it is only ever served through the
     * signed, authorised personalisation route, never by a public URL.
     */
    public function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return MediaStorage::privateDisk();
    }

    /**
     * @return array{path: string, name: string, bytes: int, mime: string}
     */
    public function store(Site $site, string $owner, UploadedFile $file): array
    {
        $this->assertSize($file);
        $encoded = $this->reencode($file);
        $uuid = (string) Str::uuid();
        $path = 'sites/'.$site->id.'/personalisation/'.$owner.'/'.$uuid.'.'.$encoded['ext'];
        $this->disk()->put($path, $encoded['contents'], 'private');

        return [
            'path' => $path,
            'name' => $this->safeName($file->getClientOriginalName(), $encoded['ext']),
            'bytes' => strlen($encoded['contents']),
            'mime' => $encoded['mime'],
        ];
    }

    /**
     * @param  list<array{path: string, name?: string, bytes?: int, mime?: string}>  $files
     */
    public function delete(array $files): void
    {
        foreach ($files as $file) {
            $path = $file['path'] ?? null;
            if (is_string($path) && $path !== '') {
                $this->disk()->delete($path);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $personalisation
     * @return array<string, mixed>
     */
    public function copyToOwner(array $personalisation, Site $site, string $owner): array
    {
        $created = [];

        try {
            return LinePersonalisation::relocateImages($personalisation, function (string $path) use ($site, $owner, &$created): string {
                if (! $this->disk()->exists($path)) {
                    throw ValidationException::withMessages([
                        'personalisation' => ['A personalisation image is no longer available.'],
                    ]);
                }

                $ext = pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg';
                $dest = 'sites/'.$site->id.'/personalisation/'.$owner.'/'.Str::uuid().'.'.$ext;
                if (! $this->disk()->put($dest, $this->disk()->get($path), 'private')) {
                    throw ValidationException::withMessages([
                        'personalisation' => ['A personalisation image could not be copied.'],
                    ]);
                }
                $created[] = ['path' => $dest];

                return $dest;
            });
        } catch (\Throwable $e) {
            $this->delete($created);
            throw $e;
        }
    }

    public function signedUrl(Site $site, string $path, int $ttlSeconds, string $audience = 'session'): string
    {
        return URL::temporarySignedRoute(
            'shop.personalisation.show',
            now()->addSeconds($ttlSeconds),
            [
                'site' => $site->id,
                'path' => $path,
                'audience' => $audience,
            ],
        );
    }

    public function ownerPrefix(string $kind, int $id): string
    {
        return $kind.'-'.$id;
    }

    public function pruneOrphans(?\DateTimeInterface $cutoff = null, bool $dryRun = false): int
    {
        $cutoff ??= now()->subDays((int) config('shop_input_presets.orphan_days', 14));
        $referenced = $this->referencedPaths();
        $deleted = 0;

        foreach ($this->disk()->allFiles('sites') as $path) {
            if (! preg_match('#^sites/(\d+)/personalisation/cart-(\d+)/#', $path, $matches)) {
                continue;
            }
            if (isset($referenced[$path])) {
                continue;
            }

            $cart = Cart::query()->find((int) $matches[2]);
            if ($cart?->updated_at !== null && $cart->updated_at >= $cutoff) {
                continue;
            }

            if ($cart === null && $this->disk()->lastModified($path) >= $cutoff->getTimestamp()) {
                continue;
            }

            if (! $dryRun) {
                $this->disk()->delete($path);
            }
            $deleted++;
        }

        return $deleted;
    }

    /**
     * @return array<string, true>
     */
    private function referencedPaths(): array
    {
        $paths = [];
        foreach (CartItem::query()->whereNotNull('personalisation')->cursor() as $item) {
            foreach (LinePersonalisation::imageFiles($item->personalisation) as $file) {
                $paths[$file['path']] = true;
            }
        }
        foreach (OrderItem::query()->whereNotNull('personalisation')->cursor() as $item) {
            foreach (LinePersonalisation::imageFiles($item->personalisation) as $file) {
                $paths[$file['path']] = true;
            }
        }
        foreach (SiteEnquiry::query()->whereNotNull('payload')->cursor() as $enquiry) {
            $lines = $enquiry->payload['lines'] ?? [];
            if (! is_array($lines)) {
                continue;
            }
            foreach ($lines as $line) {
                if (! is_array($line)) {
                    continue;
                }
                foreach (LinePersonalisation::imageFiles($line['personalisation'] ?? null) as $file) {
                    $paths[$file['path']] = true;
                }
            }
        }

        return $paths;
    }

    private function assertSize(UploadedFile $file): void
    {
        $max = (int) config('shop_input_presets.image_max_bytes', 8 * 1024 * 1024);
        if ($file->getSize() > $max) {
            throw ValidationException::withMessages([
                'personalisation' => ['Each image must be 8 MB or smaller.'],
            ]);
        }
    }

    /**
     * @return array{contents: string, mime: string, ext: string}
     */
    private function reencode(UploadedFile $file): array
    {
        $realPath = $file->getRealPath();
        if ($realPath === false) {
            throw ValidationException::withMessages([
                'personalisation' => ['That file could not be read.'],
            ]);
        }

        $raw = file_get_contents($realPath);
        if ($raw === false || $raw === '') {
            throw ValidationException::withMessages([
                'personalisation' => ['That file could not be read.'],
            ]);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($raw) ?: '';
        $allowed = config('shop_input_presets.image_mimes', []);
        if (! in_array($detected, $allowed, true)) {
            throw ValidationException::withMessages([
                'personalisation' => ['Images must be JPEG, PNG, WebP or HEIC.'],
            ]);
        }

        if (str_contains($raw, '<?php') || str_starts_with(ltrim($raw), '<?')) {
            throw ValidationException::withMessages([
                'personalisation' => ['Images must be JPEG, PNG, WebP or HEIC.'],
            ]);
        }

        if (in_array($detected, ['image/heic', 'image/heif'], true)) {
            return $this->reencodeHeic($raw);
        }

        $info = @getimagesizefromstring($raw);
        if ($info === false) {
            throw ValidationException::withMessages([
                'personalisation' => ['Images must be JPEG, PNG, WebP or HEIC.'],
            ]);
        }

        $maxDim = (int) config('shop_input_presets.image_max_dimension', 6000);
        if ($info[0] > $maxDim || $info[1] > $maxDim) {
            throw ValidationException::withMessages([
                'personalisation' => ['Images must be 6000 pixels or smaller on each side.'],
            ]);
        }

        $ext = match ($detected) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        if (function_exists('imagecreatefromstring')) {
            $image = @imagecreatefromstring($raw);
            if ($image === false) {
                throw ValidationException::withMessages([
                    'personalisation' => ['Images must be JPEG, PNG, WebP or HEIC.'],
                ]);
            }

            try {
                ob_start();
                if ($detected === 'image/png') {
                    imagesavealpha($image, true);
                    imagepng($image);
                } elseif ($detected === 'image/webp' && function_exists('imagewebp')) {
                    imagewebp($image, null, 80);
                } else {
                    imagejpeg($image, null, 85);
                    $detected = 'image/jpeg';
                    $ext = 'jpg';
                }
                $contents = (string) ob_get_clean();
            } finally {
                imagedestroy($image);
            }

            return ['contents' => $contents, 'mime' => $detected === 'image/png' ? 'image/png' : ($detected === 'image/webp' ? 'image/webp' : 'image/jpeg'), 'ext' => $ext];
        }

        if (in_array($detected, ['image/png', 'image/webp'], true)) {
            if (! class_exists(\Imagick::class)) {
                throw ValidationException::withMessages([
                    'personalisation' => ['PNG and WebP uploads are not supported on this server.'],
                ]);
            }

            return $this->reencodeWithImagick($raw, $detected);
        }

        // JPEG: always re-encode when Imagick exists (drops COM/APP14 and any trailing polyglot bytes);
        // the marker walker is only the last resort on a runtime with neither GD nor Imagick.
        if (class_exists(\Imagick::class)) {
            return $this->reencodeWithImagick($raw, 'image/jpeg');
        }

        return [
            'contents' => $this->stripJpegMetadata($raw),
            'mime' => 'image/jpeg',
            'ext' => 'jpg',
        ];
    }

    /**
     * @return array{contents: string, mime: string, ext: string}
     */
    private function reencodeHeic(string $raw): array
    {
        if (! class_exists(\Imagick::class)) {
            throw ValidationException::withMessages([
                'personalisation' => ['HEIC images are not supported on this server.'],
            ]);
        }

        $image = new \Imagick;
        $image->readImageBlob($raw);
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        $maxDim = (int) config('shop_input_presets.image_max_dimension', 6000);
        if ($width > $maxDim || $height > $maxDim) {
            $image->clear();
            throw ValidationException::withMessages([
                'personalisation' => ['Images must be 6000 pixels or smaller on each side.'],
            ]);
        }
        $image->setImageFormat('jpeg');
        $image->setImageCompressionQuality(85);
        $image->stripImage();
        $contents = $image->getImageBlob();
        $image->clear();

        return ['contents' => $contents, 'mime' => 'image/jpeg', 'ext' => 'jpg'];
    }

    /**
     * @return array{contents: string, mime: string, ext: string}
     */
    private function reencodeWithImagick(string $raw, string $detected): array
    {
        $image = new \Imagick;

        try {
            $image->readImageBlob($raw);
            $image->setIteratorIndex(0);
            $image->stripImage();
            $format = match ($detected) { 'image/png' => 'png', 'image/webp' => 'webp', default => 'jpeg' };
            $image->setImageFormat($format);
            if ($format === 'webp') {
                $image->setImageCompressionQuality(80);
            } elseif ($format === 'jpeg') {
                $image->setImageCompressionQuality(85);
            }
            $contents = $image->getImageBlob();
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'personalisation' => ['Images must be JPEG, PNG, WebP or HEIC.'],
            ]);
        } finally {
            $image->clear();
            $image->destroy();
        }

        return [
            'contents' => $contents,
            'mime' => in_array($detected, ['image/png', 'image/webp'], true) ? $detected : 'image/jpeg',
            'ext' => match ($detected) { 'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg' },
        ];
    }

    private function stripJpegMetadata(string $raw): string
    {
        if (! str_starts_with($raw, "\xFF\xD8")) {
            return $raw;
        }

        $out = "\xFF\xD8";
        $offset = 2;
        $length = strlen($raw);
        while ($offset < $length - 1) {
            if ($raw[$offset] !== "\xFF") {
                $out .= substr($raw, $offset);
                break;
            }
            $marker = $raw[$offset + 1];
            if ($marker === "\xDA") {
                $out .= substr($raw, $offset);
                break;
            }
            if ($marker === "\xD9") {
                $out .= "\xFF\xD9";
                break;
            }
            $size = unpack('n', substr($raw, $offset + 2, 2))[1] ?? 0;
            $segment = substr($raw, $offset, $size + 2);
            $offset += $size + 2;
            if (in_array($marker, ["\xE1", "\xE2", "\xED"], true)) {
                continue;
            }
            $out .= $segment;
        }

        return $out;
    }

    private function safeName(string $original, string $ext): string
    {
        $base = pathinfo($original, PATHINFO_FILENAME);
        $base = Str::slug($base);
        if ($base === '') {
            $base = 'image';
        }

        return $base.'.'.$ext;
    }
}
