<?php

use App\Exceptions\UnsupportedImageException;
use App\Services\Images\ImageOptimiserService;

function makeImage(string $format, int $w = 10, int $h = 10): string
{
    $img = new \Imagick;
    $img->newImage($w, $h, new \ImagickPixel('red'));
    $img->setImageFormat($format);

    $bytes = $img->getImageBlob();
    $img->clear();

    return $bytes;
}

function makeAnimated(string $format, int $frames = 3, int $w = 8, int $h = 8): string
{
    $colors = ['blue', 'red', 'green', 'yellow', 'black', 'white', 'purple', 'orange'];
    $anim = new \Imagick;
    foreach (range(0, $frames - 1) as $i) {
        $frame = new \Imagick;
        $frame->newImage($w, $h, new \ImagickPixel($colors[$i % count($colors)]));
        $frame->setImageFormat($format);
        $frame->setImageDelay(10);
        $anim->addImage($frame);
        $frame->clear();
    }
    $anim->resetIterator();
    $anim->setImageFormat($format);
    $bytes = $anim->getImagesBlob();
    $anim->clear();

    return $bytes;
}

function webpDelegateAvailable(): bool
{
    return in_array('WEBP', (new \Imagick)->queryFormats('WEBP'), true);
}

test('animated webp is flattened to one frame', function () {
    $bytes = makeAnimated('webp', 3, 8, 8);
    $out = app(ImageOptimiserService::class)->optimise($bytes);
    $check = new \Imagick;
    $check->readImageBlob($out['bytes']);
    expect($check->getNumberImages())->toBe(1)->and($out['extension'])->toBe('webp');
    $check->clear();
})->skip(fn () => ! webpDelegateAvailable(), 'no webp delegate');

test('gif is rejected by the post-decode allowlist', function () {
    app(ImageOptimiserService::class)->optimise(makeImage('gif'));
})->throws(UnsupportedImageException::class);

test('pdf bytes are rejected even with an image extension claim', function () {
    $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>";
    app(ImageOptimiserService::class)->optimise($pdf);
})->throws(UnsupportedImageException::class);

test('decoded format must be jpeg png or webp', function () {
    app(ImageOptimiserService::class)->optimise(makeImage('bmp'));
})->throws(UnsupportedImageException::class);

test('a decompression bomb is rejected without decoding it', function () {
    $bombImage = new \Imagick;
    $bombImage->newImage(200, 120, new \ImagickPixel('white'));
    $bombImage->setImageFormat('png');
    $bomb = $bombImage->getImageBlob();
    $bombImage->clear();

    expect(strlen($bomb))->toBeLessThan(1_000_000);

    $service = new ImageOptimiserService(
        maxDecodeWidth: 64,
        maxDecodeHeight: 64,
        maxDecodePixels: 4_000,
    );

    $t = microtime(true);
    expect(fn () => $service->optimise($bomb))
        ->toThrow(UnsupportedImageException::class, '200×120');
    expect(microtime(true) - $t)->toBeLessThan(1.0);
});

test('default width ceiling rejects a 12001-wide png before full decode', function () {
    $img = new \Imagick;
    $img->newImage(12_001, 8, new \ImagickPixel('white'));
    $img->setImageFormat('png');
    $bomb = $img->getImageBlob();
    $img->clear();

    $t = microtime(true);
    expect(fn () => app(ImageOptimiserService::class)->optimise($bomb))
        ->toThrow(UnsupportedImageException::class);
    expect(microtime(true) - $t)->toBeLessThan(1.0);
});

