<?php

use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\HeroVideoVersion;
use App\Models\Site;
use App\Services\Site\PublicPageCache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Hero scene studio — multi-slide editor for the home hero. Backs the
 * sites.home_hero_scene JSON column. Single-slide and null-scene sites
 * keep rendering through the legacy hero.blade.php path; this component
 * only kicks in when the agent flips "Scene mode" on.
 *
 * v1 scope:
 *  - kind=image (slider) only — kind=video is hooked up via the video
 *    studio's composite job.
 *  - 1..4 slides, fade transition, per-slide heading/sub/cta/text_zone.
 *  - Asset picker lists active + inactive HeroVersions for this site +
 *    home/hero so the agent can re-use any past gen as a slide.
 */
new class extends Component
{
    use AuthorizesSiteAccess;

    #[Locked]
    public int $siteId;

    /** Whether the site has a scene at all. Null scene → false. */
    public bool $sceneEnabled = false;

    /**
     * Working copy of the scene JSON. wire:model.blur on the inputs
     * mutates this nested array; the updated() hook persists on every
     * change so the user doesn't have to hit "save".
     *
     * @var array{kind:string,slides:array<int,array<string,mixed>>,transitions:array<int,array<string,mixed>>,composite_video_id:?int}
     */
    public array $scene = [
        'kind' => 'image',
        'slides' => [],
        'transitions' => [],
        'composite_video_id' => null,
    ];

    /**
     * Index of the slide that's currently in focus in the editor's
     * right pane. The asset library + copy fields edit this slide.
     * Reset to 0 whenever it goes out of bounds (slide removal etc).
     */
    public int $selectedSlideIndex = 0;

    /**
     * Page-type filter for the image library carousel. Defaults to
     * 'home' so the agent sees the home hero pool first; switching to
     * a service page or 'all' lets them re-use any image they've
     * generated across the site.
     */
    public string $libraryPageType = 'home';

    public ?string $errorMessage = null;

    public const MAX_SLIDES = 4;

    public const TEXT_ZONES = [
        'top-left', 'top-center', 'top-right',
        'middle-left', 'middle-center', 'middle-right',
        'bottom-left', 'bottom-center', 'bottom-right',
    ];

    public const TRANSITION_TYPES = ['fade'];

    public const FOCUS_OPTIONS = [
        'auto' => 'Auto',
        'left' => 'Left',
        'centre' => 'Centre',
        'right' => 'Right',
        'fill' => 'Fill',
    ];

    /** Site-default copy focus. Empty/auto is stored as null. */
    public string $heroFocus = 'auto';

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        $site = $this->findAuthorizedSite();
        if (! $site) {
            return;
        }
        $this->heroFocus = $site->hero_focus ?: 'auto';

        // Prefer the pending draft if one exists. Draft encoding:
        //   { "enabled": true,  ...payload } → studio shows draft, toggle on
        //   { "enabled": false }             → studio shows blank, toggle off
        //   null                             → no draft; fall back to live
        $draft = $site->home_hero_scene_draft;
        $live = $site->home_hero_scene;

        if (is_array($draft)) {
            $enabled = (bool) ($draft['enabled'] ?? false);
            $this->sceneEnabled = $enabled;
            if ($enabled) {
                $payload = $draft;
                unset($payload['enabled']);
                if (! empty($payload['slides'])) {
                    $this->scene = array_replace($this->scene, $payload);
                }
            } elseif (is_array($live) && ! empty($live['slides'])) {
                // Draft says "off" but a live scene exists — keep the live
                // slides loaded so toggling back on doesn't wipe them.
                $this->scene = array_replace($this->scene, $live);
            }

            return;
        }

        if (is_array($live) && ! empty($live['slides'])) {
            $this->sceneEnabled = true;
            $this->scene = array_replace($this->scene, $live);
        }
    }

    /** Persist on every nested mutation — wire:model.blur fires updated(). */
    public function updated(string $name): void
    {
        if (str_starts_with($name, 'scene.') || $name === 'sceneEnabled') {
            $this->persist();
        }
        if ($name === 'heroFocus') {
            $this->persistHeroFocus();
        }
    }

    /** Persist the working scene back onto the site. */
    private function persist(?Site $site = null): void
    {
        $site = $site ?: $this->findAuthorizedSite();
        if (! $site) {
            return;
        }

        // Trim transitions to match slide count - 1.
        $needed = max(0, count($this->scene['slides']) - 1);
        if (count($this->scene['transitions']) < $needed) {
            $this->scene['transitions'] = array_pad(
                $this->scene['transitions'],
                $needed,
                ['type' => 'fade', 'duration_secs' => 1.0],
            );
        } elseif (count($this->scene['transitions']) > $needed) {
            $this->scene['transitions'] = array_slice($this->scene['transitions'], 0, $needed);
        }

        foreach ($this->scene['slides'] as $i => $slide) {
            $focus = \App\Support\Site\HeroFocus::sanitize($slide['focus'] ?? null);
            if ($focus === null) {
                unset($this->scene['slides'][$i]['focus']);
            } else {
                $this->scene['slides'][$i]['focus'] = $focus;
            }
        }

        // Writes go to the draft column; publish copies draft → live, discard
        // nulls the draft. Encoding mirrors the migration's documented shape:
        //   on:  { enabled: true, ...scene }
        //   off: { enabled: false }
        $draftPayload = $this->sceneEnabled
            ? array_merge(['enabled' => true], $this->scene)
            : ['enabled' => false];

        $site->forceFill([
            'home_hero_scene_draft' => $draftPayload,
        ])->save();

        // Don't invalidate the public-page cache here — drafts aren't visible
        // to public visitors. The cache gets bumped on publish (see
        // SitePublishService::publishSite).

        // Notify the parent page-manager so it can re-evaluate which
        // sections to render — when scene mode is on with 1+ slides the
        // legacy Hero card disappears from Content Sections (the per-
        // slide editor up here owns that copy now). Without this,
        // toggling stays out of sync until the agent refreshes.
        $this->dispatch('hero-scene-changed');
        // Nudge the unpublished-changes banner so the pending count picks up
        // the freshly-written draft without waiting for the 30s poll.
        $this->dispatch('composition-dirty');
    }

    private function persistHeroFocus(): void
    {
        $site = $this->findAuthorizedSite();
        if (! $site) {
            return;
        }

        $value = \App\Support\Site\HeroFocus::sanitize($this->heroFocus);
        $site->forceFill([
            'hero_focus' => ($value === null || $value === 'auto') ? null : $value,
        ])->save();
        $this->dispatch('composition-dirty');
    }

    /** Master toggle: scene mode on/off. */
    public function toggleSceneMode(): void
    {
        $site = $this->findAuthorizedSite();
        if (! $site) {
            return;
        }

        $wasEnabled = $this->sceneEnabled;
        $this->sceneEnabled = ! $this->sceneEnabled;

        // Auto-open the editor drawer the moment scene mode flips on so
        // the agent isn't left clicking a separate "Edit" to discover the
        // slide list. Toggling off doesn't auto-close — the drawer stays
        // dormant until the next enable.
        if (! $wasEnabled && $this->sceneEnabled) {
            $this->dispatch('open-hero-scene-editor');
        }

        // Enable behaviour:
        //  1) No slides yet — seed slide 1 from the active hero image +
        //     copy from the home page's hero section, so the slider opens
        //     reading like the current hero and the agent tweaks from there.
        //  2) Slides already exist — fill any BLANK heading/sub/cta on
        //     slide 1 from the page hero. Pre-existing agent edits are
        //     preserved (the coalesce only fills empty values). Covers the
        //     case where someone toggled on before pre-fill landed and now
        //     has an asset but no copy.
        if ($this->sceneEnabled) {
            if (empty($this->scene['slides'])) {
                $active = HeroVersion::where('site_id', $site->id)
                    ->where('page_type', 'home')
                    ->where('slot', 'hero')
                    ->where('is_active', true)
                    ->first();

                if ($active) {
                    $copy = $this->fetchHomeHeroSectionCopy($site);
                    $this->scene['slides'][] = $this->blankSlide([
                        'asset_type' => 'hero_version',
                        'asset_id' => $active->id,
                        'heading' => $copy['heading'] ?? null,
                        'subheading' => $copy['subheading'] ?? null,
                        'cta_label' => $copy['cta_label'] ?? null,
                        'text_zone' => is_array($active->placement)
                            ? ($active->placement['text_zone'] ?? 'middle-left')
                            : 'middle-left',
                    ]);
                }
            } else {
                $copy = $this->fetchHomeHeroSectionCopy($site);
                foreach (['heading', 'subheading', 'cta_label'] as $field) {
                    $current = $this->scene['slides'][0][$field] ?? null;
                    $blank = $current === null || trim((string) $current) === '';
                    if ($blank && ! empty($copy[$field])) {
                        $this->scene['slides'][0][$field] = $copy[$field];
                    }
                }
            }
        }

        $this->persist($site);
    }

    /**
     * Pull title / subtitle / cta_label out of the home page's hero section
     * so we can pre-fill slide 1 on first scene-mode enable. Reads the
     * publishedRevision first (authoritative) and falls back to the
     * GeneratedPage's content_data column for sites that haven't been
     * republished since the revision pipeline landed.
     *
     * Schema oddity: hero sections carry both a 'title' / 'subtitle'
     * naming AND legacy 'heading' / 'subheading' aliases. Coalesce both
     * so older content still maps correctly.
     *
     * @return array{heading: ?string, subheading: ?string, cta_label: ?string}
     */
    private function fetchHomeHeroSectionCopy(Site $site): array
    {
        $home = GeneratedPage::where('site_id', $site->id)
            ->where('page_type', 'home')
            ->with('publishedRevision:id,content_data')
            ->first();

        if (! $home) {
            return ['heading' => null, 'subheading' => null, 'cta_label' => null];
        }

        $content = $home->publishedRevision?->content_data
            ?? $home->content_data
            ?? [];

        $sections = is_array($content['sections'] ?? null) ? $content['sections'] : [];
        foreach ($sections as $section) {
            $name = $section['name'] ?? $section['type'] ?? null;
            if ($name !== 'hero') {
                continue;
            }
            $sd = $section['data'] ?? $section;

            return [
                'heading' => $sd['title'] ?? $sd['heading'] ?? null,
                'subheading' => $sd['subtitle'] ?? $sd['subheading'] ?? $sd['intro'] ?? null,
                'cta_label' => $sd['cta_label'] ?? $sd['button_label'] ?? null,
            ];
        }

        return ['heading' => null, 'subheading' => null, 'cta_label' => null];
    }

    /** Add a new slide (blank — no asset until the picker assigns one). */
    public function addSlide(): void
    {
        if (count($this->scene['slides']) >= self::MAX_SLIDES) {
            $this->errorMessage = 'Max '.self::MAX_SLIDES.' slides per scene.';

            return;
        }

        $this->errorMessage = null;
        $this->scene['slides'][] = $this->blankSlide();
        // Focus the new slide so the editor pane jumps to it.
        $this->selectedSlideIndex = count($this->scene['slides']) - 1;
        $this->persist();
    }

    public function removeSlide(int $index): void
    {
        if (! isset($this->scene['slides'][$index])) {
            return;
        }
        array_splice($this->scene['slides'], $index, 1);
        // Keep the focus pointer in bounds. If we deleted the last slide
        // or anything before the selected one, drop a notch.
        if ($this->selectedSlideIndex >= count($this->scene['slides'])) {
            $this->selectedSlideIndex = max(0, count($this->scene['slides']) - 1);
        }
        $this->persist();
    }

    public function moveSlide(int $from, int $to): void
    {
        $slides = $this->scene['slides'];
        if (! isset($slides[$from]) || ! isset($slides[$to])) {
            return;
        }
        $slide = array_splice($slides, $from, 1)[0];
        array_splice($slides, $to, 0, [$slide]);
        $this->scene['slides'] = $slides;
        // Follow the selected slide as it moves so the right pane keeps
        // showing the same content even if its index changed.
        if ($this->selectedSlideIndex === $from) {
            $this->selectedSlideIndex = $to;
        } elseif ($from < $this->selectedSlideIndex && $to >= $this->selectedSlideIndex) {
            $this->selectedSlideIndex--;
        } elseif ($from > $this->selectedSlideIndex && $to <= $this->selectedSlideIndex) {
            $this->selectedSlideIndex++;
        }
        $this->persist();
    }

    /** Switch the editor's right pane to a different slide. */
    public function selectSlide(int $index): void
    {
        if (! isset($this->scene['slides'][$index])) {
            return;
        }
        $this->selectedSlideIndex = $index;
    }

    /** Assign a HeroVersion as the selected slide's image. The library
     *  is always visible at the bottom of the editor — no separate
     *  modal — so this just patches the current slide and persists. */
    public function pickAsset(int $heroVersionId): void
    {
        $site = $this->findAuthorizedSite();
        if (! $site) {
            return;
        }
        if (! isset($this->scene['slides'][$this->selectedSlideIndex])) {
            return;
        }
        $version = HeroVersion::where('site_id', $site->id)->find($heroVersionId);
        if (! $version) {
            return;
        }
        $this->scene['slides'][$this->selectedSlideIndex]['asset_type'] = 'hero_version';
        $this->scene['slides'][$this->selectedSlideIndex]['asset_id'] = $version->id;
        $this->persist();
    }

    /** @return array<string,mixed> */
    private function blankSlide(array $overrides = []): array
    {
        return array_replace([
            'asset_type' => 'hero_version',
            'asset_id' => null,
            'heading' => null,
            'subheading' => null,
            'cta_label' => null,
            // cta_action: page_type slug of an internal page (e.g. 'contact',
            // 'about'). Null → fall back to the site's contact page in the
            // renderer (legacy behaviour). External URLs may follow later.
            'cta_action' => null,
            'text_zone' => 'middle-left',
            'text_color' => 'white',
            'overlay_strength' => 'medium',
            'dwell_secs' => 6,
        ], $overrides);
    }

    /**
     * Protected computed property instead of a public with() method:
     * with() is a remotely callable Livewire action whose return value
     * (Site/model rows included) would be JSON-encoded into the
     * response. #[Computed] + protected keeps it render-only; the
     * template extract()s it so variable names are unchanged.
     */
    #[Computed]
    protected function viewData(): array
    {
        $site = $this->findAuthorizedSite();
        $library = collect();
        $assetMap = [];
        $availablePages = collect();
        $ctaPages = collect();

        if ($site) {
            // Pages the CTA dropdown can link to. Published, non-archived,
            // ordered by nav order — same set the public nav surfaces.
            $ctaPages = \App\Models\GeneratedPage::where('site_id', $site->id)
                ->published()
                ->orderBy('sort_order')
                ->get(['page_type', 'nav_label']);

            // Pages with at least one hero version available to pick from.
            // Driven by the data, not a hard-coded list, so newly added
            // service pages appear automatically.
            $availablePages = HeroVersion::where('site_id', $site->id)
                ->where('slot', 'hero')
                ->distinct()
                ->orderBy('page_type')
                ->pluck('page_type');

            $query = HeroVersion::where('site_id', $site->id)
                ->where('slot', 'hero');
            if ($this->libraryPageType !== 'all') {
                $query->where('page_type', $this->libraryPageType);
            }
            $library = $query->orderByDesc('id')
                ->limit(50)
                ->get(['id', 'page_type', 'url', 'is_active', 'created_at']);

            // Build a quick id → url map so each slide row can render its
            // current thumbnail without a per-row query. Spans all page
            // types because slides can reference assets from anywhere now.
            $ids = collect($this->scene['slides'])
                ->pluck('asset_id')
                ->filter()
                ->all();
            if (! empty($ids)) {
                $rows = HeroVersion::where('site_id', $site->id)
                    ->whereIn('id', $ids)
                    ->get(['id', 'url']);
                foreach ($rows as $row) {
                    $assetMap[$row->id] = $row->url;
                }
            }
        }

        return [
            'site' => $site,
            'library' => $library,
            'assetMap' => $assetMap,
            'textZones' => self::TEXT_ZONES,
            'availablePages' => $availablePages,
            'ctaPages' => $ctaPages,
        ];
    }
};

