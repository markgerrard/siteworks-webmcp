<?php

namespace App\Services\Images;

use App\Exceptions\UnsupportedImageException;

/**
 * Downscales and re-encodes final page images (hero/intro/band) to WebP.
 * The 4MB-PNG problem: generated outputs stored verbatim made
 * hero payloads ~10x heavier than needed. Raw pipeline intermediates are
 * NOT run through this — only images the public site serves.
 */
class ImageOptimiserService
{
    public const MAX_DECODE_WIDTH = 12_000;

    public const MAX_DECODE_HEIGHT = 12_000;

    public const MAX_DECODE_PIXELS = 40_000_000;

    /**
     * Ping-derived frame bound. Identical-frame animated WebP can
     * under-report getNumberImages(); RESOURCETYPE_LISTLENGTH is an
     * extra cap when this Imagick build exposes it (3.8.1 does).
     */
    public const MAX_DECODE_FRAMES = 200;

    public const DISK_LIMIT_BYTES = 256 * 1024 * 1024;

    public const MEMORY_LIMIT_BYTES = 256 * 1024 * 1024;

    public const MAP_LIMIT_BYTES = 512 * 1024 * 1024;

    public const AREA_LIMIT_PIXELS = 40_000_000;

    public const TIME_LIMIT_SECONDS = 20;

    /** @var list<string> */
    private const ALLOWED_FORMATS = ['JPEG', 'PNG', 'WEBP'];

    public function __construct(
        private readonly int $maxDecodeWidth = self::MAX_DECODE_WIDTH,
        private readonly int $maxDecodeHeight = self::MAX_DECODE_HEIGHT,
        private readonly int $maxDecodePixels = self::MAX_DECODE_PIXELS,
        private readonly int $maxDecodeFrames = self::MAX_DECODE_FRAMES,
        private readonly int $diskLimitBytes = self::DISK_LIMIT_BYTES,
        private readonly int $memoryLimitBytes = self::MEMORY_LIMIT_BYTES,
        private readonly int $mapLimitBytes = self::MAP_LIMIT_BYTES,
        private readonly int $areaLimitPixels = self::AREA_LIMIT_PIXELS,
        private readonly int $timeLimitSeconds = self::TIME_LIMIT_SECONDS,
    ) {}

