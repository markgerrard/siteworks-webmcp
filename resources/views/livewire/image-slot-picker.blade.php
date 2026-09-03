<?php

use App\Enums\HeroVersionSource;
use App\Enums\PageStatus;
use App\Exceptions\UnsupportedImageException;
use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\Site;
use App\Services\Images\ImageOptimiserService;
use App\Services\Site\HeroVersionService;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use AuthorizesSiteAccess;
    use WithFileUploads;

    private const SLOTS = ['hero', 'intro', 'band', 'band_2', 'band_3'];

    #[Locked]
    public int $siteId;

    #[Locked]
    public int $pageId;

    #[Locked]
    public string $slot;

    public $file = null;

    /**
     * @var list<array{id: int, url: string, is_active: bool, created_at: string|null}>
     */
    #[Locked]
    public array $versions = [];

    public function mount(int $siteId, int $pageId, string $slot): void
    {
        abort_unless(in_array($slot, self::SLOTS, true), 404);

        $this->siteId = $siteId;
        $this->pageId = $pageId;
        $this->slot = $slot;

        $site = $this->assertAuthorizedSiteAccess();
        $page = $this->resolveActiveOwnedPage($site);
        $this->refreshVersions($site, $page);
    }

    public function rendering(): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        $page = $this->resolveActiveOwnedPage($site);
        $this->refreshVersions($site, $page);
    }

    public function select(int $versionId): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        $page = $this->resolveActiveOwnedPage($site);
        $version = HeroVersion::query()
            ->where('site_id', $site->id)
            ->where('page_type', $page->page_type)
            ->where('slot', $this->slot)
            ->whereKey($versionId)
            ->first();
        abort_unless($version !== null, 404);

        $heroVersions = app(HeroVersionService::class);
        $heroVersions->activateExistingAndRecord($version, $site, auth()->id());
        $this->dispatch('composition-dirty');
        $this->refreshVersions($site, $page);
    }

    public function upload(): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        $page = $this->resolveActiveOwnedPage($site);
        $pageType = $this->pageTypeKey($page);
        $this->assertStorageKeySegment($pageType);
        $this->assertStorageKeySegment($this->slot);

        if (! RateLimiter::attempt("hero-upload:{$site->id}", 10, fn () => true, 300)) {
            $this->addError('file', 'Upload rate limit reached — please wait a few minutes.');

            return;
        }

        $this->validate(['file' => 'required|file|image|mimes:jpg,jpeg,png,webp|max:12288']);

        $tmpPath = $this->file->getRealPath();
        $info = @getimagesizefromstring(file_get_contents($tmpPath));
        if ($info === false || max($info[0], $info[1]) < 600) {
            $this->addError('file', 'Image too small — long edge must be at least 600px.');

            return;
        }

        try {
            $out = app(ImageOptimiserService::class)->optimise(file_get_contents($tmpPath));
        } catch (UnsupportedImageException $e) {
            $this->addError('file', 'That image could not be processed.');

            return;
        }

        $root = rtrim(config('services.storage.preview_root', 'previews'), '/');
        $path = "{$root}/{$site->id}/hero/{$pageType}/{$this->slot}/".Str::uuid().'.webp';
        $disk = Storage::disk('s3');

        try {
            $putOk = $disk->put($path, $out['bytes'], 'public');
            if ($putOk !== true) {
                throw new \RuntimeException(
                    "Image slot upload to s3://{$path} returned false (site {$site->id})"
                );
            }

            HeroVersion::create([
                'site_id' => $site->id,
                'page_type' => $pageType,
                'slot' => $this->slot,
                'url' => $disk->url($path),
                'prompt' => 'manual upload',
                'model' => 'upload',
                'placement' => [],
                'is_active' => false,
                'source' => HeroVersionSource::UserUpload,
            ]);
        } catch (\Throwable $e) {
            report($e);
            try {
                $disk->delete($path);
            } catch (\Throwable) {
                // Best-effort: the original failure is already reported.
            }
            $this->addError('file', 'That image could not be stored.');

            return;
        }

        $this->file = null;
        $this->refreshVersions($site, $page);
    }

    private function resolveActiveOwnedPage(Site $site): GeneratedPage
    {
        $page = GeneratedPage::query()
            ->where('site_id', $site->id)
            ->find($this->pageId);

        abort_unless(
            $page !== null
            && $page->status !== PageStatus::Archived
            && $page->archived_at === null,
            404
        );

        return $page;
    }

    private function pageTypeKey(GeneratedPage $page): string
    {
        return $page->page_type instanceof \BackedEnum
            ? $page->page_type->value
            : (string) $page->page_type;
    }

    private function assertStorageKeySegment(string $value): void
    {
        abort_unless((bool) preg_match('/^[a-z0-9_-]+$/', $value), 404);
    }

    private function refreshVersions(Site $site, GeneratedPage $page): void
    {
        $this->versions = HeroVersion::query()
            ->where('site_id', $site->id)
            ->where('page_type', $page->page_type)
            ->where('slot', $this->slot)
            ->orderByDesc('id')
            ->get(['id', 'url', 'is_active', 'created_at'])
            ->map(fn (HeroVersion $version): array => [
                'id' => $version->id,
                'url' => $version->url,
                'is_active' => (bool) $version->is_active,
                'created_at' => $version->created_at?->format('d M Y H:i'),
            ])
            ->all();
    }
}; ?>

