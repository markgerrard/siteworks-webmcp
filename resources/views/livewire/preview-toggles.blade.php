<?php

use App\Enums\LeadFormPolicy;
use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Services\PreviewSnapshotWriter;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Small togglebox for layout-level flags (top utility bar, lead form policy,
 * etc.). Each change is persisted in business_profile.profile_data and
 * stamped into the current preview snapshot so the change is visible
 * without a full pipeline rebuild.
 */
new class extends Component
{
    use AuthorizesSiteAccess;

    #[Locked]
    public int $siteId;

    public bool $topBarEnabled = true;

    /** @var string One of LeadFormPolicy enum values */
    public string $leadFormPolicy = 'home_services';

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        $site = $this->findAuthorizedSite();
        $profile = $site?->businessProfile?->profile_data ?? [];
        $this->topBarEnabled = (bool) ($profile['top_bar_enabled'] ?? true);
        $this->leadFormPolicy = $site?->businessProfile?->leadFormPolicy()->value ?? 'home_services';
    }

    public function toggleTopBar(): void
    {
        $this->persistToggle('top_bar_enabled', ! $this->topBarEnabled);
        $this->topBarEnabled = ! $this->topBarEnabled;
    }

    public function updateLeadFormPolicy(string $value): void
    {
        $policy = LeadFormPolicy::tryFrom($value) ?? LeadFormPolicy::Home;
        $this->persistStringField('lead_form_policy', $policy->value);
        $this->leadFormPolicy = $policy->value;
    }

    /**
     * Write $flagKey = $value (bool) into BusinessProfile.profile_data AND mirror it
     * into the current preview snapshot so versioned renders pick it up
     * without waiting for a fresh pipeline run. Then bump admin_revision so
     * the dirty banner fires.
     */
    protected function persistToggle(string $flagKey, bool $value): void
    {
        $site = $this->findAuthorizedSite();
        if (! $site || ! $site->businessProfile) {
            return;
        }

        $profile = $site->businessProfile->profile_data ?? [];
        $profile[$flagKey] = $value;
        $site->businessProfile->update(['profile_data' => $profile]);

        $preview = $site->latestPreview;
        if ($preview) {
            app(PreviewSnapshotWriter::class)->mutate($preview, function (&$snap) use ($flagKey, $value) {
                $snap[$flagKey] = $value;
            });
        }

        app(\App\Services\Site\CompositionService::class)
            ->bumpAdminRevision($site, auth()->id());
        $this->dispatch('composition-dirty');
    }

    /**
     * Write $flagKey = $value (string) into BusinessProfile.profile_data and bump revision.
     */
    protected function persistStringField(string $flagKey, string $value): void
    {
        $site = $this->findAuthorizedSite();
        if (! $site || ! $site->businessProfile) {
            return;
        }

        $profile = $site->businessProfile->profile_data ?? [];
        $profile[$flagKey] = $value;
        $site->businessProfile->update(['profile_data' => $profile]);

        app(\App\Services\Site\CompositionService::class)
            ->bumpAdminRevision($site, auth()->id());
        $this->dispatch('composition-dirty');
    }
};
?>

<div class="space-y-3">
    <div class="flex items-start justify-between gap-4 rounded-lg border border-zinc-200 dark:border-neutral-700 p-4">
        <div>
            <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                Top utility bar
            </div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">
                The strip above the header showing coverage area, accreditation badge and phone number. Turn off for a cleaner look on desktop.
            </p>
        </div>
        <button type="button" wire:click="toggleTopBar"
                class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus:outline-none
                       {{ $topBarEnabled ? 'bg-accent' : 'bg-zinc-300 dark:bg-neutral-600' }}">
            <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow transition-transform
                         {{ $topBarEnabled ? 'translate-x-5' : 'translate-x-0' }}"></span>
        </button>
    </div>

    <div class="rounded-lg border border-zinc-200 dark:border-neutral-700 p-4">
        <div class="mb-2">
            <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                Lead form placement
            </div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">
                Controls where the inline enquiry form appears. Changes take effect after the next content regeneration.
            </p>
        </div>
        <select
            wire:change="updateLeadFormPolicy($event.target.value)"
            class="mt-2 w-full rounded-md border border-zinc-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 text-sm text-zinc-900 dark:text-zinc-100 px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-amber-500"
        >
            <option value="off" @selected($leadFormPolicy === 'off')>Off (contact page only)</option>
            <option value="home" @selected($leadFormPolicy === 'home')>Home page only</option>
            <option value="home_services" @selected($leadFormPolicy === 'home_services')>Home + Service pages (recommended)</option>
            <option value="all" @selected($leadFormPolicy === 'all')>All content pages</option>
        </select>
    </div>
</div>