    /**
     * @return array{bytes: string, extension: string, width: int, height: int}
     */
    public function optimise(string $bytes, int $maxWidth = 2560, int $quality = 82): array
    {
        $this->assertAllowedMagicBytes($bytes);

        $img = new \Imagick;
        $prior = [];

        try {
            $this->lowerResourceLimits($img, $prior);
            $this->preflight($bytes);

            try {
                $img->readImageBlob($bytes);
            } catch (\ImagickException $e) {
                throw new UnsupportedImageException('Image could not be decoded', previous: $e);
            }

            $format = strtoupper($img->getImageFormat());
            if (! in_array($format, self::ALLOWED_FORMATS, true)) {
                throw new UnsupportedImageException("Unsupported image format {$format}");
            }

            if ($img->getNumberImages() > 1) {
                $coalesced = $img->coalesceImages();
                $img->clear();
                $coalesced->setIteratorIndex(0);
                $img = $coalesced->getImage();
                $coalesced->clear();
            }

            // Normalise EXIF orientation before stripping metadata.
            // Imagick 3.8 exposes autoOrient()/autoOrientate(), not the
            // MagickWand C symbol autoOrientImage(). Never skip silently.
            if (method_exists($img, 'autoOrient')) {
                $img->autoOrient();
            } elseif (method_exists($img, 'autoOrientate')) {
                $img->autoOrientate();
            } else {
                throw new UnsupportedImageException('Image orientation could not be normalised');
            }
            $img->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);

            $decodedWidth = $img->getImageWidth();
            $decodedHeight = $img->getImageHeight();
            $decodedPixels = $decodedWidth * $decodedHeight;
            if ($decodedWidth > $this->maxDecodeWidth
                || $decodedHeight > $this->maxDecodeHeight
                || $decodedPixels > $this->maxDecodePixels) {
                throw new UnsupportedImageException(
                    $this->exceedsDecodeLimitsMessage($decodedWidth, $decodedHeight, $decodedPixels, $img->getNumberImages()),
                );
            }

            if ($img->getImageWidth() > $maxWidth) {
                $img->scaleImage($maxWidth, 0);
            }

            $img->setImageFormat('webp');
            $img->setImageCompressionQuality($quality);
            $img->stripImage();

            return [
                'bytes' => $img->getImageBlob(),
                'extension' => 'webp',
                'width' => $img->getImageWidth(),
                'height' => $img->getImageHeight(),
            ];
        } catch (UnsupportedImageException $e) {
            throw $e;
        } catch (\ImagickException $e) {
            throw new UnsupportedImageException('Image could not be processed', previous: $e);
        } finally {
            try {
                $this->restoreResourceLimits($img, $prior);
            } finally {
                $img->clear();
            }
        }
    }

    private function assertAllowedMagicBytes(string $bytes): void
    {
        $magic = substr($bytes, 0, 12);
        $ok = str_starts_with($magic, "\xFF\xD8\xFF")
            || str_starts_with($magic, "\x89PNG\r\n\x1A\n")
            || (str_starts_with($magic, 'RIFF') && substr($magic, 8, 4) === 'WEBP');

        if (! $ok) {
            throw new UnsupportedImageException('Unsupported image format');
        }
    }

    private function preflight(string $bytes): void
    {
        $ping = new \Imagick;

        try {
            $ping->pingImageBlob($bytes);
        } catch (\ImagickException $e) {
            $ping->clear();
            $header = $this->headerDimensions($bytes);
            if ($header !== null && $this->exceedsDecodeLimits($header['width'], $header['height'], 1)) {
                throw new UnsupportedImageException(
                    $this->exceedsDecodeLimitsMessage($header['width'], $header['height'], $header['width'] * $header['height'], 1),
                    previous: $e,
                );
            }
            throw new UnsupportedImageException('Image could not be decoded', previous: $e);
        }

        try {
            $width = $ping->getImageWidth();
            $height = $ping->getImageHeight();
            $frames = $ping->getNumberImages();
            $format = strtoupper($ping->getImageFormat());
        } finally {
            $ping->clear();
        }

        if (! in_array($format, self::ALLOWED_FORMATS, true)) {
            throw new UnsupportedImageException("Unsupported image format {$format}");
        }

        $pixels = $width * $height;
        if ($this->exceedsDecodeLimits($width, $height, $frames)) {
            throw new UnsupportedImageException(
                $this->exceedsDecodeLimitsMessage($width, $height, $pixels, $frames),
            );
        }
    }

    /**
     * Lower process-global Imagick resource limits for this call only.
     * ImageMagick silently discards attempts to raise a limit above the
     * policy/default ceiling, so we never request a value above the
     * currently-in-force limit. TIME is the exception: a prior value of
     * 0 is ImageMagick's unlimited sentinel, not a floor, so the ceiling
     * is installed and the 0 is restored afterwards. LISTLENGTH uses a
     * negative sentinel (PHP_INT_MIN) for unlimited — same treatment.
     *
     * @param  array<int, int|float>  $prior
     * @return array<int, int|float>
     */
    private function lowerResourceLimits(\Imagick $img, array &$prior = []): array
    {
        $requested = [
            \Imagick::RESOURCETYPE_MEMORY => $this->memoryLimitBytes,
            \Imagick::RESOURCETYPE_MAP => $this->mapLimitBytes,
            \Imagick::RESOURCETYPE_AREA => $this->areaLimitPixels,
            \Imagick::RESOURCETYPE_DISK => $this->diskLimitBytes,
            \Imagick::RESOURCETYPE_WIDTH => $this->maxDecodeWidth,
            \Imagick::RESOURCETYPE_HEIGHT => $this->maxDecodeHeight,
        ];

        if (defined(\Imagick::class.'::RESOURCETYPE_TIME')) {
            $requested[\Imagick::RESOURCETYPE_TIME] = $this->timeLimitSeconds;
        }

        // Identical-frame animated WebP can under-report ping's frame
        // count; cap RESOURCETYPE_LISTLENGTH (Imagick 3.8+) when present.
        // RESOURCETYPE_LIST_LENGTH is not a real constant on 3.8.1.
        if (defined(\Imagick::class.'::RESOURCETYPE_LISTLENGTH')) {
            $requested[\Imagick::RESOURCETYPE_LISTLENGTH] = $this->maxDecodeFrames;
        }

        foreach ($requested as $type => $value) {
            $prior[$type] = $img->getResourceLimit($type);
            $timeIsUnlimited = defined(\Imagick::class.'::RESOURCETYPE_TIME')
                && $type === \Imagick::RESOURCETYPE_TIME
                && (float) $prior[$type] === 0.0;
            $listLengthIsUnlimited = defined(\Imagick::class.'::RESOURCETYPE_LISTLENGTH')
                && $type === \Imagick::RESOURCETYPE_LISTLENGTH
                && (int) $prior[$type] < 0;
            if ($timeIsUnlimited || $listLengthIsUnlimited || $value < $prior[$type]) {
                $img->setResourceLimit($type, $value);
            }
        }

        return $prior;
    }

    /**
     * @return array{width: int, height: int}|null
     */
    private function headerDimensions(string $bytes): ?array
    {
        $info = @getimagesizefromstring($bytes);
        if (! is_array($info)) {
            return null;
        }

        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($width < 1 || $height < 1) {
            return null;
        }

        return ['width' => $width, 'height' => $height];
    }

    private function exceedsDecodeLimits(int $width, int $height, int $frames): bool
    {
        return $width > $this->maxDecodeWidth
            || $height > $this->maxDecodeHeight
            || ($width * $height) > $this->maxDecodePixels
            || $frames > $this->maxDecodeFrames;
    }

    private function exceedsDecodeLimitsMessage(int $width, int $height, int $pixels, int $frames): string
    {
        return "Image exceeds decode limits ({$width}×{$height}, {$pixels} px, {$frames} frames)";
    }

    /**
     * @param  array<int, int|float>  $prior
     */
    private function restoreResourceLimits(\Imagick $img, array $prior): void
    {
        foreach ($prior as $type => $value) {
            $img->setResourceLimit($type, $value);
        }
    }
}
