<?php

use App\Enums\LogoConceptSource;
use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Livewire\Concerns\DemoUnavailable;
use App\Models\LogoConcept;
use App\Models\Site;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use AuthorizesSiteAccess;
    use DemoUnavailable;
    use \Livewire\WithFileUploads;

    #[Locked]
    public int $siteId;

    public ?int $activeVersion = null;

    /**
     * "Upload your own logo" — for clients that already have one. Becomes
     * a LogoConcept (source: uploaded, version 0 so it co-shows in the
     * always-visible baseline batch) and is auto-selected through the same
     * locked selection path as clicking a grid concept.
     *
     * SVG is deliberately rejected: scriptable content hosted verbatim on
     * the Spaces origin — same policy as manual-logo-generator uploads.
     */
    public $logoUpload = null;

    /**
     * Source concept ids currently waiting on a transparent copy.
     *
     * @var list<int>
     */
    #[Locked]
    public array $pendingTransparentIds = [];

    #[Locked]
    public int $transparentPollCount = 0;

    public const TRANSPARENT_MAX_POLLS = 40;

    /**
     * Source concept ids currently waiting on an inverted copy.
     *
     * @var list<int>
     */
    #[Locked]
    public array $pendingInvertedIds = [];

    #[Locked]
    public int $invertedPollCount = 0;

    public const INVERTED_MAX_POLLS = 40;

    public function updatedLogoUpload(): void
    {
        $this->validate([
            'logoUpload' => 'required|file|mimes:png,jpg,jpeg,webp|max:4096',
        ]);

        $site = $this->findAuthorizedSite();
        if (! $site || $this->logoUpload === null) {
            return;
        }

        $extension = strtolower($this->logoUpload->getClientOriginalExtension() ?: 'png');
        $path = sprintf('sites/%d/logo/uploaded-%s.%s', $site->id, now()->format('Ymd-His'), $extension);
        \Illuminate\Support\Facades\Storage::disk('s3')->put(
            $path,
            $this->logoUpload->get(),
            'public',
        );

        $concept = LogoConcept::create([
            'site_id' => $site->id,
            'source' => LogoConceptSource::Uploaded,
            'version' => 0,
            'path' => $path,
            'metadata' => [
                'original_filename' => $this->logoUpload->getClientOriginalName(),
            ],
        ]);

        $this->logoUpload = null;
        $this->select($concept->id);
        $this->activeVersion = 0;

        session()->flash('logo-picker-msg', 'Logo uploaded and selected.');
    }

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        $this->activeVersion = $this->defaultVersion();
    }

    private function defaultVersion(): ?int
    {
        $site = $this->findAuthorizedSite();
        if (! $site) {
            return null;
        }

        // Prefer the version containing the currently-selected concept, so
        // the grid doesn't snap away from what the site is actually using.
        $selected = $site->logoConcepts()->where('is_selected', true)->first();
        if ($selected) {
            return (int) $selected->version;
        }

        return (int) ($site->logoConcepts()->where('source', LogoConceptSource::Generated->value)->max('version') ?? 0);
    }

    public function setVersion(int $version): void
    {
        $this->activeVersion = $version;
    }

    public function select(int $conceptId): void
    {
        $site = $this->findAuthorizedSite();
        $concept = $site->logoConcepts()->find($conceptId);
        if (! $concept) {
            return;
        }

        app(\App\Services\Site\LogoSelectionService::class)
            ->select($site, $concept, auth()->id(), bumpAdmin: true);

        // Re-stamp the current preview snapshot so the header picks up the
        // new logo without rerunning the heavy content / hero pipeline.
        // Versioned renderer reads LogoConcept.is_selected live (see
        // PageRenderer::resolveLogoUrl) — this snapshot write is legacy
        // /preview/{slug} compat only. Routed via PreviewSnapshotWriter
        // so concurrent mutations don't clobber.
        $preview = $site->latestPreview;
        if ($preview) {
            $url = $concept->url();
            app(\App\Services\PreviewSnapshotWriter::class)->mutate($preview, function (&$snapshot) use ($url) {
                $snapshot['logo_url'] = $url;
            });
        }

        $this->dispatch('composition-dirty');

        session()->flash('logo-picker-msg', 'Logo selected.');
    }

    public function regenerate(): void
    {
        $this->demoUnavailable('logo concepts');
    }

    protected function demoNoticeChannel(): string
    {
        return 'logo-picker-msg';
    }

    public function makeTransparentCopy(int $conceptId): void
    {
        $this->demoUnavailable('transparent logo');
    }

    public function makeInvertedCopy(int $conceptId): void
    {
        $this->demoUnavailable('inverted logo');
    }

    public function useOnOverlay(int $conceptId): void
    {
        $site = $this->findAuthorizedSite();
        if (! $site) {
            return;
        }

        $concept = $site->logoConcepts()->find($conceptId);
        if (! $concept) {
            return;
        }

        if (($concept->metadata['transparent'] ?? false) !== true) {
            session()->flash('logo-picker-msg', 'Only transparent logos can be used on the overlay header.');

            return;
        }

        $site->update(['overlay_logo_concept_id' => $concept->id]);

        app(\App\Services\Site\CompositionService::class)
            ->bumpAdminRevision($site, auth()->id());
        $this->dispatch('composition-dirty');

        session()->flash('logo-picker-msg', 'Overlay logo set — white on the photo, original when the nav goes solid.');
    }

    public function clearOverlayLogo(): void
    {
        $site = $this->findAuthorizedSite();
        if (! $site) {
            return;
        }

        $site->update(['overlay_logo_concept_id' => null]);

        app(\App\Services\Site\CompositionService::class)
            ->bumpAdminRevision($site, auth()->id());
        $this->dispatch('composition-dirty');

        session()->flash('logo-picker-msg', 'Overlay logo cleared.');
    }

    public function checkPendingLogoCopies(): void
    {
        $this->checkTransparentResults();
        $this->checkInvertedResults();
    }

    public function checkTransparentResults(): void
    {
        if ($this->pendingTransparentIds === []) {
            return;
        }

        $site = $this->findAuthorizedSite();
        if (! $site) {
            return;
        }

        $this->transparentPollCount++;

        $readySourceIds = $site->logoConcepts()
            ->where('source', LogoConceptSource::Redraw->value)
            ->get()
            ->filter(function (LogoConcept $concept): bool {
                return ($concept->metadata['transparent'] ?? false) === true
                    && in_array((int) ($concept->metadata['source_concept_id'] ?? 0), $this->pendingTransparentIds, true);
            })
            ->map(fn (LogoConcept $concept): int => (int) $concept->metadata['source_concept_id'])
            ->all();

        if ($readySourceIds !== []) {
            $this->pendingTransparentIds = array_values(array_filter(
                $this->pendingTransparentIds,
                fn (int $id): bool => ! in_array($id, $readySourceIds, true),
            ));
            session()->flash('logo-picker-msg', 'Transparent copy ready — it appears in the list; the original stays selected.');
        }

        if ($this->pendingTransparentIds !== [] && $this->transparentPollCount >= self::TRANSPARENT_MAX_POLLS) {
            $this->pendingTransparentIds = [];
            session()->flash('logo-picker-msg', 'Transparent copy timed out — please try again.');
        }
    }

    public function checkInvertedResults(): void
    {
        if ($this->pendingInvertedIds === []) {
            return;
        }

        $site = $this->findAuthorizedSite();
        if (! $site) {
            return;
        }

        $this->invertedPollCount++;

        $readySourceIds = $site->logoConcepts()
            ->where('source', LogoConceptSource::Redraw->value)
            ->get()
            ->filter(function (LogoConcept $concept): bool {
                return ($concept->metadata['variant'] ?? null) === 'inverted'
                    && in_array((int) ($concept->metadata['inverted_of'] ?? 0), $this->pendingInvertedIds, true);
            })
            ->map(fn (LogoConcept $concept): int => (int) $concept->metadata['inverted_of'])
            ->all();

        if ($readySourceIds !== []) {
            $this->pendingInvertedIds = array_values(array_filter(
                $this->pendingInvertedIds,
                fn (int $id): bool => ! in_array($id, $readySourceIds, true),
            ));
            session()->flash('logo-picker-msg', 'Inverted copy ready — it appears in the list; the original stays selected.');
        }

        if ($this->pendingInvertedIds !== [] && $this->invertedPollCount >= self::INVERTED_MAX_POLLS) {
            $this->pendingInvertedIds = [];
            session()->flash('logo-picker-msg', 'Inverted copy timed out — please try again.');
        }
    }

    #[On('echo:logo-concepts,updated')]
    #[On('manual-logo-ready')]
    public function refresh(): void
    {
        // Livewire re-renders on every action anyway; this hook is here so
        // a future broadcast or the manual-logo-ready event (from
        // manual-logo-generator.blade.php after polling detects the job result)
        // can push updates without a manual refresh.
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
        if (! $site) {
            return [
                'allConcepts' => collect(),
                'concepts' => collect(),
                'conceptGroups' => collect(),
                'versions' => collect(),
                'hasRanking' => false,
                'overlayLogoConceptId' => null,
            ];
        }

        $all = $site->logoConcepts()
            ->orderByRaw('rank IS NULL, rank ASC')
            ->orderBy('id')
            ->get();

        $versions = $all->pluck('version')->unique()->sort()->values();

        // If activeVersion isn't one of the available versions (e.g. nothing
        // generated yet) fall back to whatever exists.
        if (! $versions->contains($this->activeVersion)) {
            $this->activeVersion = $versions->last();
        }

        // The v=0 "baseline" batch (Detected + Redraw + Trace) is
        // ALWAYS co-shown alongside whichever AI-generated batch is
        // active, so admins can compare new AI concepts against the
        // original detected logo and its enhanced variants. Without
        // this, regenerating AI logos lands the new batch at v2 / v3
        // and the baseline disappears from the picker — breaking the
        // "compare against original" story.
        $visibleVersions = $this->activeVersion === 0
            ? [0]
            : array_unique([0, $this->activeVersion]);

        $concepts = $all->whereIn('version', $visibleVersions)->values();
        $sourceOrder = collect([
            LogoConceptSource::Uploaded,
            LogoConceptSource::Detected,
            LogoConceptSource::Generated,
            LogoConceptSource::Redraw,
            LogoConceptSource::Trace,
            LogoConceptSource::Manual,
        ]);
        $conceptGroups = $sourceOrder
            ->map(fn (LogoConceptSource $source) => [
                'source' => $source,
                'concepts' => $concepts->where('source', $source)->values(),
            ])
            ->filter(fn (array $group) => $group['concepts']->isNotEmpty())
            ->values();

        return [
            'allConcepts' => $all,
            'concepts' => $concepts,
            'conceptGroups' => $conceptGroups,
            'versions' => $versions,
            'hasRanking' => $concepts->whereNotNull('rank')->isNotEmpty(),
            'overlayLogoConceptId' => $site->overlay_logo_concept_id,
        ];
    }
};
?>

