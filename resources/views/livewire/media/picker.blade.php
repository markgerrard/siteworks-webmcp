<?php

use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Models\SiteMedia;
use App\Services\Media\MediaLibraryService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use AuthorizesSiteAccess;
    use WithFileUploads;

    #[Locked]
    public int $siteId;

    #[Locked]
    public string $model = 'mediaId';

    #[Locked]
    public string $kinds = 'image';

    #[Locked]
    public string $aspect = '16:9';

    #[Locked]
    public string $slotLabel = 'Media';

    public bool $open = false;

    public string $tab = 'library';

    public string $search = '';

    /** @var list<mixed> */
    public $uploads = [];

    public function mount(int $siteId, string $model = 'mediaId', string $kinds = 'image', ?string $aspect = null, string $slotLabel = 'Media'): void
    {
        $this->siteId = $siteId;
        $this->model = $model;
        $this->kinds = $kinds;
        $this->aspect = $aspect ?: '16:9';
        $this->slotLabel = $slotLabel;
        abort_unless($this->findAuthorizedSite(), 403);
    }

    /**
     * @return list<string>
     */
    private function kindList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->kinds))));
    }

    /**
     * @return Collection<int, SiteMedia>
     */
    #[Computed]
    public function items(): Collection
    {
        $site = $this->assertAuthorizedSiteAccess();
        $kinds = $this->kindList();
        $filters = ['q' => $this->search];
        if (count($kinds) === 1) {
            $filters['kind'] = $kinds[0];
        }

        $items = app(MediaLibraryService::class)->list($site, $filters);
        if (count($kinds) > 1) {
            $items = $items->filter(fn (SiteMedia $media): bool => in_array($media->kind->value, $kinds, true))->values();
        }

        return $items;
    }

    /**
     * @return Collection<int, SiteMedia>
     */
    #[Computed]
    public function provisionalItems(): Collection
    {
        $kinds = $this->kindList();

        return SiteMedia::query()
            ->where('site_id', $this->siteId)
            ->where('provisional', true)
            ->when($kinds !== [], fn ($query) => $query->whereIn('kind', $kinds))
            ->orderByDesc('id')
            ->get();
    }

    public function openPicker(): void
    {
        $this->assertAuthorizedSiteAccess();
        $this->open = true;
        $this->tab = 'library';
        unset($this->items, $this->provisionalItems);
    }

    public function closePicker(): void
    {
        $this->open = false;
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, ['library', 'upload'], true)) {
            return;
        }
        $this->tab = $tab;
        unset($this->items, $this->provisionalItems);
    }

    public function selectMedia(int $mediaId): void
    {
        $media = $this->ownedLibraryMedia($mediaId);
        $this->emitSelected($media->id);
        $this->open = false;
    }

    public function updatedUploads(): void
    {
        $this->validate([
            'uploads' => 'required|array|min:1',
            'uploads.*' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120', // matches SiteMediaIngestService::MAX_BYTES
        ]);

        $site = $this->assertAuthorizedSiteAccess();
        $last = null;
        foreach ($this->uploads as $file) {
            $last = app(MediaLibraryService::class)->upload($site, $file);
        }
        $this->uploads = [];
        unset($this->items);
        if ($last !== null) {
            $this->emitSelected($last->id);
            $this->open = false;
        }
    }

    public function keepAndSelect(int $mediaId): void
    {
        $media = app(MediaLibraryService::class)->keep($this->ownedMedia($mediaId));
        $this->emitSelected($media->id);
        $this->open = false;
        unset($this->items, $this->provisionalItems);
    }

    private function emitSelected(int $id): void
    {
        $this->dispatch('media-selected', id: $id, model: $this->model);
    }

    private function ownedMedia(int $mediaId): SiteMedia
    {
        $site = $this->assertAuthorizedSiteAccess();

        return SiteMedia::query()->where('site_id', $site->id)->findOrFail($mediaId);
    }

    private function ownedLibraryMedia(int $mediaId): SiteMedia
    {
        $site = $this->assertAuthorizedSiteAccess();

        $media = SiteMedia::query()->library()->where('site_id', $site->id)->findOrFail($mediaId);

        // The kinds filter was display-only: enforce it at the mutation boundary too.
        $kind = $media->kind instanceof \BackedEnum ? $media->kind->value : (string) $media->kind;
        abort_unless(in_array($kind, $this->kindList(), true), 422, 'That media kind cannot be selected here.');

        return $media;
    }
}; ?>

<div data-livewire-component="media-picker">
    <button type="button" wire:click="openPicker"
            class="self-start rounded border border-zinc-300 px-2 py-1 text-xs dark:border-neutral-600">
        Choose {{ $slotLabel }}
    </button>

    @if ($open)
        <div class="mt-3 rounded-xl border border-zinc-200 p-4 dark:border-neutral-700" data-media-picker-modal role="dialog" aria-label="{{ $slotLabel }} picker">
            <div class="mb-3 flex items-center justify-between">
                <p class="text-sm font-medium">{{ $slotLabel }}</p>
                <button type="button" wire:click="closePicker" class="text-xs text-zinc-500 underline">Close</button>
            </div>
            <div class="mb-3 flex gap-2 text-sm">
                <button type="button" wire:click="setTab('library')" class="{{ $tab === 'library' ? 'font-semibold underline' : '' }}">Library</button>
                <button type="button" wire:click="setTab('upload')" class="{{ $tab === 'upload' ? 'font-semibold underline' : '' }}">Upload</button>
            </div>

            @if ($tab === 'library')
                <input type="search" wire:model.live.debounce.300ms="search" class="mb-3 w-full rounded border border-zinc-300 bg-transparent px-2 py-1 text-sm dark:border-neutral-600" aria-label="Search library" placeholder="Search">
                @if ($this->items->isEmpty())
                    <p class="text-sm text-zinc-500">No matching files.</p>
                @else
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        @foreach ($this->items as $media)
                            <button type="button" wire:key="pick-{{ $media->id }}" wire:click="selectMedia({{ $media->id }})" class="overflow-hidden rounded border border-zinc-200 text-left dark:border-neutral-700">
                                @if ($media->url)
                                    <img src="{{ $media->url }}" alt="" class="aspect-video w-full object-cover">
                                @endif
                                <span class="block truncate p-1 text-xs">{{ $media->title ?: basename((string) $media->s3_key) }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            @endif

            @if ($tab === 'upload')
                <label class="flex cursor-pointer flex-col items-center rounded-xl border border-dashed border-zinc-300 px-4 py-8 text-sm text-zinc-500 dark:border-neutral-600">
                    Drop files here or click to upload
                    <input type="file" wire:model="uploads" multiple class="mt-2 text-sm" aria-label="Upload media">
                </label>
            @endif

        </div>
    @endif
</div>