?>

@php
    /** @see viewData() — with()-replacement, extracted to keep the original template variable names. */
    $__viewData = $this->viewData;
    extract($__viewData);
@endphp

{{-- Single root element: Livewire requires one, and a conditional root makes
     the rendered HTML start with its <!--[if BLOCK]--> marker, which breaks
     child-tag extraction in Livewire >=4.2.4. --}}
<div>
@if (! $site)
    <div class="rounded border bg-red-50 p-4 text-sm text-red-700">
        You don't have access to this site's hero scene settings.
    </div>
@else
<div class="space-y-3"
     x-data
     x-on:open-hero-scene-editor.window="$flux.modal('hero-scene-editor').show()">

    {{-- Outer panel: master toggle + status pill + Edit button.
         Slide editing has moved into the flyout drawer below — this
         panel just exposes the on/off + a quick way back into the
         editor for an existing scene. --}}
    <div class="flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
        <div>
            <h4 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Multi-slide scene</h4>
            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                Cycle 2–4 hero images on a fade. Off → single image hero (default).
            </p>
            @if ($sceneEnabled)
                <p class="mt-1 text-[11px] font-medium text-blue-600 dark:text-blue-400">
                    {{ count($scene['slides']) }} {{ \Illuminate\Support\Str::plural('slide', count($scene['slides'])) }} configured
                </p>
            @endif
            <div class="mt-2">
                <label class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 block mb-1">Copy focus (site default)</label>
                <select wire:model.live="heroFocus"
                        class="text-sm rounded-md border border-zinc-200 bg-white px-2 py-1.5 dark:bg-neutral-900 dark:border-neutral-700">
                    @foreach (self::FOCUS_OPTIONS as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="flex items-center gap-3 shrink-0">
            @if ($sceneEnabled)
                <flux:modal.trigger name="hero-scene-editor">
                    <flux:button size="sm" variant="primary" icon="pencil-square">Edit slides</flux:button>
                </flux:modal.trigger>
            @endif
            <button type="button" wire:click="toggleSceneMode"
                    class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full transition-colors
                           {{ $sceneEnabled ? 'bg-blue-600' : 'bg-zinc-300 dark:bg-neutral-700' }}">
                <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white transition-transform
                             {{ $sceneEnabled ? 'translate-x-5' : 'translate-x-0.5' }} mt-0.5"></span>
            </button>
        </div>
    </div>

    @if ($errorMessage)
        <div class="rounded border border-red-300 bg-red-50 p-2 text-xs text-red-700">{{ $errorMessage }}</div>
    @endif

    {{-- Slide editor modal — two-pane (Keynote-style):
         LEFT rail = mini slide list with selected ring + transition pills.
         RIGHT pane = editor for the selected slide (preview, copy, position,
         transition out, asset library, actions).
         No nested modals — image picking is the bottom-of-pane grid that's
         always visible. Solves the picker-z-index problem by removing the
         picker entirely. --}}
    {{-- Slide editor modal — redesigned per UX feedback:
         - Compact horizontal slide STRIP at top (96x54 thumbs) so the
           navigation is light, not a wall of preview cards.
         - Below the strip: form on the LEFT, sticky preview on the RIGHT.
         - Footer: autosave hint + Done close.
         The form is the primary surface — preview is reference, not the
         control. Mobile (<md): preview stacks above form. --}}
    <flux:modal name="hero-scene-editor" class="w-full max-w-5xl max-h-[90vh]">
        <div class="flex flex-col" style="max-height: calc(90vh - 3rem);">
            {{-- Header --}}
            <div class="shrink-0 mb-4">
                <flux:heading size="lg">Hero scene editor</flux:heading>
                <flux:subheading>Cycle 1–4 hero images, each with its own copy and position.</flux:subheading>
            </div>

            @if (! $sceneEnabled)
                <flux:callout icon="information-circle">
                    Scene mode is off. Toggle it on from the Sections panel to start editing slides.
                </flux:callout>
            @else
                @php
                    // Clamp the focus pointer to a real slide before render
                    // so a stale $selectedSlideIndex (e.g. from a previous
                    // delete) never indexes into nothing.
                    $focusIdx = isset($scene['slides'][$selectedSlideIndex])
                        ? $selectedSlideIndex
                        : (count($scene['slides']) > 0 ? 0 : null);
                    $sel = $focusIdx !== null ? $scene['slides'][$focusIdx] : null;
                    $selUrl = ($sel && $sel['asset_id'] !== null)
                        ? ($assetMap[$sel['asset_id']] ?? null)
                        : null;
                    $selZone = $sel['text_zone'] ?? 'middle-left';
                    $isLast = $focusIdx !== null && $focusIdx === count($scene['slides']) - 1;
                    [$selRow, $selCol] = explode('-', $selZone);
                    $previewVerticalClass = match ($selRow) { 'top' => 'items-start', 'bottom' => 'items-end', default => 'items-center' };
                    $previewHorizontalClass = match ($selCol) { 'right' => 'text-right', 'center' => 'text-center', default => 'text-left' };
                    $previewJustifyClass = match ($selCol) { 'right' => 'justify-end', 'center' => 'justify-center', default => 'justify-start' };
                @endphp

                {{-- Compact slide strip — horizontal scroll on mobile, fixed
                     on desktop. Each thumb is 96x54 (16:9), the selected
                     one wears a blue ring, an unassigned slide shows a
                     placeholder, and + Add sits at the end. --}}
                <div class="shrink-0 pb-3 mb-3 border-b border-zinc-200 dark:border-neutral-700">
                    <div class="flex items-center gap-2 overflow-x-auto pb-1">
                        @foreach ($scene['slides'] as $i => $slide)
                            @php
                                $thumbUrl = $slide['asset_id'] !== null ? ($assetMap[$slide['asset_id']] ?? null) : null;
                                $isFocus = $i === $focusIdx;
                            @endphp

                            <button type="button" wire:click="selectSlide({{ $i }})"
                                    @class([
                                        'group shrink-0 relative w-24 h-[54px] rounded-md overflow-hidden border-2 transition-all cursor-pointer',
                                        'border-blue-500 ring-2 ring-blue-500/40' => $isFocus,
                                        'border-zinc-200 dark:border-neutral-700 hover:border-zinc-400 dark:hover:border-neutral-500' => ! $isFocus,
                                    ])
                                    title="Slide {{ $i + 1 }}{{ ! empty($slide['heading']) ? ' — '.$slide['heading'] : '' }}">
                                @if ($thumbUrl)
                                    <img src="{{ $thumbUrl }}" alt="slide {{ $i + 1 }}" class="absolute inset-0 w-full h-full object-cover">
                                @else
                                    <div class="absolute inset-0 flex items-center justify-center bg-zinc-100 dark:bg-neutral-800 text-[10px] text-zinc-400">No image</div>
                                @endif
                                <span class="absolute top-1 left-1 text-[10px] font-bold text-white bg-black/60 rounded px-1 leading-tight">{{ $i + 1 }}</span>
                            </button>

                            {{-- Tiny transition pip between adjacent thumbs. --}}
                            @if ($i < count($scene['slides']) - 1)
                                @php $tr = $scene['transitions'][$i] ?? ['type' => 'fade', 'duration_secs' => 1.0]; @endphp
                                <span class="shrink-0 text-[10px] text-zinc-400" title="{{ $tr['type'] }} · {{ number_format((float) $tr['duration_secs'], 1) }}s">›</span>
                            @endif
                        @endforeach

                        @if (count($scene['slides']) < 4)
                            <button type="button" wire:click="addSlide"
                                    class="shrink-0 w-24 h-[54px] rounded-md border-2 border-dashed border-zinc-300 dark:border-neutral-700 text-xs font-medium text-zinc-500 hover:border-blue-400 hover:text-blue-600 transition-colors cursor-pointer flex items-center justify-center">
                                + Add
                            </button>
                        @endif
                    </div>
                </div>

                {{-- Body: single scrolling container so the image library
                     can sit below the form/preview row at FULL modal width
                     (not constrained to the form column). Form + preview
                     share a 2-col grid above; library is a full-width
                     2-row horizontal carousel below. Stacks on mobile. --}}
                <div class="flex-1 overflow-y-auto min-h-0 -mr-2 pr-2">
                <div class="grid grid-cols-1 md:grid-cols-[1fr_320px] gap-5">
                    {{-- LEFT: form --}}
                    <div class="space-y-4 order-2 md:order-1">
                        @if (! $sel)
                            <div class="rounded-lg border-2 border-dashed border-zinc-300 dark:border-neutral-700 py-12 text-center text-sm text-zinc-500">
                                No slides yet — add one from the strip above.
                            </div>
                        @else
                            <div class="flex items-center justify-between">
                                <h4 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                    Edit slide {{ $focusIdx + 1 }}
                                </h4>
                                <div class="flex items-center gap-1">
                                    @if ($focusIdx > 0)
                                        <button type="button" wire:click="moveSlide({{ $focusIdx }}, {{ $focusIdx - 1 }})"
                                                class="text-xs text-zinc-500 hover:text-zinc-800 cursor-pointer w-7 h-7 inline-flex items-center justify-center rounded hover:bg-zinc-100 dark:hover:bg-neutral-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                                                title="Move up"
                                                aria-label="Move slide {{ $focusIdx + 1 }} up">↑</button>
                                    @endif
                                    @if (! $isLast)
                                        <button type="button" wire:click="moveSlide({{ $focusIdx }}, {{ $focusIdx + 1 }})"
                                                class="text-xs text-zinc-500 hover:text-zinc-800 cursor-pointer w-7 h-7 inline-flex items-center justify-center rounded hover:bg-zinc-100 dark:hover:bg-neutral-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                                                title="Move down"
                                                aria-label="Move slide {{ $focusIdx + 1 }} down">↓</button>
                                    @endif
                                    <x-confirm-button
                                        name="remove-slide-{{ $focusIdx }}"
                                        title="Remove slide {{ $focusIdx + 1 }}?"
                                        description="The slide's overlay copy is lost. The image stays in the library."
                                        confirmLabel="Remove"
                                        confirmVariant="danger"
                                        wire:click="removeSlide({{ $focusIdx }})">
                                        <x-slot:trigger>
                                            <button type="button"
                                                    class="text-xs text-red-500 hover:text-red-700 cursor-pointer w-7 h-7 inline-flex items-center justify-center rounded hover:bg-red-50 dark:hover:bg-red-900/30 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                                                    title="Remove slide {{ $focusIdx + 1 }}"
                                                    aria-label="Remove slide {{ $focusIdx + 1 }}">✕</button>
                                        </x-slot:trigger>
                                    </x-confirm-button>
                                </div>
                            </div>

                            {{-- Heading + Subheading 4/5 left, Text Position
                                 grid 1/5 right — matches the legacy hero
                                 section editor pattern in the Sections pill
                                 so the controls feel familiar. --}}
                            <div class="flex flex-col sm:flex-row gap-4 items-start">
                                <div class="flex-1 min-w-0 space-y-3">
                                    <div>
                                        <label class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 block mb-1">Heading</label>
                                        <input type="text"
                                               wire:model.live.debounce.500ms="scene.slides.{{ $focusIdx }}.heading"
                                               placeholder="What this slide says"
                                               class="w-full text-sm rounded-md border border-zinc-200 bg-white px-3 py-2 dark:bg-neutral-900 dark:border-neutral-700">
                                    </div>

                                    <div>
                                        <label class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 block mb-1">Subheading</label>
                                        <textarea wire:model.live.debounce.500ms="scene.slides.{{ $focusIdx }}.subheading"
                                                  placeholder="Optional supporting line"
                                                  rows="3"
                                                  class="w-full text-sm rounded-md border border-zinc-200 bg-white px-3 py-2 dark:bg-neutral-900 dark:border-neutral-700"></textarea>
                                    </div>
                                </div>

                                <div class="shrink-0 space-y-3">
                                    <label class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 block mb-1">Text position</label>
                                    <div class="inline-grid grid-cols-3 gap-1 p-1.5 bg-zinc-100 dark:bg-neutral-800 rounded-lg">
                                        @foreach ($textZones as $zone)
                                            @php
                                                [$zRow, $zCol] = explode('-', $zone);
                                                $label = strtoupper(substr($zRow, 0, 1)).strtoupper(substr($zCol, 0, 1));
                                                $isActive = $selZone === $zone;
                                            @endphp
                                            <button type="button"
                                                    wire:click="$set('scene.slides.{{ $focusIdx }}.text_zone', '{{ $zone }}')"
                                                    class="w-8 h-7 rounded text-[10px] font-bold transition-all cursor-pointer
                                                           {{ $isActive
                                                                ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900 shadow-sm'
                                                                : 'bg-white text-zinc-400 hover:bg-zinc-200 dark:bg-neutral-700 dark:text-zinc-500 dark:hover:bg-neutral-600' }}"
                                                    title="{{ ucwords(str_replace('-', ' ', $zone)) }}">
                                                {{ $label }}
                                            </button>
                                        @endforeach
                                    </div>
                                    <div>
                                        <label class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 block mb-1">Copy focus</label>
                                        <select wire:model.live="scene.slides.{{ $focusIdx }}.focus"
                                                class="w-full text-sm rounded-md border border-zinc-200 bg-white px-2 py-1.5 dark:bg-neutral-900 dark:border-neutral-700">
                                            @foreach (self::FOCUS_OPTIONS as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                            </div>

                            {{-- CTA: button text + action target. Two-column on
                                 sm+; stacks on mobile. cta_action=null falls
                                 back to the site contact page in the renderer. --}}
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 block mb-1">CTA button text</label>
                                    <input type="text"
                                           wire:model.live.debounce.500ms="scene.slides.{{ $focusIdx }}.cta_label"
                                           placeholder="e.g. Get a Free Quote"
                                           class="w-full text-sm rounded-md border border-zinc-200 bg-white px-3 py-2 dark:bg-neutral-900 dark:border-neutral-700">
                                </div>
                                <div>
                                    <label class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 block mb-1">CTA action</label>
                                    <select wire:model.live="scene.slides.{{ $focusIdx }}.cta_action"
                                            class="w-full text-sm rounded-md border border-zinc-200 bg-white px-3 py-2 dark:bg-neutral-900 dark:border-neutral-700">
                                        <option value="">None (default to contact)</option>
                                        @foreach ($ctaPages as $p)
                                            <option value="{{ $p->page_type }}">{{ $p->nav_label ?: ucfirst(str_replace('-', ' ', $p->page_type)) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            {{-- Timing: dwell (slide 0 only) + transition out.
                                 Render the row on every slide — the transition
                                 controls go disabled on the last slide rather
                                 than disappearing, so opening the modal on the
                                 last slide doesn't shrink it and re-shrink the
                                 layout when you click back to slide 1. --}}
                            <div class="flex flex-col sm:flex-row sm:items-end gap-4 pt-3 border-t border-zinc-200 dark:border-neutral-700">
                                @if ($focusIdx === 0)
                                    <div>
                                        <label class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 block mb-1">Slide dwell</label>
                                        <div class="flex items-center gap-2">
                                            <input type="number" min="3" max="20" step="1"
                                                   wire:model.blur="scene.slides.{{ $focusIdx }}.dwell_secs"
                                                   class="w-20 text-sm rounded-md border border-zinc-200 bg-white px-2 py-1.5 dark:bg-neutral-900 dark:border-neutral-700">
                                            <span class="text-[11px] text-zinc-400">seconds (applies to every slide for now)</span>
                                        </div>
                                    </div>
                                @endif

                                <div @class(['opacity-50' => $isLast])>
                                    <label class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 block mb-1">
                                        @if ($isLast)
                                            Transition (last slide — none)
                                        @else
                                            Transition → slide {{ $focusIdx + 2 }}
                                        @endif
                                    </label>
                                    <div class="flex items-center gap-2">
                                        <select @disabled($isLast)
                                                wire:model.live="scene.transitions.{{ $focusIdx }}.type"
                                                class="text-sm rounded-md border border-zinc-200 bg-white px-2 py-1.5 disabled:cursor-not-allowed dark:bg-neutral-900 dark:border-neutral-700">
                                            @foreach (['fade'] as $t)
                                                <option value="{{ $t }}">{{ ucfirst($t) }}</option>
                                            @endforeach
                                        </select>
                                        <input type="number" step="0.1" min="0.2" max="2.0"
                                               @disabled($isLast)
                                               wire:model.blur="scene.transitions.{{ $focusIdx }}.duration_secs"
                                               class="w-20 text-sm rounded-md border border-zinc-200 bg-white px-2 py-1.5 disabled:cursor-not-allowed dark:bg-neutral-900 dark:border-neutral-700">
                                        <span class="text-[11px] text-zinc-400">s</span>
                                    </div>
                                </div>
                            </div>

                        @endif
                    </div>

                    {{-- RIGHT: live preview (no longer sticky — scrolls with
                         the body). Stacks above form on mobile (order-1)
                         for visual context first. --}}
                    <div class="order-1 md:order-2">
                        @if ($sel)
                            <div class="space-y-2">
                                <div class="text-[11px] font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                    Preview · slide {{ $focusIdx + 1 }}
                                </div>
                                <div class="relative aspect-video overflow-hidden rounded-lg bg-zinc-200 dark:bg-neutral-800 border border-zinc-300 dark:border-neutral-700">
                                    @if ($selUrl)
                                        <img src="{{ $selUrl }}" alt="preview" class="absolute inset-0 w-full h-full object-cover">
                                        <div class="absolute inset-0 bg-gradient-to-r from-black/70 via-black/40 to-transparent"></div>
                                    @else
                                        <div class="absolute inset-0 flex items-center justify-center text-xs text-zinc-400 px-4 text-center">No image — pick one from the library on the left</div>
                                    @endif

                                    @if ($selUrl && (! empty($sel['heading']) || ! empty($sel['subheading'])))
                                        <div class="absolute inset-0 p-4 flex {{ $previewVerticalClass }} {{ $previewJustifyClass }}">
                                            <div class="max-w-[85%] {{ $previewHorizontalClass }}">
                                                @if (! empty($sel['heading']))
                                                    <h3 class="text-sm sm:text-base font-extrabold text-white drop-shadow-[0_2px_8px_rgba(0,0,0,0.7)] leading-tight">
                                                        {{ $sel['heading'] }}
                                                    </h3>
                                                @endif
                                                @if (! empty($sel['subheading']))
                                                    <p class="mt-1 text-[11px] text-white/80 drop-shadow-[0_1px_4px_rgba(0,0,0,0.7)] line-clamp-2">
                                                        {{ $sel['subheading'] }}
                                                    </p>
                                                @endif
                                                @if (! empty($sel['cta_label']))
                                                    <span class="mt-2 inline-flex items-center gap-1 px-2.5 py-1 rounded text-[11px] font-bold text-white" style="background-color: var(--brand-accent, #f59e0b);">
                                                        {{ $sel['cta_label'] }} →
                                                    </span>
                                                @endif
                                            </div>
                                        </div>
                                    @endif
                                </div>
                                <p class="text-[11px] text-zinc-400">Live preview · updates as you type.</p>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Image library — full-width across the modal, single
                     row of horizontal-scrolling thumbs with prev/next.
                     Page dropdown filters HeroVersions by their owning
                     page so the agent can pull a strong service-page
                     hero into a home slide if it reads well, etc. --}}
                @if ($sel)
                    <div class="mt-5 pt-4 border-t border-zinc-200 dark:border-neutral-700"
                         x-data="{ scroll(d) { $refs.imageTrack.scrollBy({ left: d, behavior: 'smooth' }); } }">
                        <div class="flex items-center justify-between mb-2 gap-3 flex-wrap">
                            <label class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                Image — click a thumb to swap
                            </label>
                            <div class="flex items-center gap-3">
                                <div class="flex items-center gap-2">
                                    <span class="text-[11px] text-zinc-500 dark:text-zinc-400">Page</span>
                                    <select wire:model.live="libraryPageType"
                                            class="text-xs rounded-md border border-zinc-200 bg-white px-2 py-1 dark:bg-neutral-900 dark:border-neutral-700">
                                        <option value="all">All pages</option>
                                        @foreach ($availablePages as $page)
                                            <option value="{{ $page }}">{{ \Illuminate\Support\Str::headline($page) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="flex items-center gap-1">
                                    <button type="button" x-on:click="scroll(-272)"
                                            class="w-7 h-7 inline-flex items-center justify-center rounded-md border border-zinc-200 bg-white text-zinc-500 hover:bg-zinc-50 hover:text-zinc-800 dark:border-neutral-700 dark:bg-neutral-900 dark:text-zinc-400 dark:hover:bg-neutral-800 cursor-pointer transition-colors"
                                            aria-label="Scroll left">‹</button>
                                    <button type="button" x-on:click="scroll(272)"
                                            class="w-7 h-7 inline-flex items-center justify-center rounded-md border border-zinc-200 bg-white text-zinc-500 hover:bg-zinc-50 hover:text-zinc-800 dark:border-neutral-700 dark:bg-neutral-900 dark:text-zinc-400 dark:hover:bg-neutral-800 cursor-pointer transition-colors"
                                            aria-label="Scroll right">›</button>
                                </div>
                            </div>
                        </div>

                        @if ($library->isEmpty())
                            <p class="text-xs text-zinc-500 py-2">
                                No hero images on file for {{ $libraryPageType === 'all' ? 'this site' : 'this page' }} yet — generate one from the Images pill first, or switch the page filter.
                            </p>
                        @else
                            <div x-ref="imageTrack"
                                 role="region"
                                 aria-label="Image library, scroll horizontally"
                                 class="flex gap-2 overflow-x-auto overscroll-x-contain pb-1 scroll-smooth">
                                @foreach ($library as $version)
                                    @php $isAssigned = $sel['asset_id'] === $version->id; @endphp
                                    <button type="button" wire:click="pickAsset({{ $version->id }})"
                                            aria-label="{{ $isAssigned ? 'Current image for slide '.($selectedSlideIndex + 1) : 'Change image for slide '.($selectedSlideIndex + 1).', option '.$loop->iteration }}"
                                            @class([
                                                'relative shrink-0 w-32 aspect-video overflow-hidden rounded-lg border-2 transition-all cursor-pointer group focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400',
                                                'border-emerald-500 ring-2 ring-emerald-500' => $isAssigned,
                                                'border-zinc-200 dark:border-neutral-700 hover:border-blue-500' => ! $isAssigned,
                                            ])>
                                        <img src="{{ $version->url }}" alt="hero v{{ $version->id }}" class="w-full h-full object-cover">
                                        {{-- Page badge — useful when "All pages" is on so the
                                             agent knows where each thumb came from. --}}
                                        @if ($libraryPageType === 'all')
                                            <span class="absolute bottom-1 left-1 text-[9px] uppercase tracking-wide bg-black/60 text-white px-1 py-0.5 rounded leading-none">
                                                {{ $version->page_type }}
                                            </span>
                                        @endif
                                        @if ($isAssigned)
                                            <span class="absolute inset-0 bg-emerald-500/20 pointer-events-none"></span>
                                            <span class="absolute top-1 right-1 text-[10px] bg-emerald-500 text-white px-1.5 py-0.5 rounded font-semibold">In use</span>
                                        @elseif ($version->is_active)
                                            <span class="absolute top-1 right-1 text-[10px] bg-zinc-900/70 text-white px-1.5 py-0.5 rounded">Active</span>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
                </div>

                {{-- Footer: live autosave status + Done close. The wire:dirty
                     hook flips the indicator while a property is staged
                     locally but not yet persisted; wire:target="scene"
                     covers any nested-array update on the working copy. --}}
                <div class="shrink-0 mt-4 pt-3 border-t border-zinc-200 dark:border-neutral-700 flex items-center justify-between">
                    <div class="flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                        <span wire:loading.remove wire:target="scene" class="flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                            Autosaved
                        </span>
                        <span wire:loading wire:target="scene" class="flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                            Saving…
                        </span>
                    </div>
                    <flux:modal.close>
                        <flux:button size="sm" variant="primary">Done</flux:button>
                    </flux:modal.close>
                </div>
            @endif
        </div>
    </flux:modal>
</div>
@endif
</div>
