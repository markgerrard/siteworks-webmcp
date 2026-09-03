<?php

use App\Exceptions\Site\PageStateException;
use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\SitePublishService;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    use AuthorizesSiteAccess;

    #[Locked]
    public int $siteId;

    public ?int $currentVersionId = null;

    /** @var array<int, array{id: int, version: int, published_at: string, published_by: string|null, publish_note: string|null}> */
    public array $versions = [];

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        abort_unless($this->findAuthorizedSite(), 403);
        $this->loadVersions();
    }

    public function restore(int $versionId): void
    {
        $site = $this->findAuthorizedSite();
        abort_unless($site, 403);

        $version = SiteVersion::find($versionId);
        if (! $version || $version->site_id !== $site->id) {
            abort(404);
        }

        try {
            app(SitePublishService::class)->rollbackToVersion($site, $version);
        } catch (PageStateException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->loadVersions();
        $this->dispatch('site-updated');
        session()->flash('success', "Site rolled back to v{$version->version}.");
    }

    protected function loadVersions(): void
    {
        $site = $this->findAuthorizedSite();
        if (! $site) {
            return;
        }

        $current = SiteVersionCurrent::where('site_id', $site->id)->first();
        $this->currentVersionId = $current?->version_id;

        $this->versions = SiteVersion::where('site_id', $site->id)
            ->with('publishedBy')
            ->orderByDesc('version')
            ->get()
            ->map(fn (SiteVersion $v) => [
                'id' => $v->id,
                'version' => $v->version,
                'published_at' => $v->published_at?->format('d M Y H:i'),
                'published_by' => $v->publishedBy?->name,
                'publish_note' => $v->publish_note,
            ])
            ->toArray();
    }
}; ?>

<div>
    @if (session('success'))
        <div class="mb-4 rounded-md bg-green-50 px-4 py-2 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-400">
            {{ session('success') }}
        </div>
    @endif

    @if (session('error'))
        <div class="mb-4 rounded-md bg-red-50 px-4 py-2 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-400">
            {{ session('error') }}
        </div>
    @endif

    @if (count($versions) <= 1)
        <p class="text-sm text-zinc-500 dark:text-zinc-400">
            Publish changes to build version history.
        </p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 dark:border-neutral-700 text-left text-zinc-500 dark:text-zinc-400">
                        <th class="pb-2 pr-4 font-medium">Version</th>
                        <th class="pb-2 pr-4 font-medium">Published</th>
                        <th class="pb-2 pr-4 font-medium">By</th>
                        <th class="pb-2 pr-4 font-medium">Note</th>
                        <th class="pb-2 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($versions as $v)
                        @php $isCurrent = $v['id'] === $currentVersionId; @endphp
                        <tr class="border-b border-zinc-100 dark:border-neutral-800 {{ $isCurrent ? 'bg-amber-50 dark:bg-amber-900/10' : '' }}">
                            <td class="py-2 pr-4 font-mono font-semibold text-zinc-900 dark:text-zinc-100">
                                v{{ $v['version'] }}
                                @if ($isCurrent)
                                    <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-800/40 dark:text-amber-300">CURRENT</span>
                                @endif
                            </td>
                            <td class="py-2 pr-4 text-zinc-600 dark:text-zinc-300">{{ $v['published_at'] ?? '—' }}</td>
                            <td class="py-2 pr-4 text-zinc-600 dark:text-zinc-300">{{ $v['published_by'] ?? '—' }}</td>
                            <td class="py-2 pr-4 text-zinc-500 dark:text-zinc-400 max-w-xs truncate">{{ $v['publish_note'] ?? '' }}</td>
                            <td class="py-2">
                                <div class="flex items-center gap-2">
                                    <a href="{{ route('site.version.preview', ['site' => $siteId, 'version' => $v['id']]) }}"
                                       target="_blank"
                                       class="text-xs text-amber-600 dark:text-amber-400 hover:underline">
                                        Preview
                                    </a>
                                    @if (! $isCurrent)
                                        <x-confirm-button
                                            name="restore-version-{{ $v['id'] }}"
                                            title="Restore to v{{ $v['version'] }}?"
                                            description="The current live version will be replaced by this snapshot on next publish."
                                            confirmLabel="Restore"
                                            confirmVariant="primary"
                                            wire:click="restore({{ $v['id'] }})">
                                            <x-slot:trigger>
                                                <button type="button"
                                                        class="text-xs text-zinc-500 hover:text-red-600 dark:text-zinc-400 dark:hover:text-red-400 cursor-pointer">
                                                    Restore
                                                </button>
                                            </x-slot:trigger>
                                        </x-confirm-button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
