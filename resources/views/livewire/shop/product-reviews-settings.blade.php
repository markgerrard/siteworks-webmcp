<?php

use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Services\Site\PublicPageCache;
use App\Support\Shop\ProductReviewSettings;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use AuthorizesSiteAccess;

    #[Locked]
    public int $siteId;

    public bool $enabled = false;

    public string $label = ProductReviewSettings::DEFAULT_LABEL;

    public bool $publicForm = false;

    public bool $moderate = true;

    public bool $showOnCards = true;

    public int $minReviewsForCard = 1;

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        $this->hydrateFromSite($this->abortUnlessShopEnabled());
    }

    public function setKnob(string $column, mixed $value = null): void
    {
        $site = $this->abortUnlessShopEnabled();
        $current = ProductReviewSettings::fromSite($site);

        try {
            $next = $current->merge($column, $value);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->setErrorBag($e->validator->getMessageBag());

            return;
        }

        $site->update(['reviews_settings' => $next->toArray()]);
        $this->hydrateFromSite($site->fresh());
        app(PublicPageCache::class)->invalidate($site);
    }

    private function hydrateFromSite(\App\Models\Site $site): void
    {
        $settings = ProductReviewSettings::fromSite($site);
        $this->enabled = $settings->enabled;
        $this->label = $settings->label;
        $this->publicForm = $settings->publicForm;
        $this->moderate = $settings->moderate;
        $this->showOnCards = $settings->showOnCards;
        $this->minReviewsForCard = $settings->minReviewsForCard;
    }
}; ?>

<div data-livewire-component="shop.product-reviews-settings" class="space-y-4">
    <p class="text-sm text-zinc-600 dark:text-neutral-400">{{ __('Star ratings on cards and the product page. The public form stays off unless you turn it on.') }}</p>

    <flux:checkbox wire:change="setKnob('enabled', $event.target.checked)" :checked="$enabled" label="Enable product reviews" />
    <flux:input wire:blur="setKnob('label', $event.target.value)" wire:model="label" label="Section label" maxlength="40" />
    <flux:checkbox wire:change="setKnob('show_on_cards', $event.target.checked)" :checked="$showOnCards" label="Show ratings on product cards" />
    <flux:input wire:blur="setKnob('min_reviews_for_card', $event.target.value)" wire:model="minReviewsForCard" type="number" min="1" max="99" label="Minimum reviews before stars show on cards" />
    <flux:checkbox wire:change="setKnob('public_form', $event.target.checked)" :checked="$publicForm" label="Allow shoppers to write a review" />
    <flux:checkbox wire:change="setKnob('moderate', $event.target.checked)" :checked="$moderate" label="Hold new reviews for approval" />
    <flux:error name="label" />
    <flux:error name="min_reviews_for_card" />
</div>
