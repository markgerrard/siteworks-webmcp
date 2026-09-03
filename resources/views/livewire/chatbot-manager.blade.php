<?php

use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Livewire\Concerns\DemoUnavailable;
use App\Services\PreviewSnapshotWriter;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use AuthorizesSiteAccess;
    use DemoUnavailable;

    #[Locked]
    public int $siteId;

    public bool $enabled = true;

    public string $systemPrompt = '';

    /** @var array<int, string> */
    public array $welcomePills = [];

    public bool $regenerating = false;

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        $site = $this->findAuthorizedSite();
        $profile = $site?->businessProfile?->profile_data ?? [];
        $this->systemPrompt = $profile['chatbot_system_prompt'] ?? '';
        $this->enabled = $profile['chatbot_enabled'] ?? ($this->systemPrompt !== '');
        $this->welcomePills = array_values(array_filter(
            array_map('strval', $profile['chatbot_welcome_pills'] ?? []),
        ));
    }

    public function addPill(): void
    {
        if (count($this->welcomePills) >= 6) {
            return;
        }
        $this->welcomePills[] = '';
    }

    public function removePill(int $index): void
    {
        unset($this->welcomePills[$index]);
        $this->welcomePills = array_values($this->welcomePills);
    }

    public function savePills(): void
    {
        $site = $this->findAuthorizedSite();
        if (! $site || ! $site->businessProfile) {
            return;
        }

        $cleaned = array_values(array_filter(
            array_map(fn ($p) => trim((string) $p), $this->welcomePills),
            fn ($p) => $p !== '',
        ));
        $this->welcomePills = $cleaned;

        $profile = $site->businessProfile->profile_data ?? [];
        $profile['chatbot_welcome_pills'] = $cleaned;
        $site->businessProfile->update(['profile_data' => $profile]);

        $preview = $site->latestPreview;
        if ($preview) {
            app(PreviewSnapshotWriter::class)->mutate($preview, function (&$snapshot) use ($cleaned): void {
                $snapshot['chatbot']['welcome_pills'] = $cleaned;
                $snapshot['profile']['chatbot_welcome_pills'] = $cleaned;
            });
        }

        app(\App\Services\Site\CompositionService::class)
            ->bumpAdminRevision($site, auth()->id());
        $this->dispatch('composition-dirty');

        session()->flash('chatbot-msg', 'Welcome pills saved.');
    }

    public function toggleEnabled(): void
    {
        $site = $this->findAuthorizedSite();
        if (! $site || ! $site->businessProfile) {
            return;
        }

        $this->enabled = ! $this->enabled;
        $profile = $site->businessProfile->profile_data ?? [];
        $profile['chatbot_enabled'] = $this->enabled;
        $site->businessProfile->update(['profile_data' => $profile]);

        $preview = $site->latestPreview;
        if ($preview) {
            $enabled = $this->enabled;
            $prompt = $this->systemPrompt;
            app(PreviewSnapshotWriter::class)->mutate($preview, function (&$snapshot) use ($enabled, $prompt) {
                $snapshot['chatbot']['enabled'] = $enabled && $prompt !== '';
            });
        }

        app(\App\Services\Site\CompositionService::class)
            ->bumpAdminRevision($site, auth()->id());
        $this->dispatch('composition-dirty');

        session()->flash('chatbot-msg', $this->enabled ? 'Chatbot enabled.' : 'Chatbot disabled.');
    }

    public function savePrompt(): void
    {
        $site = $this->findAuthorizedSite();
        if (! $site || ! $site->businessProfile) {
            return;
        }

        $profile = $site->businessProfile->profile_data ?? [];
        $profile['chatbot_system_prompt'] = $this->systemPrompt;
        $site->businessProfile->update(['profile_data' => $profile]);

        $preview = $site->latestPreview;
        if ($preview) {
            $enabled = $this->enabled;
            $prompt = $this->systemPrompt;
            app(PreviewSnapshotWriter::class)->mutate($preview, function (&$snapshot) use ($prompt, $enabled) {
                $snapshot['chatbot']['system_prompt'] = $prompt;
                $snapshot['chatbot']['enabled'] = $enabled && $prompt !== '';
                $snapshot['profile']['chatbot_system_prompt'] = $prompt;
            });
        }

        app(\App\Services\Site\CompositionService::class)
            ->bumpAdminRevision($site, auth()->id());
        $this->dispatch('composition-dirty');

        session()->flash('chatbot-msg', 'System prompt saved.');
    }

    public function regenerate(): void
    {
        $this->demoUnavailable('chatbot prompt');
    }

    protected function demoNoticeChannel(): string
    {
        return 'chatbot-msg';
    }
};
?>