test('the resource limits requested are the limits actually in force', function () {
    $img = new \Imagick;
    $areaDefault = $img->getResourceLimit(\Imagick::RESOURCETYPE_AREA);
    $diskDefault = $img->getResourceLimit(\Imagick::RESOURCETYPE_DISK);
    $widthDefault = $img->getResourceLimit(\Imagick::RESOURCETYPE_WIDTH);
    $memoryDefault = $img->getResourceLimit(\Imagick::RESOURCETYPE_MEMORY);
    $timeDefault = $img->getResourceLimit(\Imagick::RESOURCETYPE_TIME);

    expect(ImageOptimiserService::AREA_LIMIT_PIXELS)->toBeLessThanOrEqual($areaDefault)
        ->and(ImageOptimiserService::DISK_LIMIT_BYTES)->toBeLessThanOrEqual($diskDefault)
        ->and(ImageOptimiserService::MAX_DECODE_WIDTH)->toBeLessThanOrEqual($widthDefault)
        ->and(ImageOptimiserService::MEMORY_LIMIT_BYTES)->toBeLessThanOrEqual($memoryDefault)
        ->and((int) $timeDefault)->toBe(0);

    $service = new ImageOptimiserService;
    $method = new ReflectionMethod($service, 'lowerResourceLimits');
    $prior = $method->invoke($service, $img);
    $restore = new ReflectionMethod($service, 'restoreResourceLimits');

    try {
        expect((int) $img->getResourceLimit(\Imagick::RESOURCETYPE_AREA))->toBe(ImageOptimiserService::AREA_LIMIT_PIXELS)
            ->and((int) $img->getResourceLimit(\Imagick::RESOURCETYPE_DISK))->toBe(ImageOptimiserService::DISK_LIMIT_BYTES)
            ->and((int) $img->getResourceLimit(\Imagick::RESOURCETYPE_WIDTH))->toBe(ImageOptimiserService::MAX_DECODE_WIDTH)
            ->and((int) $img->getResourceLimit(\Imagick::RESOURCETYPE_MEMORY))->toBe(ImageOptimiserService::MEMORY_LIMIT_BYTES)
            ->and((int) $img->getResourceLimit(\Imagick::RESOURCETYPE_HEIGHT))->toBe(ImageOptimiserService::MAX_DECODE_HEIGHT)
            ->and((int) $img->getResourceLimit(\Imagick::RESOURCETYPE_TIME))->toBe(ImageOptimiserService::TIME_LIMIT_SECONDS)
            ->and((int) $img->getResourceLimit(\Imagick::RESOURCETYPE_LISTLENGTH))->toBe(ImageOptimiserService::MAX_DECODE_FRAMES);
    } finally {
        $restore->invoke($service, $img, $prior);
        expect((int) $img->getResourceLimit(\Imagick::RESOURCETYPE_TIME))->toBe(0);
        $img->clear();
    }
});

test('a frame-multiplication bomb is rejected before decode', function () {
    $service = new ImageOptimiserService(maxDecodeFrames: 2);
    $bytes = makeAnimated('webp', 5, 16, 16);

    $t = microtime(true);
    expect(fn () => $service->optimise($bytes))
        ->toThrow(UnsupportedImageException::class);
    expect(microtime(true) - $t)->toBeLessThan(1.0);
})->skip(fn () => ! webpDelegateAvailable(), 'no webp delegate');

test('optimise does not leak its resource limits to other Imagick users', function () {
    $probe = new \Imagick;
    $beforeMemory = $probe->getResourceLimit(\Imagick::RESOURCETYPE_MEMORY);
    $beforeDisk = $probe->getResourceLimit(\Imagick::RESOURCETYPE_DISK);
    $beforeWidth = $probe->getResourceLimit(\Imagick::RESOURCETYPE_WIDTH);
    $beforeTime = $probe->getResourceLimit(\Imagick::RESOURCETYPE_TIME);
    $probe->clear();

    app(ImageOptimiserService::class)->optimise(makeImage('png'));

    $after = new \Imagick;
    expect($after->getResourceLimit(\Imagick::RESOURCETYPE_MEMORY))->toBe($beforeMemory)
        ->and($after->getResourceLimit(\Imagick::RESOURCETYPE_DISK))->toBe($beforeDisk)
        ->and($after->getResourceLimit(\Imagick::RESOURCETYPE_WIDTH))->toBe($beforeWidth)
        ->and((int) $after->getResourceLimit(\Imagick::RESOURCETYPE_TIME))->toBe((int) $beforeTime)
        ->and((int) $after->getResourceLimit(\Imagick::RESOURCETYPE_TIME))->toBe(0);
    $after->clear();
});

test('post-decode failures surface as UnsupportedImageException, not raw Imagick errors', function () {
    $service = new ImageOptimiserService(
        diskLimitBytes: 1,
        memoryLimitBytes: 1,
        mapLimitBytes: 1,
    );

    try {
        $service->optimise(makeImage('png', 2000, 2000));
        $this->fail('expected UnsupportedImageException');
    } catch (UnsupportedImageException $e) {
        expect($e->getMessage())->not->toContain('error/');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf(UnsupportedImageException::class);
    }
});

