<?php

use App\Enums\SiteStatus;
use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Models\Site;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use AuthorizesSiteAccess;
    #[Locked]
    public int $siteId;
    public string $status;
    public ?string $error = null;
    public ?string $previewSlug = null;
    // Host-aware preview target — custom domain > branded preview host >
    // legacy /preview/{slug}. Mirrors the logic in sites/show.blade.php so
    // staff always land on the versioned renderer (live theme) rather than
    // the frozen Preview.snapshot served by PreviewController.
    public ?string $previewHref = null;
    public ?string $previewDisplayUrl = null;
    public int $pageCount = 0;

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        $this->refresh();
    }

    public function refresh(): void
    {
        $site = $this->findAuthorizedSite();
        if (! $site) {
            return;
        }
        $site->load(['generatedPages', 'latestPreview']);
        $this->status = $site->status->value;
        $this->error = $site->last_error;
        $this->previewSlug = $site->latestPreview?->slug;
        $this->pageCount = $site->generatedPages->count();

        $this->previewHref = null;
        $this->previewDisplayUrl = null;
        if ($this->previewSlug) {
            // Public hosts route via /_edit/view-live so the link always
            // lands on the LIVE published version — even if the browser
            // still has an edit_session cookie from an earlier Edit Live
            // session. See PublicEditExitController::viewLive.
            if ($site->custom_domain && $site->custom_domain_status === 'active') {
                $this->previewHref = 'https://'.$site->custom_domain.'/_edit/view-live';
                $this->previewDisplayUrl = $site->custom_domain;
            } elseif ($host = $site->previewHostname()) {
                $this->previewHref = 'https://'.$host.'/_edit/view-live';
                $this->previewDisplayUrl = $host;
            } else {
                $this->previewHref = route('preview.show', $this->previewSlug);
                $this->previewDisplayUrl = url('/preview/'.$this->previewSlug);
            }
        }
    }

    public function isRunning(): bool
    {
        return in_array($this->status, ['scraping', 'profiling', 'generating', 'building']);
    }
};
?>

<div @if($this->isRunning()) wire:poll.2s="refresh" @endif>
    {{-- Status badge + spinner --}}
    <div class="flex items-center gap-3">
        @if($this->isRunning())
            <div class="flex items-center gap-2">
                <svg class="h-5 w-5 animate-spin text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <flux:badge color="blue">{{ ucfirst($status) }}</flux:badge>
            </div>
            <span class="text-sm text-zinc-500">
                @switch($status)
                    @case('scraping') Scraping website... @break
                    @case('profiling') Extracting business profile... @break
                    @case('generating') Writing page content... @break
                    @case('building') Building preview... @break
                @endswitch
            </span>
        @else
            <flux:badge :color="match($status) {
                'draft' => 'zinc',
                'review' => 'amber',
                'published' => 'green',
                'failed' => 'red',
                default => 'blue',
            }">{{ ucfirst($status) }}</flux:badge>
        @endif
    </div>

    {{-- Progress steps --}}
    @if($this->isRunning() || $status === 'review')
        <div class="mt-4 flex items-center gap-1">
            @php
                $steps = ['scraping', 'profiling', 'generating', 'building', 'review'];
                $currentIndex = array_search($status, $steps);
            @endphp
            @foreach($steps as $i => $step)
                <div class="flex items-center gap-1">
                    <div class="flex h-7 w-7 items-center justify-center rounded-full text-xs font-medium
                        {{ $i < $currentIndex ? 'bg-green-100 text-green-700' : '' }}
                        {{ $i === $currentIndex && $this->isRunning() ? 'bg-blue-100 text-blue-700 ring-2 ring-blue-400' : '' }}
                        {{ $i === $currentIndex && !$this->isRunning() ? 'bg-amber-100 text-amber-700' : '' }}
                        {{ $i > $currentIndex ? 'bg-zinc-100 text-zinc-400' : '' }}
                    ">
                        @if($i < $currentIndex)
                            <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                        @else
                            {{ $i + 1 }}
                        @endif
                    </div>
                    @if($i < count($steps) - 1)
                        <div class="h-0.5 w-6 {{ $i < $currentIndex ? 'bg-green-300' : 'bg-zinc-200' }}"></div>
                    @endif
                </div>
            @endforeach
        </div>
        <div class="mt-1 flex gap-1 text-xs text-zinc-400">
            @foreach($steps as $step)
                <span class="w-7 text-center">{{ ucfirst(substr($step, 0, 3)) }}</span>
                @if(!$loop->last) <span class="w-6"></span> @endif
            @endforeach
        </div>
    @endif

    {{-- Error --}}
    @if($error)
        <div class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-800 dark:bg-red-900/20 dark:text-red-400">
            {{ $error }}
        </div>
    @endif

    {{-- Preview link (appears when done) --}}
    @if($previewHref)
        <div class="mt-4">
            <flux:button variant="primary" :href="$previewHref" target="_blank" icon="arrow-top-right-on-square">
                View site
            </flux:button>
            <flux:subheading class="mt-1">
                {{ $previewDisplayUrl }}
            </flux:subheading>
        </div>
    @endif
</div>
