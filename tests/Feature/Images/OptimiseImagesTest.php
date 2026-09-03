<?php

namespace Tests\Feature\Images;

use App\Models\HeroVersion;
use App\Models\Site;
use App\Services\Images\ImageOptimiserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OptimiseImagesTest extends TestCase
{
    use RefreshDatabase;

    private function pngBlob(int $width = 3000, int $height = 1500): string
    {
        $img = new \Imagick();
        $img->newImage($width, $height, 'red');
        // Noise defeats PNG's flat-colour compression so size deltas are real.
        $img->addNoiseImage(\Imagick::NOISE_RANDOM);
        $img->setImageFormat('png');

        return $img->getImageBlob();
    }

    public function test_optimiser_downscales_and_encodes_webp(): void
    {
        $out = app(ImageOptimiserService::class)->optimise($this->pngBlob(), maxWidth: 2560);

        $this->assertSame('webp', $out['extension']);
        $this->assertSame(2560, $out['width']);
        $this->assertSame('RIFF', substr($out['bytes'], 0, 4));
        $this->assertSame('WEBP', substr($out['bytes'], 8, 4));
    }

    public function test_optimiser_never_upscales(): void
    {
        $out = app(ImageOptimiserService::class)->optimise($this->pngBlob(800, 400));

        $this->assertSame(800, $out['width']);
    }

    public function test_backfill_converts_rows_and_keeps_originals(): void
    {
        Storage::fake('s3');
        $disk = Storage::disk('s3');
        $disk->put('previews/9/hero-test.png', $this->pngBlob());
        $site = Site::factory()->create();
        $row = HeroVersion::create([
            'site_id' => $site->id, 'slot' => 'hero', 'page_type' => 'home',
            'url' => $disk->url('previews/9/hero-test.png'),
            'watermark_url' => null, 'is_active' => true,
        ]);

        $this->artisan('images:optimise', ['--min-bytes' => 1000])->assertSuccessful();

        $row->refresh();
        $this->assertStringEndsWith('hero-test.webp', $row->url);
        $disk->assertExists('previews/9/hero-test.webp');
        $disk->assertExists('previews/9/hero-test.png');
        $this->assertSame('RIFF', substr($disk->get('previews/9/hero-test.webp'), 0, 4));
    }

    public function test_dry_run_writes_nothing(): void
    {
        Storage::fake('s3');
        $disk = Storage::disk('s3');
        $disk->put('previews/9/hero-dry.png', $this->pngBlob());
        $site = Site::factory()->create();
        $row = HeroVersion::create([
            'site_id' => $site->id, 'slot' => 'hero', 'page_type' => 'home',
            'url' => $disk->url('previews/9/hero-dry.png'),
            'watermark_url' => null, 'is_active' => true,
        ]);

        $this->artisan('images:optimise', ['--dry-run' => true, '--min-bytes' => 1000])->assertSuccessful();

        $row->refresh();
        $this->assertStringEndsWith('.png', $row->url);
        $disk->assertMissing('previews/9/hero-dry.webp');
    }

    public function test_small_files_are_skipped(): void
    {
        Storage::fake('s3');
        $disk = Storage::disk('s3');
        $disk->put('previews/9/hero-small.png', $this->pngBlob(200, 100));
        $site = Site::factory()->create();
        $row = HeroVersion::create([
            'site_id' => $site->id, 'slot' => 'hero', 'page_type' => 'home',
            'url' => $disk->url('previews/9/hero-small.png'),
            'watermark_url' => null, 'is_active' => true,
        ]);

        $this->artisan('images:optimise')->assertSuccessful();

        $row->refresh();
        $this->assertStringEndsWith('.png', $row->url);
    }
}
