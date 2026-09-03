<?php

use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Models\Site;
use App\Services\Site\BrandImageService;
use App\Services\Site\PublicPageCache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use AuthorizesSiteAccess;
    use WithFileUploads;

    #[Locked]
    public int $siteId;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $shareImageUpload = null;

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        $this->authorizedSite();
    }

    protected function authorizedSite(): Site
    {
        $site = $this->findAuthorizedSite();
        abort_unless($site, 403);

        return $site;
    }

    public function regenerateShareImage(): void
    {
        $site = $this->authorizedSite();
        $brandImages = app(BrandImageService::class);
        $ogUrl = $brandImages->generateOgImage($site);
        $squareUrl = $brandImages->generateOgSquareImage($site);
        if ($ogUrl === null) {
            session()->flash('design-error', 'Could not generate a share image. Try again in a moment.');

            return;
        }

        $site->update(array_filter([
            'brand_og_url' => $ogUrl,
            'brand_og_square_url' => $squareUrl,
        ]));
        app(PublicPageCache::class)->invalidate($site);
        session()->flash('design-msg', 'Share image regenerated.');
    }

    public function updatedShareImageUpload(): void
    {
        $this->validate([
            'shareImageUpload' => ['required', 'file', 'max:4096'],
        ]);

        $site = $this->authorizedSite();
        if ($this->shareImageUpload === null) {
            return;
        }

        $bytes = $this->shareImageUpload->get();
        try {
            $validated = app(BrandImageService::class)->validatedCustomShareImage($bytes);
        } catch (Throwable $exception) {
            Log::warning('Share image panel rejected an invalid upload', [
                'site_id' => $this->siteId,
                'error' => $exception->getMessage(),
            ]);
            $this->addError('shareImageUpload', $exception->getMessage());

            return;
        }

        $filename = sprintf('og-custom-%s.%s', sha1($bytes), $validated['extension']);
        $path = sprintf('sites/%d/brand/%s', $site->id, $filename);
        $disk = Storage::disk('s3');
        $previous = $site->brand_og_custom_path;

        try {
            if ($disk->put($path, $bytes, 'public') !== true || ! $disk->exists($path)) {
                throw new RuntimeException("Custom share image was not persisted at {$path}.");
            }
        } catch (Throwable $exception) {
            Log::error('Share image panel upload failed', [
                'site_id' => $site->id,
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);
            $this->addError('shareImageUpload', 'The share image could not be stored. Your previous image is unchanged.');
            session()->flash('design-error', 'The share image could not be stored. Your previous image is unchanged.');

            return;
        }

        $site->update([
            'brand_og_custom_path' => $path,
            'brand_og_custom_meta' => [
                'width' => $validated['width'],
                'height' => $validated['height'],
            ],
        ]);
        if (is_string($previous) && $previous !== '' && $previous !== $path) {
            try {
                if ($disk->delete($previous) !== true) {
                    throw new RuntimeException("Previous custom share image could not be deleted at {$previous}.");
                }
            } catch (Throwable $exception) {
                Log::error('Share image panel cleanup failed', [
                    'site_id' => $site->id,
                    'path' => $previous,
                    'error' => $exception->getMessage(),
                ]);
                session()->flash('design-error', 'The new share image was saved, but the previous file could not be removed.');
            }
        }

        $this->shareImageUpload = null;
        app(PublicPageCache::class)->invalidate($site);
        session()->flash('design-msg', 'Custom share image saved. It will be used instead of the generated card.');
    }

    public function removeCustomShareImage(): void
    {
        $site = $this->authorizedSite();
        $previous = $site->brand_og_custom_path;
        if (is_string($previous) && $previous !== '') {
            try {
                if (Storage::disk('s3')->delete($previous) !== true) {
                    throw new RuntimeException("Custom share image could not be deleted at {$previous}.");
                }
            } catch (Throwable $exception) {
                Log::error('Share image panel removal failed', [
                    'site_id' => $site->id,
                    'path' => $previous,
                    'error' => $exception->getMessage(),
                ]);
                session()->flash('design-error', 'The custom share image could not be removed.');

                return;
            }
        }

        $site->update([
            'brand_og_custom_path' => null,
            'brand_og_custom_meta' => null,
        ]);
        app(PublicPageCache::class)->invalidate($site);
        session()->flash('design-msg', 'Custom share image removed. The generated card will be used.');
    }

    public function with(): array
    {
        $site = $this->authorizedSite();
        $site->loadMissing('selectedLogoConcept');

        return [
            'shareImageUrl' => $site->ogImageUrl(),
            'shareImageIsCustom' => filled($site->brand_og_custom_path),
            'logoMissing' => $site->selectedLogoConcept === null,
        ];
    }
};
?>

<div class="space-y-4">
    @if (session('design-msg'))
        <flux:callout variant="success" icon="check-circle">{{ session('design-msg') }}</flux:callout>
    @endif
    @if (session('design-error'))
        <flux:callout variant="danger" icon="exclamation-triangle">{{ session('design-error') }}</flux:callout>
    @endif

    <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
        <div class="mb-3 flex items-center justify-between gap-2">
            <div>
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Share image</h3>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">1200×630 card used when this site is shared. Custom upload wins over the generated card.</p>
            </div>
            <flux:button size="sm" variant="primary" wire:click="regenerateShareImage" wire:loading.attr="disabled">Regenerate</flux:button>
        </div>
        @if ($logoMissing)
            <flux:callout variant="warning" icon="exclamation-triangle" class="mb-3">
                No logo selected — the card will use a monogram until you pick a logo.
            </flux:callout>
        @endif
        @if ($shareImageUrl)
            <img src="{{ $shareImageUrl }}" alt="Current share image" class="mb-3 w-full max-w-xl rounded border border-zinc-200 dark:border-neutral-700" width="1200" height="630">
            @if ($shareImageIsCustom)
                <p class="mb-2 text-xs text-zinc-500 dark:text-zinc-400">Using a custom upload.</p>
                <button type="button" wire:click="removeCustomShareImage" class="mb-3 text-xs text-zinc-500 underline hover:text-zinc-800 dark:hover:text-zinc-200">Remove custom image</button>
            @endif
        @else
            <p class="mb-3 text-sm text-zinc-500 dark:text-zinc-400">No share image yet — regenerate to create one.</p>
        @endif
        <label class="block text-xs text-zinc-500 dark:text-zinc-400">
            Custom upload
            <input type="file" wire:model="shareImageUpload" accept="image/png,image/jpeg,image/webp" class="mt-1 block text-sm">
        </label>
        @error('shareImageUpload') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </section>
</div>