<div>
    @if (session('chatbot-msg'))
        <flux:callout variant="success" icon="check-circle" class="mb-4">
            {{ session('chatbot-msg') }}
        </flux:callout>
    @endif

    <div class="space-y-6">
        {{-- Enable toggle --}}
        <div class="flex items-start justify-between gap-4 rounded-lg border border-zinc-200 dark:border-neutral-700 p-4">
            <div>
                <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                    {{ $enabled ? 'Chatbot is live on the site' : 'Chatbot is hidden' }}
                </div>
                <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">
                    When enabled, a floating chat widget appears on every page of your site so visitors can ask questions.
                </p>
            </div>
            <button type="button" wire:click="toggleEnabled"
                    class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus:outline-none
                           {{ $enabled ? 'bg-amber-500' : 'bg-zinc-300 dark:bg-neutral-600' }}">
                <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow transition-transform
                             {{ $enabled ? 'translate-x-5' : 'translate-x-0' }}"></span>
            </button>
        </div>

        @if ($systemPrompt === '')
            <div class="rounded-lg border border-dashed border-zinc-300 dark:border-neutral-700 p-6 text-center">
                <p class="text-sm text-zinc-600 dark:text-zinc-400 mb-3">
                    No chatbot system prompt has been generated yet.
                </p>
                @unless ($demo)
                    <flux:button wire:click="regenerate" variant="primary" size="sm" icon="sparkles">
                        Generate from profile
                    </flux:button>
                @endunless
            </div>
        @else
            {{-- Welcome pills editor --}}
            <div>
                <label class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Welcome pills</label>
                <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-3">
                    Shown as clickable buttons on the very first chatbot open (before any message is sent). Keep each one short.
                </p>

                <div class="space-y-2">
                    @foreach ($welcomePills as $i => $pill)
                        <div class="flex items-center gap-2" wire:key="pill-{{ $i }}">
                            <input type="text"
                                   wire:model="welcomePills.{{ $i }}"
                                   maxlength="60"
                                   placeholder="e.g. Do you cover my area?"
                                   class="flex-1 text-sm rounded-md border border-zinc-200 bg-white px-3 py-1.5 dark:bg-neutral-900 dark:border-neutral-700 dark:text-zinc-100">
                            <button type="button" wire:click="removePill({{ $i }})"
                                    class="text-xs text-red-600 dark:text-red-400 hover:underline cursor-pointer">
                                Remove
                            </button>
                        </div>
                    @endforeach
                </div>

                <div class="flex items-center justify-between mt-3">
                    <flux:button wire:click="addPill" variant="ghost" size="sm" icon="plus"
                                 :disabled="count($welcomePills) >= 6">
                        Add pill
                    </flux:button>
                    <flux:button wire:click="savePills" variant="primary" size="sm" icon="check">
                        Save pills
                    </flux:button>
                </div>
            </div>

            {{-- System prompt editor --}}
            <div>
                <div class="flex items-center justify-between mb-2">
                    <label class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">System prompt</label>
                    @unless ($demo)
                        <flux:button wire:click="regenerate" variant="ghost" size="sm" icon="arrow-path">
                            Regenerate from profile
                        </flux:button>
                    @endunless
                </div>
                <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-2">
                    This is the full system prompt passed to the chatbot on every message. Edit to tweak tone, add/remove info, or change the CTAs.
                </p>
                <textarea wire:model="systemPrompt"
                          rows="16"
                          class="w-full rounded-md border border-zinc-200 bg-white px-3 py-2 text-sm font-mono dark:bg-neutral-900 dark:border-neutral-700 dark:text-zinc-100"></textarea>
                <div class="mt-3 flex justify-end">
                    <flux:button wire:click="savePrompt" variant="primary" size="sm" icon="check">
                        Save prompt
                    </flux:button>
                </div>
            </div>
        @endif
    </div>
</div>