<div data-livewire-component="image-slot-picker" data-slot="{{ $slot }}">
    @if (session('image-slot-msg'))
        <p class="mb-3 text-xs text-emerald-700 dark:text-emerald-300">{{ session('image-slot-msg') }}</p>
    @endif

    <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">Applies immediately to the live site.</p>

    @if ($versions === [])
        <p class="mb-3 text-xs text-zinc-400 dark:text-zinc-500">No versions in this slot yet.</p>
    @else
        <div class="mb-4 grid grid-cols-2 sm:grid-cols-3 gap-2">
            @foreach ($versions as $v)
                <div class="relative rounded-md overflow-hidden border {{ $v['is_active'] ? 'border-zinc-900 dark:border-white' : 'border-zinc-200 dark:border-neutral-700' }}">
                    <img src="{{ $v['url'] }}" alt="" class="w-full aspect-video object-cover" />
                    <div class="absolute bottom-0 inset-x-0 bg-black/60 text-[10px] text-white px-1 py-0.5 text-center truncate">
                        {{ $v['created_at'] }}
                    </div>
                    @if ($v['is_active'])
                        <div class="absolute top-1 left-1 px-1.5 py-0.5 rounded-full bg-zinc-900 dark:bg-white text-white dark:text-zinc-900 text-[9px] font-bold uppercase tracking-wide">
                            Active
                        </div>
                    @else
                        <button type="button"
                                wire:click="select({{ $v['id'] }})"
                                class="absolute inset-0 flex items-center justify-center bg-black/0 hover:bg-black/30 transition-colors text-white text-xs font-medium opacity-0 hover:opacity-100">
                            Use this
                        </button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <div class="flex flex-col gap-3">
        <div class="flex items-center gap-2 flex-wrap">
            <input type="file"
                   wire:model="file"
                   accept="image/jpeg,image/png,image/webp"
                   class="text-xs text-zinc-600 dark:text-zinc-300 file:mr-2 file:rounded file:border-0 file:bg-zinc-100 file:px-2 file:py-1 file:text-xs dark:file:bg-neutral-800">
            <button type="button"
                    wire:click="upload"
                    wire:loading.attr="disabled"
                    wire:target="upload, file"
                    class="text-xs font-medium px-3 py-1.5 rounded-md border border-zinc-300 dark:border-neutral-600 text-zinc-700 dark:text-zinc-200 hover:bg-zinc-100 dark:hover:bg-neutral-800">
                <span wire:loading.remove wire:target="upload, file">Upload</span>
                <span wire:loading wire:target="upload, file">Uploading…</span>
            </button>
        </div>
        @error('file')
            <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
        @enderror

    </div>
</div>
