<?php

use App\Enums\ProjectItemSource;
use App\Enums\ProjectItemStatus;
use App\Enums\ProjectItemType;
use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Models\SiteMedia;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use AuthorizesSiteAccess, WithFileUploads;

    /**
     * Identity of the card. Both are #[Locked]: without it Livewire lets
     * the browser rewrite them via $wire.set(), and every action that
     * resolved the row globally then operated on another tenant's item
     * while the site check passed against the attacker's own siteId.
     */
    #[Locked]
    public int $itemId;

    #[Locked]
    public int $siteId;

    /**
     * Per-request memo for the authorised Site. findAuthorizedSite() issues
     * a Site::find on every call, and the render path touches it several
     * times per card ($this->item + driftBadge); on a 30-tile gallery that
     * was ~90 redundant lookups. Not a Livewire property — never
     * serialised, so it cannot be tampered with or leak across requests.
     */
    private ?Site $resolvedSite = null;

    private bool $resolvedSiteLoaded = false;

    private function authorizedSite(): ?Site
    {
        if (! $this->resolvedSiteLoaded) {
            $this->resolvedSite = $this->findAuthorizedSite();
            $this->resolvedSiteLoaded = true;
        }

        return $this->resolvedSite;
    }

    /**
     * Lazy-load placeholder. The view keeps data-item-id on the root so
     * SortableJS reorder still sees unmounted cards — see the view for why.
     *
     * @param  array{itemId?: int}  $params
     */
    public function placeholder(array $params = []): \Illuminate\View\View
    {
        return view('livewire.placeholders.project-item-card', [
            'itemId' => (int) ($params['itemId'] ?? 0),
        ]);
    }

    // Bound fields — separate from the model so we can stage edits with
    // explicit save + validation feedback without trampling observer hashes.
    public string $title = '';
    public string $description = '';
    public string $category = '';
    public array $metrics = [];

    /**
     * Set true on any tracked-field update; reset on save(). Drives the
     * Save-changes button enable/disable + the "unsaved changes" affordance.
     */
    public bool $dirty = false;

    /**
     * Livewire lifecycle hook — fires on every public-property update via
     * wire:model. Marks the card dirty for tracked-field changes only,
     * so transient state like $imageUpload doesn't trigger Save.
     */
    public function updated(string $name): void
    {
        if (in_array($name, ['title', 'description', 'category'], true)
            || str_starts_with($name, 'metrics.')) {
            $this->dirty = true;
        }
    }

    /**
     * Upload buffer for the agent-upload flow. One-at-a-time per card —
     * Livewire serialises clicks anyway, so a single property is enough.
     */
    public $imageUpload = null;

    public array $supportedIcons = ['timer', 'castle', 'shield', 'scale', 'construction', 'bolt', 'hard-hat'];

    public function mount(int $itemId): void
    {
        $item = ProjectItem::findOrFail($itemId);
        $this->itemId = $itemId;
        $this->siteId = $item->site_id;
        // Authorise via site access concern.
        $this->assertAuthorizedSiteAccess();

        $this->reloadFromModel($item);
    }

    /**
     * Re-hydrate the bound fields from the underlying ProjectItem row.
     * Listens for `composition-published` (dispatched by the
     * unpublished-changes-banner after Publish AND Discard) so the
     * input fields snap to whatever the DB now holds — without this,
     * Discard reverts the row but the Livewire input still shows the
     * agent's mid-edit state.
     */
    #[On('composition-published')]
    public function reloadFields(): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        $item = $this->itemForSite($site);
        if (! $item) {
            return;
        }
        $this->reloadFromModel($item);
    }

    /**
     * Resolve $itemId inside the given site. Never resolve the id
     * globally — it is client-visible state, so a global find() lets a
     * tampered id reach another tenant's row.
     *
     * @param  list<string>  $with  relations to eager-load
     */
    private function itemForSite(Site $site, array $with = []): ?ProjectItem
    {
        return ProjectItem::with($with)
            ->where('id', $this->itemId)
            ->where('site_id', $site->id)
            ->first();
    }

    /**
     * Fail-closed resolution for mutating actions: 403 when the site is
     * not the caller's, 404 when the item is not in it. Matches the abort
     * semantics the previous findOrFail() calls had for a missing row.
     */
    private function authorizedItemOrFail(): ProjectItem
    {
        $site = $this->assertAuthorizedSiteAccess();

        return ProjectItem::where('id', $this->itemId)
            ->where('site_id', $site->id)
            ->firstOrFail();
    }

    private function reloadFromModel(ProjectItem $item): void
    {
        $this->title = (string) $item->title;
        $this->description = (string) $item->description;
        $this->category = (string) $item->category;
        $this->metrics = $item->metrics ?? [];
        $this->dirty = false;
    }

    /**
     * Read via the PROPERTY form ($this->item) everywhere — calling it as a
     * method bypasses #[Computed] memoisation and re-runs the site + item
     * lookups on every reference.
     */
    #[Computed]
    public function item(): ?ProjectItem
    {
        $site = $this->authorizedSite();
        if (! $site) {
            return null;
        }

        return $this->itemForSite($site, ['image', 'site', 'versions']);
    }

    public function activateVersion(int $mediaId): void
    {
        $item = $this->authorizedItemOrFail();
        // Verify the SiteMedia row belongs to this item — prevents an
        // attacker (or stale browser DOM) from pointing image_id at an
        // unrelated site's media. The project_item_id FK is the source
        // of truth for which versions belong here.
        $media = SiteMedia::where('id', $mediaId)
            ->where('project_item_id', $item->id)
            ->firstOrFail();
        $item->update(['image_id' => $media->id]);
        // Bust the public preview HTML cache — the rendered image URL
        // for this project item has changed, and view:clear / cache-buster
        // query strings don't reach PublicPageCache.
        app(\App\Services\Site\PublicPageCache::class)->invalidate($item->site);

        session()->flash(
            'page-mgr-msg',
            'Switched to version from '.$media->created_at->format('d M H:i').'.'
        );
    }

    public function save(): void
    {
        $item = $this->authorizedItemOrFail();
        $vocab = $item->site->project_categories ?? [];

        $this->validate([
            'title' => 'required|string|max:120',
            'description' => 'required|string|max:280',
            'category' => ['required', 'string', 'in:'.(empty($vocab) ? '__none__' : implode(',', $vocab))],
        ]);

        // Flip source from AiGenerated to AgentEdited on first manual
        // save. Without this, GenerateProjectsPageJob's idempotency wipe
        // (delete WHERE source=ai_generated AND status=draft) catches
        // the merchant-edited item on the next regen and silently nukes
        // every customisation (review). Keep all other
        // sources untouched — AgentAdded / ClientUpload / Facebook etc.
        // were never at risk because they don't match the delete query.
        $payload = [
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'metrics' => $this->metrics,
        ];
        if ($item->source === ProjectItemSource::AiGenerated) {
            $payload['source'] = ProjectItemSource::AgentEdited;
        }

        $item->update($payload);

        // Tile content edits change what the next publish will pin —
        // nudge the unpublished-changes banner so the agent can see
        // there's something to publish. The banner's existing checks
        // catch tile-set deltas already; this dispatch covers the
        // CONTENT delta on individual tiles.
        $this->dispatch('composition-dirty');
        $this->dirty = false;
        session()->flash('project-item-saved-'.$this->itemId, true);
    }

    public function archive(): void
    {
        $this->authorizedItemOrFail()->update(['status' => ProjectItemStatus::Archived]);
        // Nudge the unpublished-changes banner — archiving changes the
        // tile set the next publish will pin.
        $this->dispatch('composition-dirty');
    }

    public function unarchive(): void
    {
        $this->authorizedItemOrFail()->update(['status' => ProjectItemStatus::Draft]);
        $this->dispatch('composition-dirty');
    }

    public function uploadProjectImage(): void
    {
        $site = $this->authorizedSite();
        if (! $site) {
            return;
        }

        $item = $this->itemForSite($site);
        if (! $item) {
            return;
        }

        if (! RateLimiter::attempt("project-image-upload:{$site->id}", 20, fn () => true, 300)) {
            session()->flash('page-mgr-err', 'Upload rate limit reached — please wait a few minutes.');

            return;
        }

        $this->validate([
            'imageUpload' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $tmpPath = $this->imageUpload->getRealPath();
        $info = @getimagesizefromstring(file_get_contents($tmpPath));
        if ($info === false || max($info[0], $info[1]) < 600) {
            session()->flash('page-mgr-err', 'Image too small — long edge must be at least 600px.');
            $this->reset('imageUpload');

            return;
        }

        $ext = strtolower($this->imageUpload->getClientOriginalExtension() ?: 'jpg');
        $ts = now()->format('YmdHis');
        $path = sprintf(
            'sites/%d/project_items/%d-userupload-%s.%s',
            $site->id, $item->id, $ts, $ext,
        );
        Storage::disk('s3')->put($path, file_get_contents($tmpPath), 'public');
        $url = Storage::disk('s3')->url($path);

        // Create the SiteMedia row + flip image_id atomically. No
        // advisory lock needed — image_id is per-row, no concurrent
        // upload from a different session can race against itself in a
        // way that produces a partial-unique-violation outcome.
        $media = SiteMedia::create([
            'site_id' => $site->id,
            'project_item_id' => $item->id,
            'source' => 'agent_upload',
            'source_ref' => 'user-upload-'.$ts,
            'alt_text' => $item->title ?: $item->category,
            'llm_call_id' => null,
            'url' => $url,
            's3_key' => $path,
            'mime_type' => 'image/'.($ext === 'jpg' ? 'jpeg' : $ext),
            'metadata' => [
                'width' => $info[0],
                'height' => $info[1],
                'origin' => 'agent_upload',
            ],
        ]);

        $item->update([
            'image_id' => $media->id,
            'image_job_state' => 'succeeded',
        ]);

        // Bust the public preview HTML cache — the rendered image URL
        // for this project item has changed, view:clear / cache-buster
        // query strings dont reach PublicPageCache.
        app(\App\Services\Site\PublicPageCache::class)->invalidate($item->site);

        $this->reset('imageUpload');
        session()->flash('page-mgr-msg', 'Image uploaded.');
    }

    public function addMetric(): void
    {
        $this->metrics[] = ['icon' => 'timer', 'label' => ''];
        $this->dirty = true;
    }

    public function removeMetric(int $index): void
    {
        unset($this->metrics[$index]);
        $this->metrics = array_values($this->metrics);
        $this->save();
    }

    public function driftBadge(): ?string
    {
        $item = $this->item;
        if (! $item || ! $item->page) {
            return null;
        }

        foreach (($item->page->content_data['sections'] ?? []) as $section) {
            $pinned = $section['published_content_hashes'][$item->id] ?? null;
            if ($pinned && $pinned !== $item->content_hash) {
                return 'Edited since publish';
            }
        }

        return null;
    }
};
?>

@php
    $item = $this->item;
    $isArchived = $item && $item->status === \App\Enums\ProjectItemStatus::Archived;
    $driftBadge = $this->driftBadge();
@endphp
<div class="relative rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 {{ $isArchived ? 'opacity-60' : '' }}"
     data-livewire-component="project-item-card"
     data-item-id="{{ $itemId }}">

    @if ($isArchived)
        <div class="absolute top-2 left-2 z-10 px-2 py-0.5 rounded-full bg-zinc-100 text-zinc-500 dark:bg-neutral-800 dark:text-zinc-400 text-[10px] font-bold uppercase tracking-wide">
            Archived
        </div>
    @endif

    {{-- Drag handle — top-right of the card. Solid background + ring so
         it stays readable over both light + dark images. Only the handle
         initiates a drag (handle: '.tile-drag-handle' on Sortable's
         config), so form fields and buttons inside the card stay
         interactive. --}}
    @if (! $isArchived)
        <button type="button"
                class="tile-drag-handle absolute top-2 right-2 z-20 w-7 h-7 inline-flex items-center justify-center rounded-md bg-white/95 ring-1 ring-zinc-200 text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:bg-neutral-800/95 dark:ring-neutral-600 dark:text-zinc-300 dark:hover:bg-neutral-700 dark:hover:text-white shadow-sm cursor-grab active:cursor-grabbing"
                title="Drag to reorder"
                aria-label="Drag to reorder">
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                <circle cx="7" cy="5" r="1.5"/><circle cx="13" cy="5" r="1.5"/>
                <circle cx="7" cy="10" r="1.5"/><circle cx="13" cy="10" r="1.5"/>
                <circle cx="7" cy="15" r="1.5"/><circle cx="13" cy="15" r="1.5"/>
            </svg>
        </button>
    @endif

    @if (! $item)
        <p class="text-sm text-red-600">Item #{{ $itemId }} not found.</p>
    @else
        @php $isCaseStudy = $item->type === \App\Enums\ProjectItemType::CaseStudy; @endphp
        {{-- Layout: gallery tiles stay vertical (image-on-top stacked
             above fields). Case-study tiles flip to side-by-side on
             md+ — image left, fields right — so the 16:9 banner
             doesn't dominate the card vertically. --}}
        <div class="@if ($isCaseStudy) md:flex md:gap-4 @endif">
        {{-- Image well + badges --}}
        <div class="relative overflow-hidden rounded-lg
                    {{ $isCaseStudy ? 'aspect-[16/9] mb-3 md:mb-0 md:w-2/5 md:flex-shrink-0' : 'aspect-[4/5] mb-3' }}
                    bg-zinc-100 dark:bg-neutral-800">

            @if ($item->image?->url)
                <img src="{{ $item->image->url }}"
                     alt="{{ $item->image->alt_text ?? $item->title }}"
                     class="w-full h-full object-cover">
            @else
                <div class="w-full h-full flex items-center justify-center">
                    <span class="text-xs uppercase tracking-widest text-zinc-500">{{ $item->category }}</span>
                </div>
            @endif

            {{-- Image-state badges --}}
            @if ($item->image_id === null && $item->image_job_state === 'pending')
                <div class="absolute top-2 right-2 bg-zinc-900/85 text-white text-xs px-2 py-1 rounded animate-pulse">
                    Image generating
                </div>
            @elseif ($item->image_id === null && $item->image_job_state === 'failed')
                <div class="absolute top-2 right-2 bg-red-600 text-white text-xs px-2 py-1 rounded">
                    Image generation failed
                </div>
            @elseif ($item->image_id === null && $item->image_job_state === 'cost_capped')
                <div class="absolute top-2 right-2 bg-amber-600 text-white text-xs px-2 py-1 rounded">
                    Skipped — monthly cost cap
                </div>
            @elseif ($item->image_id !== null && $item->image_job_state === 'pending')
                {{-- regenerating: existing image still visible, subtle overlay --}}
                <div class="absolute top-2 right-2 bg-zinc-900/85 text-white text-xs px-2 py-1 rounded animate-pulse">
                    Regenerating…
                </div>
            @endif

            {{-- Drift badge (content-edit drift, orthogonal to image state) --}}
            @if ($driftBadge)
                <div class="absolute top-2 left-2 bg-amber-500 text-white text-xs font-medium px-2 py-1 rounded shadow-sm">
                    {{ $driftBadge }}
                </div>
            @endif
        </div>

        {{-- Edit fields — full width on gallery tiles, flex-1 on
             case-study tiles so they share row space with the image. --}}
        <div class="space-y-2 @if ($isCaseStudy) md:flex-1 md:min-w-0 @endif">
            <label class="block text-xs font-medium text-zinc-500 dark:text-zinc-400">
                Title
                <input type="text"
                       wire:model.live.debounce.500ms="title"
                       maxlength="120"
                       class="mt-1 w-full rounded-md border border-zinc-200 bg-white px-2 py-1 text-sm dark:border-neutral-700 dark:bg-neutral-900">
            </label>
            @error('title')<p class="text-xs text-red-600">{{ $message }}</p>@enderror

            <label class="block text-xs font-medium text-zinc-500 dark:text-zinc-400">
                Description
                <textarea wire:model.live.debounce.500ms="description"
                          maxlength="280"
                          rows="2"
                          class="mt-1 w-full rounded-md border border-zinc-200 bg-white px-2 py-1 text-sm dark:border-neutral-700 dark:bg-neutral-900"></textarea>
            </label>
            @error('description')<p class="text-xs text-red-600">{{ $message }}</p>@enderror

            <label class="block text-xs font-medium text-zinc-500 dark:text-zinc-400">
                Category
                <select wire:model.live="category"
                        class="mt-1 w-full rounded-md border border-zinc-200 bg-white px-2 py-1 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                    @foreach ($item->site->project_categories ?? [] as $c)
                        <option value="{{ $c }}">{{ $c }}</option>
                    @endforeach
                </select>
            </label>
            @error('category')<p class="text-xs text-red-600">{{ $message }}</p>@enderror

            {{-- Case-study metrics editor — hidden on gallery layout --}}
            @if ($item->type === \App\Enums\ProjectItemType::CaseStudy)
                <div class="mt-3 border-t border-zinc-100 dark:border-neutral-800 pt-3">
                    <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-2">Metrics</div>
                    @foreach ($metrics as $index => $metric)
                        <div class="flex items-center gap-2 mb-2">
                            <select wire:model.live="metrics.{{ $index }}.icon"
                                    class="rounded-md border border-zinc-200 bg-white px-2 py-1 text-xs dark:border-neutral-700 dark:bg-neutral-900">
                                @foreach ($supportedIcons as $icon)
                                    <option value="{{ $icon }}">{{ $icon }}</option>
                                @endforeach
                            </select>
                            <input type="text"
                                   wire:model.live.debounce.500ms="metrics.{{ $index }}.label"
                                   placeholder="Metric label"
                                   maxlength="28"
                                   class="flex-1 rounded-md border border-zinc-200 bg-white px-2 py-1 text-xs dark:border-neutral-700 dark:bg-neutral-900">
                            <button type="button"
                                    wire:click="removeMetric({{ $index }})"
                                    class="text-xs text-zinc-400 hover:text-red-600">×</button>
                        </div>
                    @endforeach
                    <button type="button"
                            wire:click="addMetric"
                            class="text-xs font-medium text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100">
                        + Add metric
                    </button>
                </div>
            @endif

            {{-- Explicit save — replaces per-field autosave so the
                 unpublished-changes banner only fires when the agent is
                 done editing, not on every blur. --}}
            <div class="flex items-center justify-between mt-3 pt-3 border-t border-zinc-100 dark:border-neutral-800">
                @if (session('project-item-saved-'.$itemId))
                    <span class="text-xs text-emerald-700 dark:text-emerald-300 inline-flex items-center gap-1">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        Saved
                    </span>
                @elseif ($dirty)
                    <span class="text-xs text-amber-700 dark:text-amber-300">Unsaved changes</span>
                @else
                    <span class="text-xs text-zinc-400 dark:text-zinc-500">No changes</span>
                @endif
                <button type="button"
                        wire:click="save"
                        wire:loading.attr="disabled"
                        wire:target="save"
                        @disabled(! $dirty)
                        class="text-xs font-semibold px-3 py-1 rounded
                               {{ $dirty
                                  ? 'bg-zinc-900 text-white hover:bg-zinc-700 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-300 cursor-pointer'
                                  : 'bg-zinc-100 text-zinc-400 dark:bg-neutral-800 dark:text-zinc-500 cursor-not-allowed' }}">
                    <span wire:loading.remove wire:target="save">Save changes</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </button>
            </div>
        </div>
        </div>{{-- /case-study row wrapper --}}

        {{-- Version history (mirrors the hero version-picker pattern in
             page-manager.blade.php). Hidden behind a toggle when there's
             more than one version. Click an inactive thumbnail to switch
             the active image. --}}
        @if ($item->versions->count() > 1)
            <div class="mt-3" x-data="{ showVersions: false }">
                <button type="button" x-on:click="showVersions = !showVersions"
                        class="text-xs text-amber-600 dark:text-amber-400 hover:text-amber-800 dark:hover:text-amber-300 underline underline-offset-2 cursor-pointer">
                    <span x-text="showVersions ? 'Hide' : 'Show'"></span> version history ({{ $item->versions->count() }})
                </button>
                <div x-show="showVersions" x-cloak class="mt-2 grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-2"
                     wire:loading.class="opacity-60 pointer-events-none animate-pulse"
                     wire:target="activateVersion">
                    @foreach ($item->versions as $version)
                        @php
                            $isActive = $version->id === $item->image_id;
                            $aspectClass = $item->type === \App\Enums\ProjectItemType::CaseStudy ? 'aspect-[16/9]' : 'aspect-[4/5]';
                            // Preview must be reliably larger than the
                            // thumbnail across any grid breakpoint
                            // (3/4/6 cols). Pick fixed pixel widths that
                            // beat the largest realistic thumb (~280px
                            // at 3-col on a wide card).
                            $previewSize = $item->type === \App\Enums\ProjectItemType::CaseStudy ? 'w-[36rem]' : 'w-[22rem]';
                            $isUserUpload = $version->source === 'agent_upload';
                        @endphp
                        {{-- Outer .group wraps both the thumbnail and the
                             absolute hover-preview, so :hover on either is
                             scoped to this single version. The thumbnail
                             keeps its overflow-hidden so border-radius
                             clips the image; the preview popover sits as
                             a sibling that's free to escape those bounds. --}}
                        <div class="relative group">
                            <div class="cursor-pointer rounded-md overflow-hidden border-2 {{ $isActive ? 'border-zinc-900 dark:border-white' : 'border-zinc-200 dark:border-neutral-700' }}"
                                 @if (!$isActive) wire:click="activateVersion({{ $version->id }})" @endif
                                 title="{{ $isActive ? 'Active' : 'Click to activate' }} — {{ $version->created_at->format('d M H:i') }}">
                                <img src="{{ $version->url }}" alt="v{{ $version->id }}"
                                     class="w-full {{ $aspectClass }} object-cover" />
                                <div class="absolute top-1 right-1 px-1.5 py-0.5 rounded-full text-white text-[9px] font-bold uppercase tracking-wide
                                            {{ $isUserUpload ? 'bg-emerald-600' : 'bg-blue-600' }}">
                                    {{ $isUserUpload ? 'User' : 'AI' }}
                                </div>
                                @if (!$isActive)
                                    <div class="absolute inset-0 bg-black/0 group-hover:bg-black/30 transition-all flex items-center justify-center">
                                        <span class="text-white text-xs font-medium opacity-0 group-hover:opacity-100 transition-opacity">Use this</span>
                                    </div>
                                @endif
                                <div class="absolute bottom-0 inset-x-0 bg-black/60 text-xs text-white px-1 py-0.5 text-center truncate">
                                    {{ $version->created_at->format('d M H:i') }}
                                </div>
                            </div>
                            {{-- Hover-zoom preview. pointer-events-none so
                                 the popover doesn't steal the hover state
                                 from the thumbnail itself. z-50 escapes any
                                 ancestor overflow-hidden (page-manager card
                                 wrappers etc.). --}}
                            {{-- Width is set on the wrapper (not the img)
                                 so Tailwind's preflight `img { max-width: 100% }`
                                 doesn't collapse the popover. The wrapper
                                 establishes a width context the img can
                                 fill at 100%. --}}
                            <div class="hidden group-hover:block absolute z-50 left-1/2 -translate-x-1/2 bottom-full mb-2 pointer-events-none {{ $previewSize }}">
                                <img src="{{ $version->url }}" alt=""
                                     class="w-full {{ $aspectClass }} object-cover rounded-lg shadow-2xl ring-1 ring-black/20 dark:ring-white/20" />
                                <div class="mt-1 text-center text-[10px] font-medium text-zinc-700 dark:text-zinc-300 bg-white/90 dark:bg-neutral-900/90 rounded px-2 py-0.5 inline-block w-full">
                                    {{ $version->created_at->format('d M Y H:i') }}
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Action row --}}
        <div class="mt-4 flex flex-wrap gap-2">
            {{-- Agent upload — bypass AI, file -> S3 -> SiteMedia row -> active.
                 Hidden input + button trigger pattern, mirrors hero / intro. --}}
            <input
                type="file"
                id="project-image-upload-{{ $itemId }}"
                wire:model="imageUpload"
                x-on:livewire-upload-finish="$wire.uploadProjectImage()"
                accept="image/jpeg,image/png,image/webp"
                class="hidden">
            <button type="button"
                    x-on:click="document.getElementById('project-image-upload-{{ $itemId }}').click()"
                    wire:loading.attr="disabled"
                    wire:target="uploadProjectImage, imageUpload"
                    class="text-xs font-medium px-2 py-1 rounded border border-zinc-200 hover:bg-zinc-50 dark:border-neutral-700 dark:hover:bg-neutral-800">
                <span wire:loading.remove wire:target="uploadProjectImage, imageUpload">Upload image</span>
                <span wire:loading wire:target="uploadProjectImage, imageUpload">Uploading…</span>
            </button>

            @if ($isArchived)
                <button type="button"
                        wire:click="unarchive"
                        class="text-xs font-medium px-2 py-1 rounded border border-emerald-200 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-700 dark:text-emerald-300 dark:hover:bg-emerald-900/30">
                    Unarchive
                </button>
            @else
                <button type="button"
                        wire:click="archive"
                        class="text-xs font-medium px-2 py-1 rounded border border-zinc-200 text-zinc-600 hover:bg-zinc-50 dark:border-neutral-700 dark:hover:bg-neutral-800">
                    Archive
                </button>
            @endif
        </div>
    @endif
</div>
