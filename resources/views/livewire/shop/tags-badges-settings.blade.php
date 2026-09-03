<?php

use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Services\Shop\ProductTagSettings;
use App\Support\Shop\AutoTagConfig;
use App\Support\Shop\ProductTagVocabulary;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use AuthorizesSiteAccess;

    private const FORM_TO_RULE = [
        'best_seller' => 'best-seller',
        'new' => 'new',
        'low_stock' => 'low-stock',
        'made_to_order' => 'made-to-order',
    ];

    #[Locked]
    public int $siteId;

    /** @var list<array{slug: string, label: string, show_as_badge: bool, tone: string}> */
    public array $tags = [];

    public string $newLabel = '';

    public string $newTone = 'accent';

    public bool $newShowAsBadge = true;

    #[Locked]
    public string $settingsRevision = '';

    /**
     * @var array<string, array{enabled: bool, label: string, show_as_badge: bool, tone: string, params: array<string, int>}>
     */
    public array $autoRules = [];

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        $this->abortUnlessShopEnabled();
        $this->hydrateFromSite();
    }

    public function addTag(): void
    {
        $site = $this->abortUnlessShopEnabled();
        $this->validate([
            'newLabel' => 'required|string|max:40',
            'newTone' => 'required|in:accent,neutral,success,warning',
            'newShowAsBadge' => 'boolean',
        ]);

        $slug = Str::slug($this->newLabel);
        if ($slug === '' || preg_match(ProductTagVocabulary::SLUG_PATTERN, $slug) !== 1) {
            throw ValidationException::withMessages(['newLabel' => 'Tag label must produce a kebab-case slug.']);
        }

        $existing = array_column($this->tags, 'slug');
        if (in_array($slug, $existing, true)) {
            throw ValidationException::withMessages(['newLabel' => 'That tag slug is already in the vocabulary.']);
        }

        $candidate = $this->tags;
        $candidate[] = [
            'slug' => $slug,
            'label' => trim($this->newLabel),
            'show_as_badge' => $this->newShowAsBadge,
            'tone' => $this->newTone,
        ];

        $this->persist($site, $candidate, $this->autoConfigPayload(), 'newLabel');

        $this->newLabel = '';
        $this->newTone = 'accent';
        $this->newShowAsBadge = true;
        $this->hydrateFromSite();
    }

    public function removeTag(int $index): void
    {
        $site = $this->abortUnlessShopEnabled();
        if (! isset($this->tags[$index])) {
            return;
        }
        unset($this->tags[$index]);
        $this->tags = array_values($this->tags);
        $this->persist($site, $this->tags, $this->autoConfigPayload(), 'tags');
        $this->hydrateFromSite();
    }

    public function updateTag(int $index): void
    {
        $site = $this->abortUnlessShopEnabled();
        if (! isset($this->tags[$index])) {
            return;
        }
        $this->validate([
            "tags.{$index}.label" => 'required|string|max:40',
            "tags.{$index}.tone" => 'required|in:accent,neutral,success,warning',
            "tags.{$index}.show_as_badge" => 'boolean',
        ]);
        $this->persist($site, $this->tags, $this->autoConfigPayload(), "tags.{$index}.label");
        $this->hydrateFromSite();
    }

    public function saveAutoRules(): void
    {
        $site = $this->abortUnlessShopEnabled();
        $this->validate([
            'autoRules.*.label' => 'required|string|max:40',
            'autoRules.*.enabled' => 'boolean',
            'autoRules.*.show_as_badge' => 'boolean',
            'autoRules.best_seller.params.n' => 'integer|min:1|max:40',
            'autoRules.best_seller.params.days' => 'integer|min:1|max:365',
            'autoRules.new.params.days' => 'integer|min:1|max:365',
            'autoRules.low_stock.params.threshold' => 'integer|min:0|max:100000',
            'autoRules.*.tone' => 'required|in:accent,neutral,success,warning',
        ]);
        $this->persist($site, $this->tags, $this->autoConfigPayload(), 'autoRules');
        $this->hydrateFromSite();
    }

    /**
     * @param  list<array{slug: string, label: string, show_as_badge: bool, tone: string}>  $tags
     * @param  array<string, mixed>  $autoTags
     */
    private function persist(\App\Models\Site $site, array $tags, array $autoTags, string $errorField): void
    {
        try {
            app(ProductTagSettings::class)->save($site, $tags, $autoTags, $this->settingsRevision);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([$errorField => $exception->getMessage()]);
        }
    }

    private function hydrateFromSite(): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        $this->tags = ProductTagVocabulary::normalize($site->product_tags);
        $this->settingsRevision = ProductTagSettings::revision($site);
        $config = AutoTagConfig::normalize($site->auto_tags);
        $this->autoRules = [];
        foreach (self::FORM_TO_RULE as $formKey => $rule) {
            $this->autoRules[$formKey] = $config[$rule];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function autoConfigPayload(): array
    {
        $payload = [];
        foreach (self::FORM_TO_RULE as $formKey => $rule) {
            $payload[$rule] = $this->autoRules[$formKey] ?? AutoTagConfig::defaults()[$rule];
        }

        return $payload;
    }
}; ?>