test('non-image bytes are rejected without invoking a decode delegate', function () {
    $t = microtime(true);
    expect(fn () => app(ImageOptimiserService::class)->optimise("%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj"))
        ->toThrow(UnsupportedImageException::class);
    expect(microtime(true) - $t)->toBeLessThan(0.05);
});

test('exif orientation is applied to the pixels before the tag is stripped', function () {
    $tiff = "II\x2a\x00".pack('V', 8).pack('v', 1)
        .pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', 6)."\x00\x00".pack('V', 0);
    $payload = "Exif\x00\x00".$tiff;
    $src = new \Imagick;
    $src->newPseudoImage(1200, 800, 'plasma:fractal');
    $src->setImageFormat('jpeg');
    $jpg = $src->getImageBlob();
    $src->clear();
    $jpg = substr($jpg, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpg, 2);

    $out = app(ImageOptimiserService::class)->optimise($jpg);

    expect($out['width'])->toBe(800)->and($out['height'])->toBe(1200);

    $check = new \Imagick;
    $check->readImageBlob($out['bytes']);
    expect($check->getImageWidth())->toBe(800)
        ->and($check->getImageHeight())->toBe(1200)
        ->and($check->getImageOrientation())->toBeIn([
            0,
            \Imagick::ORIENTATION_UNDEFINED,
            \Imagick::ORIENTATION_TOPLEFT,
        ]);
    $check->clear();
});

test('an injected width ceiling is in force during pingImageBlob', function () {
    $img = new \Imagick;
    $img->newImage(200, 120, new \ImagickPixel('white'));
    $img->setImageFormat('png');
    $bytes = $img->getImageBlob();
    $img->clear();

    $service = new ImageOptimiserService(maxDecodeWidth: 64);

    try {
        $service->optimise($bytes);
        $this->fail('expected UnsupportedImageException');
    } catch (UnsupportedImageException $e) {
        expect($e->getPrevious())->toBeInstanceOf(\ImagickException::class);
    }
});

test('resource limits are restored after a rejected bomb', function () {
    $bombImage = new \Imagick;
    $bombImage->newImage(200, 120, new \ImagickPixel('white'));
    $bombImage->setImageFormat('png');
    $bomb = $bombImage->getImageBlob();
    $bombImage->clear();

    $probe = new \Imagick;
    $beforeMemory = $probe->getResourceLimit(\Imagick::RESOURCETYPE_MEMORY);
    $beforeDisk = $probe->getResourceLimit(\Imagick::RESOURCETYPE_DISK);
    $beforeWidth = $probe->getResourceLimit(\Imagick::RESOURCETYPE_WIDTH);
    $beforeTime = $probe->getResourceLimit(\Imagick::RESOURCETYPE_TIME);
    $probe->clear();

    $service = new ImageOptimiserService(
        maxDecodeWidth: 64,
        maxDecodeHeight: 64,
        maxDecodePixels: 4_000,
    );

    expect(fn () => $service->optimise($bomb))
        ->toThrow(UnsupportedImageException::class);

    $after = new \Imagick;
    expect($after->getResourceLimit(\Imagick::RESOURCETYPE_MEMORY))->toBe($beforeMemory)
        ->and($after->getResourceLimit(\Imagick::RESOURCETYPE_DISK))->toBe($beforeDisk)
        ->and($after->getResourceLimit(\Imagick::RESOURCETYPE_WIDTH))->toBe($beforeWidth)
        ->and((int) $after->getResourceLimit(\Imagick::RESOURCETYPE_TIME))->toBe((int) $beforeTime);
    $after->clear();
});

test('the default pixel ceiling rejects a bomb that is under both dimension caps', function () {
    $bombImage = new \Imagick;
    $bombImage->newImage(11_000, 4_000, new \ImagickPixel('white'));
    $bombImage->setImageFormat('png');
    $bomb = $bombImage->getImageBlob();
    $bombImage->clear();

    expect(strlen($bomb))->toBeLessThan(1_000_000);

    $t = microtime(true);
    expect(fn () => app(ImageOptimiserService::class)->optimise($bomb))
        ->toThrow(UnsupportedImageException::class, 'Image exceeds decode limits');
    expect(microtime(true) - $t)->toBeLessThan(1.0);
});
