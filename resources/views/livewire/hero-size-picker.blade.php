<?php

use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Models\Site;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use AuthorizesSiteAccess;
    #[Locked]
    public int $siteId;
    public string $homeSize = '55vh';
    public string $innerSize = '35vh';
    public bool $saved = false;

    public const SIZES = [
        '80vh' => 'Hero (80vh)',
        '65vh' => 'Full (65vh)',
        '55vh' => 'Standard (55vh)',
        '45vh' => 'Medium (45vh)',
        '35vh' => 'Compact (35vh)',
        '25vh' => 'Slim (25vh)',
    ];

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        $site = $this->findAuthorizedSite();
        // Prefer BusinessProfile (the versioned renderer's source of truth);
        // fall back to the legacy Preview.snapshot for sites generated before
        // hero_sizes moved to profile_data.
        $profile = $site?->businessProfile?->profile_data ?? [];
        $sizes = $profile['hero_sizes']
            ?? ($site?->latestPreview?->snapshot['hero_sizes'] ?? []);
        $this->homeSize = $sizes['home'] ?? '55vh';
        $this->innerSize = $sizes['inner'] ?? '35vh';
    }

    public function apply(): void
    {
        $site = $this->findAuthorizedSite();
        $bp = $site?->businessProfile;
        if (! $bp) {
            return;
        }

        // Source of truth: BusinessProfile.profile_data.hero_sizes — the
        // versioned hero.blade reads from $profile. Previously this wrote
        // only to Preview.snapshot.hero_sizes which only the legacy
        // /preview/{slug} renderer consumed, so the control was a no-op on
        // the versioned public site.
        $profile = $bp->profile_data ?? [];
        $profile['hero_sizes'] = [
            'home' => $this->homeSize,
            'inner' => $this->innerSize,
        ];
        $bp->update(['profile_data' => $profile]);

        // Legacy mirror so /preview/{slug} still reflects it.
        $preview = $site->latestPreview;
        if ($preview) {
            app(\App\Services\PreviewSnapshotWriter::class)->mutate($preview, function (&$snapshot) {
                $snapshot['hero_sizes'] = [
                    'home' => $this->homeSize,
                    'inner' => $this->innerSize,
                ];
            });
        }

        app(\App\Services\Site\CompositionService::class)
            ->bumpAdminRevision($site, auth()->id());
        $this->dispatch('composition-dirty');

        $this->saved = true;
    }
};
?>

<div class="space-y-4">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label class="text-sm font-medium text-zinc-500 dark:text-zinc-400 block mb-1">Homepage hero</label>
            <select wire:model="homeSize" wire:change="$set('saved', false)"
                    class="w-full text-sm rounded-md border border-zinc-200 bg-white pl-3 pr-8 py-2 dark:bg-neutral-900 dark:border-neutral-700">
                @foreach (self::SIZES as $val => $label)
                    <option value="{{ $val }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-sm font-medium text-zinc-500 dark:text-zinc-400 block mb-1">Inner page heroes</label>
            <select wire:model="innerSize" wire:change="$set('saved', false)"
                    class="w-full text-sm rounded-md border border-zinc-200 bg-white pl-3 pr-8 py-2 dark:bg-neutral-900 dark:border-neutral-700">
                @foreach (self::SIZES as $val => $label)
                    <option value="{{ $val }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div class="flex items-center gap-3">
        <flux:button variant="primary" size="sm" wire:click="apply" icon="check">
            Apply
        </flux:button>
        @if ($saved)
            <span class="text-sm text-green-600 dark:text-green-400">Saved — refresh the preview.</span>
        @endif
    </div>
</div>