@php
    /** @see viewData() — with()-replacement, extracted to keep the original template variable names. */
    $__viewData = $this->viewData;
    extract($__viewData);
@endphp

<div @if ($pendingTransparentIds !== [] || $pendingInvertedIds !== []) wire:poll.3s="checkPendingLogoCopies" @endif>
    @if (session('logo-picker-msg'))
        <flux:callout variant="success" icon="check-circle" class="mb-4">
            {{ session('logo-picker-msg') }}
        </flux:callout>
    @endif
    @if ($allConcepts->isEmpty())
        <div class="space-y-3">
            <flux:subheading>No logo concepts yet — run the pipeline first, generate one manually, or upload the client's own logo.</flux:subheading>

            <div>
                <label class="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium rounded-md border border-zinc-200 dark:border-neutral-700 cursor-pointer hover:bg-zinc-50 dark:hover:bg-neutral-800 text-zinc-700 dark:text-zinc-200">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 9l5-5 5 5M12 4v12"/></svg>
                    <span wire:loading.remove wire:target="logoUpload">Upload client logo</span>
                    <span wire:loading wire:target="logoUpload">Uploading…</span>
                    <input type="file" wire:model="logoUpload" accept=".png,.jpg,.jpeg,.webp" class="hidden" />
                </label>
                <p class="mt-1 text-[11px] text-zinc-400">PNG, JPG or WebP, up to 4&nbsp;MB. Uploading selects it as the site logo.</p>
                @error('logoUpload')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
        </div>
    @else
        <div class="space-y-4">
            @php
                // v=0 is the always-co-shown baseline batch (Detected +
                // Redraw + Trace) and doesn't get its own button —
                // selecting it would hide whichever AI batch is active.
                // Only AI-generated batches (v>=1) need a switcher.
                $aiVersions = $versions->filter(fn ($v) => $v > 0)->values();
            @endphp
            <div>
                <label class="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium rounded-md border border-zinc-200 dark:border-neutral-700 cursor-pointer hover:bg-zinc-50 dark:hover:bg-neutral-800 text-zinc-700 dark:text-zinc-200">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 9l5-5 5 5M12 4v12"/></svg>
                    <span wire:loading.remove wire:target="logoUpload">Upload client logo</span>
                    <span wire:loading wire:target="logoUpload">Uploading…</span>
                    <input type="file" wire:model="logoUpload" accept=".png,.jpg,.jpeg,.webp" class="hidden" />
                </label>
                <p class="mt-1 text-[11px] text-zinc-400">PNG, JPG or WebP, up to 4&nbsp;MB. Uploading selects it as the site logo.</p>
                @error('logoUpload')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            @if ($aiVersions->count() > 1)
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase">AI batch:</span>
                    @foreach ($aiVersions as $v)
                        @php
                            $count = $allConcepts->where('version', $v)->count();
                        @endphp
                        <button type="button" wire:click="setVersion({{ $v }})"
                                @class([
                                    'px-3 py-1 text-xs font-medium rounded-md cursor-pointer transition-colors',
                                    'bg-accent text-accent-foreground' => $activeVersion === $v,
                                    'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-neutral-800 dark:text-zinc-300 dark:hover:bg-neutral-700' => $activeVersion !== $v,
                                ])>
                            v{{ $v }} ({{ $count }})
                        </button>
                    @endforeach
                </div>
            @endif

            <div class="space-y-6">
                @foreach ($conceptGroups as $group)
                    <section class="space-y-3">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            {{ $group['source']->label() }}
                        </h3>

                        <div class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
                            @foreach ($group['concepts'] as $concept)
                                @php
                                    $url = $concept->url();
                                    $isSelected = (bool) $concept->is_selected;
                                    $rank = $concept->rank;
                                    $score = $concept->score;
                                    $reason = $concept->metadata['rank_reason'] ?? null;
                                @endphp
                                <button
                                    type="button"
                                    wire:click="select({{ $concept->id }})"
                                    class="group relative flex flex-col rounded-xl border-2 p-3 transition-all hover:shadow-md
                                           {{ $isSelected ? 'border-accent ring-2 ring-accent/50 bg-accent/10 dark:border-accent dark:ring-accent/40 dark:bg-accent/15' : 'border-zinc-200 hover:border-zinc-400 dark:border-neutral-700 dark:hover:border-neutral-500' }}"
                                    title="{{ $reason }}"
                                >
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-[10px] font-semibold uppercase tracking-wide
                                                     {{ $concept->source === LogoConceptSource::Detected ? 'text-sky-600' : 'text-zinc-500' }}">
                                            {{ $concept->source->label() }}
                                        </span>
                                        @if ($rank)
                                            <span class="text-xs font-bold text-zinc-700 bg-zinc-100 rounded-full px-2 py-0.5">
                                                #{{ $rank }}@if ($score) · {{ $score }}@endif
                                            </span>
                                        @endif
                                    </div>

                                    <div class="flex-1 flex items-center justify-center bg-white rounded-lg border border-zinc-100 min-h-[110px] p-3">
                                        <img src="{{ $url }}" alt="logo concept" class="max-h-20 max-w-full object-contain" />
                                    </div>

                                    @if ($reason)
                                        <p class="mt-2 text-[11px] text-zinc-500 line-clamp-2 text-left">{{ $reason }}</p>
                                    @endif

                                    @if ($isSelected)
                                        <span class="absolute top-2 right-2 bg-accent text-accent-foreground text-[10px] font-bold uppercase rounded-full px-2 py-0.5 shadow">
                                            Selected
                                        </span>
                                    @endif


                                    @if (($concept->metadata['transparent'] ?? false) !== true)
                                        <span role="button" tabindex="0"
                                              wire:click.stop="makeTransparentCopy({{ $concept->id }})"
                                              x-on:click.stop
                                              x-on:keydown.enter.stop="$wire.makeTransparentCopy({{ $concept->id }})"
                                              class="mt-1 inline-flex items-center gap-1 self-start text-[11px] font-medium text-sky-600 dark:text-sky-400 hover:text-sky-800 dark:hover:text-sky-300 underline underline-offset-2 cursor-pointer">
                                            @if (in_array($concept->id, $pendingTransparentIds, true))
                                                Making transparent…
                                            @else
                                                Make transparent copy
                                            @endif
                                        </span>
                                    @endif
                                    @if (($concept->metadata['variant'] ?? null) !== 'inverted')
                                        <span role="button" tabindex="0"
                                              wire:click.stop="makeInvertedCopy({{ $concept->id }})"
                                              x-on:click.stop
                                              x-on:keydown.enter.stop="$wire.makeInvertedCopy({{ $concept->id }})"
                                              class="mt-1 inline-flex items-center gap-1 self-start text-[11px] font-medium text-sky-600 dark:text-sky-400 hover:text-sky-800 dark:hover:text-sky-300 underline underline-offset-2 cursor-pointer">
                                            @if (in_array($concept->id, $pendingInvertedIds, true))
                                                Making inverted…
                                            @else
                                                Make inverted copy
                                            @endif
                                        </span>
                                    @endif
                                    @if (($concept->metadata['transparent'] ?? false) === true)
                                        <span class="mt-1 text-[10px] font-semibold uppercase tracking-wide text-emerald-600 dark:text-emerald-400">Transparent</span>
                                        @if ((int) ($overlayLogoConceptId ?? 0) === (int) $concept->id)
                                            <span role="button" tabindex="0"
                                                  wire:click.stop="clearOverlayLogo"
                                                  x-on:click.stop
                                                  x-on:keydown.enter.stop="$wire.clearOverlayLogo()"
                                                  class="mt-1 inline-flex items-center gap-1 self-start text-[11px] font-medium text-amber-600 dark:text-amber-400 hover:text-amber-800 dark:hover:text-amber-300 underline underline-offset-2 cursor-pointer">
                                                Clear overlay logo
                                            </span>
                                        @else
                                            <span role="button" tabindex="0"
                                                  wire:click.stop="useOnOverlay({{ $concept->id }})"
                                                  x-on:click.stop
                                                  x-on:keydown.enter.stop="$wire.useOnOverlay({{ $concept->id }})"
                                                  class="mt-1 inline-flex items-center gap-1 self-start text-[11px] font-medium text-sky-600 dark:text-sky-400 hover:text-sky-800 dark:hover:text-sky-300 underline underline-offset-2 cursor-pointer">
                                                Use on overlay
                                            </span>
                                        @endif
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>

            <div class="flex items-center justify-between pt-2">
                <p class="text-xs text-zinc-500">
                    @if ($hasRanking)
                        Ranked best-to-worst against the built preview.
                    @else
                        Ranking not run yet.
                    @endif
                </p>
                <div class="flex items-center gap-2">
                    @unless ($demo)
                    <flux:button wire:click="regenerate" icon="arrow-path" variant="ghost">
                        Regenerate concepts
                    </flux:button>
                    @endunless
                </div>
            </div>
        </div>
    @endif

</div>