<div class="space-y-6 rounded-xl border border-zinc-200 p-4 dark:border-neutral-700">
    <div>
        <h3 class="font-semibold">{{ __('Tags & badges') }}</h3>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Vocabulary for product tags. Badges appear on cards and product pages.') }}</p>
    </div>

    <div class="space-y-3">
        @foreach ($tags as $index => $tag)
            <div wire:key="vocab-{{ $tag['slug'] }}" class="flex flex-col gap-2 sm:flex-row sm:items-end">
                <flux:input wire:model="tags.{{ $index }}.label" label="Label" />
                <flux:select wire:model="tags.{{ $index }}.tone" label="Tone">
                    <flux:select.option value="accent">{{ __('Accent') }}</flux:select.option>
                    <flux:select.option value="neutral">{{ __('Neutral') }}</flux:select.option>
                    <flux:select.option value="success">{{ __('Success') }}</flux:select.option>
                    <flux:select.option value="warning">{{ __('Warning') }}</flux:select.option>
                </flux:select>
                <flux:switch wire:model="tags.{{ $index }}.show_as_badge" label="Badge" />
                <flux:button wire:click="updateTag({{ $index }})" wire:target="updateTag">{{ __('Update') }}</flux:button>
                <flux:button wire:click="removeTag({{ $index }})" wire:target="removeTag" wire:confirm="Remove this tag from the vocabulary?">{{ __('Remove') }}</flux:button>
            </div>
        @endforeach
    </div>

    <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
        <flux:input wire:model="newLabel" label="New tag" placeholder="Label" />
        <flux:select wire:model="newTone" label="Tone">
            <flux:select.option value="accent">{{ __('Accent') }}</flux:select.option>
            <flux:select.option value="neutral">{{ __('Neutral') }}</flux:select.option>
            <flux:select.option value="success">{{ __('Success') }}</flux:select.option>
            <flux:select.option value="warning">{{ __('Warning') }}</flux:select.option>
        </flux:select>
        <flux:switch wire:model="newShowAsBadge" label="Badge" />
        <flux:button variant="primary" wire:click="addTag" wire:target="addTag">{{ __('Add tag') }}</flux:button>
    </div>
    <flux:error name="newLabel" />

    <div class="space-y-4 border-t border-zinc-200 pt-4 dark:border-neutral-700">
        <h4 class="font-medium">{{ __('Automatic tags') }}</h4>
        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Computed when the shop snapshot rebuilds. Never stored on the product.') }}</p>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="space-y-2 rounded-lg border border-zinc-200 p-3 dark:border-neutral-700">
                <flux:switch wire:model="autoRules.best_seller.enabled" label="Best seller" />
                <flux:input wire:model="autoRules.best_seller.label" label="Label" />
                <flux:input type="number" wire:model="autoRules.best_seller.params.n" label="Top N" min="1" max="40" />
                <flux:input type="number" wire:model="autoRules.best_seller.params.days" label="Days" min="1" max="365" />
                <flux:switch wire:model="autoRules.best_seller.show_as_badge" label="Show as badge" />
            </div>
            <div class="space-y-2 rounded-lg border border-zinc-200 p-3 dark:border-neutral-700">
                <flux:switch wire:model="autoRules.new.enabled" label="New" />
                <flux:input wire:model="autoRules.new.label" label="Label" />
                <flux:input type="number" wire:model="autoRules.new.params.days" label="Days" min="1" max="365" />
                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Clock is first publish. Older catalogues backfill from created_at, not the last save.') }}</p>
                <flux:switch wire:model="autoRules.new.show_as_badge" label="Show as badge" />
            </div>
            <div class="space-y-2 rounded-lg border border-zinc-200 p-3 dark:border-neutral-700">
                <flux:switch wire:model="autoRules.low_stock.enabled" label="Low stock" />
                <flux:input wire:model="autoRules.low_stock.label" label="Label" />
                <flux:input type="number" wire:model="autoRules.low_stock.params.threshold" label="Available threshold (on hand minus active reservations)" min="0" />
                <flux:switch wire:model="autoRules.low_stock.show_as_badge" label="Show as badge" />
            </div>
            <div class="space-y-2 rounded-lg border border-zinc-200 p-3 dark:border-neutral-700">
                <flux:switch wire:model="autoRules.made_to_order.enabled" label="Made to order" />
                <flux:input wire:model="autoRules.made_to_order.label" label="Label" />
                <flux:switch wire:model="autoRules.made_to_order.show_as_badge" label="Show as badge" />
            </div>
        </div>
        <flux:button variant="primary" wire:click="saveAutoRules" wire:target="saveAutoRules">{{ __('Save automatic tags') }}</flux:button>
    </div>
</div>
