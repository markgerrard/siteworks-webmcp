<?php

use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Services\PreviewSnapshotWriter;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Edit geo.scope on a site's business profile. Content prompts branch
 * on this so a nationwide business doesn't get its copy stuffed with
 * its HQ town.
 */
new class extends Component
{
    use AuthorizesSiteAccess;

    #[Locked]
    public int $siteId;

    public string $scope = 'local';

    public string $serviceArea = '';

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        $site = $this->findAuthorizedSite();
        $geo = $site?->businessProfile?->profile_data['geo'] ?? [];
        $this->scope = $this->normalise($geo['scope'] ?? 'local');
        $this->serviceArea = (string) ($geo['service_area'] ?? '');
    }

    private function normalise(?string $scope): string
    {
        return in_array($scope, ['local', 'regional', 'national'], true) ? $scope : 'local';
    }

    public function save(): void
    {
        $site = $this->findAuthorizedSite();
        if (! $site || ! $site->businessProfile || ! auth()->user()?->isAgent()) {
            abort(403);
        }

        $this->scope = $this->normalise($this->scope);
        $profile = $site->businessProfile->profile_data ?? [];
        $profile['geo'] = array_merge($profile['geo'] ?? [], [
            'scope' => $this->scope,
            'service_area' => trim($this->serviceArea) !== '' ? trim($this->serviceArea) : ($profile['geo']['service_area'] ?? ''),
        ]);

        $site->businessProfile->update(['profile_data' => $profile]);

        $preview = $site->latestPreview;
        if ($preview) {
            $snapshotProfile = $profile;
            app(PreviewSnapshotWriter::class)->mutate($preview, function (&$snap) use ($snapshotProfile): void {
                $snap['profile'] = $snapshotProfile;
            });
        }

        session()->flash('scope-msg', 'Scope saved. Regenerate page content to refresh copy with the new scope.');
    }
};
?>

<div>
    @if (session('scope-msg'))
        <flux:callout variant="success" icon="check-circle" class="mb-4">
            {{ session('scope-msg') }}
        </flux:callout>
    @endif

    <div class="space-y-4">
        <div>
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-2">Geographic scope</label>
            <div class="flex gap-1 flex-wrap">
                @foreach ([
                    'local'    => ['Local',    'Serves a single town or tight radius'],
                    'regional' => ['Regional', 'Serves a county / metro area'],
                    'national' => ['National', 'Serves the whole UK or is online-only'],
                ] as $value => [$label, $hint])
                    <button type="button" wire:click="$set('scope', '{{ $value }}')"
                            @disabled(! auth()->user()?->isAgent())
                            title="{{ $hint }}"
                            @class([
                                'px-3 py-1.5 text-xs font-medium rounded-md cursor-pointer transition-colors',
                                'bg-accent text-accent-foreground' => $scope === $value,
                                'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-neutral-800 dark:text-zinc-300 dark:hover:bg-neutral-700' => $scope !== $value,
                                'opacity-50 cursor-not-allowed' => ! auth()->user()?->isAgent(),
                            ])>
                        {{ $label }}
                    </button>
                @endforeach
            </div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-2">
                @if ($scope === 'national')
                    Content prompts will drop HQ town-stuffing and write UK-wide copy.
                @elseif ($scope === 'regional')
                    Copy mentions the wider region rather than a single town.
                @else
                    Copy is optimised around the site's town/city for local search.
                @endif
            </p>
        </div>

        <div>
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Service area</label>
            <input type="text" wire:model="serviceArea"
                   placeholder="e.g. Wigan and surrounding areas, or United Kingdom (nationwide)"
                   @disabled(! auth()->user()?->isAgent())
                   class="w-full text-sm rounded-md border border-zinc-200 bg-white px-3 py-1.5 dark:bg-neutral-900 dark:border-neutral-700 dark:text-zinc-100 disabled:opacity-50">
        </div>

        @if (auth()->user()?->isAgent())
            <div class="flex justify-end">
                <flux:button wire:click="save" variant="primary" size="sm" icon="check">Save scope</flux:button>
            </div>
        @endif
    </div>
</div>
