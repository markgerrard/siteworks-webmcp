<?php

use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Models\Site;
use App\Services\Shop\Fulfilment\FulfilmentConfig;
use App\Services\Site\PublicPageCache;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use AuthorizesSiteAccess;

    #[Locked]
    public int $siteId;

    public bool $deliveryEnabled = false;

    public string $deliveryLabel = 'Local delivery';

    /** @var list<array{name: string, prefixesText: string, fee_cents: int|string, free_over_cents: int|string|null, lead_time: string, min_order_cents: int|string|null}> */
    public array $zones = [];

    public bool $collectEnabled = false;

    public string $collectLabel = 'Click & collect';

    public string $collectAddress = '';

    public string $collectHours = '';

    public string $collectLeadTime = '';

    public bool $shippingEnabled = false;

    public string $shippingLabel = 'Shipping';

    public string $shippingNote = '';

    public string $widgetPrompt = 'Check delivery to your postcode';

    public int $rememberDays = 30;

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        $this->abortUnlessShopEnabled();

        $config = FulfilmentConfig::fromSite(Site::query()->findOrFail($siteId));
        if ($config === null) {
            $this->zones = [$this->emptyZone()];

            return;
        }

        $raw = $config->toArray();
        $this->deliveryEnabled = $config->methodEnabled('delivery');
        $this->deliveryLabel = $config->label('delivery');
        $this->zones = [];
        foreach ($config->zones() as $zone) {
            $this->zones[] = [
                'name' => $zone['name'],
                'prefixesText' => implode(', ', $zone['prefixes']),
                'fee_cents' => $zone['fee_cents'],
                'free_over_cents' => $zone['free_over_cents'],
                'lead_time' => $zone['lead_time'],
                'min_order_cents' => $zone['min_order_cents'],
            ];
        }
        if ($this->zones === []) {
            $this->zones = [$this->emptyZone()];
        }
        $this->collectEnabled = $config->methodEnabled('collect');
        $this->collectLabel = $config->label('collect');
        $this->collectAddress = $config->collectAddress();
        $this->collectHours = $config->collectHours();
        $this->collectLeadTime = $config->collectLeadTime();
        $this->shippingEnabled = $config->methodEnabled('shipping');
        $this->shippingLabel = $config->label('shipping');
        $this->shippingNote = $config->shippingNote();
        $this->widgetPrompt = $config->widgetPrompt();
        $this->rememberDays = $config->rememberDays();
        unset($raw);
    }

    public function addZone(): void
    {
        $this->abortUnlessShopEnabled();
        $this->zones[] = $this->emptyZone();
    }

    public function removeZone(int $index): void
    {
        $this->abortUnlessShopEnabled();
        unset($this->zones[$index]);
        $this->zones = array_values($this->zones);
        if ($this->zones === []) {
            $this->zones = [$this->emptyZone()];
        }
    }

    public function save(): void
    {
        $site = $this->abortUnlessShopEnabled();

        $zones = [];
        foreach ($this->zones as $zone) {
            $prefixes = [];
            foreach (preg_split('/[,\s]+/', (string) ($zone['prefixesText'] ?? '')) as $prefix) {
                if ($prefix !== '') {
                    $prefixes[] = $prefix;
                }
            }
            $zones[] = [
                'name' => $zone['name'] ?? '',
                'prefixes' => $prefixes,
                'fee_cents' => $zone['fee_cents'] === '' || $zone['fee_cents'] === null ? 0 : $zone['fee_cents'],
                'free_over_cents' => $zone['free_over_cents'] === '' ? null : $zone['free_over_cents'],
                'lead_time' => $zone['lead_time'] ?? '',
                'min_order_cents' => $zone['min_order_cents'] === '' ? null : $zone['min_order_cents'],
            ];
        }

        $result = FulfilmentConfig::validate([
            'delivery' => [
                'enabled' => $this->deliveryEnabled,
                'label' => $this->deliveryLabel,
                'zones' => $this->deliveryEnabled ? $zones : [],
            ],
            'collect' => [
                'enabled' => $this->collectEnabled,
                'label' => $this->collectLabel,
                'address' => $this->collectAddress,
                'hours' => $this->collectHours,
                'lead_time' => $this->collectLeadTime,
            ],
            'shipping' => [
                'enabled' => $this->shippingEnabled,
                'label' => $this->shippingLabel,
                'note' => $this->shippingNote,
            ],
            'widget' => [
                'prompt' => $this->widgetPrompt,
                'remember_days' => $this->rememberDays,
            ],
        ]);

        if ($result['ok'] === false) {
            foreach ($result['errors'] as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }

            return;
        }

        $site->update(['fulfilment' => $result['value']]);
        app(PublicPageCache::class)->invalidate($site);
    }

    /**
     * @return array{name: string, prefixesText: string, fee_cents: int, free_over_cents: string, lead_time: string, min_order_cents: string}
     */
    private function emptyZone(): array
    {
        return [
            'name' => '',
            'prefixesText' => '',
            'fee_cents' => 0,
            'free_over_cents' => '',
            'lead_time' => '',
            'min_order_cents' => '',
        ];
    }
}; ?>

<div class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-neutral-700">
    <h3 class="font-semibold">{{ __('Fulfilment') }}</h3>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-neutral-700">
            <flux:checkbox wire:model.live="deliveryEnabled" label="Local delivery" />
            <flux:input wire:model="deliveryLabel" label="Delivery label" />

            @if ($deliveryEnabled)
                <div class="space-y-3">
                    <h4 class="text-sm font-medium">{{ __('Zones') }}</h4>
                    @foreach ($zones as $index => $zone)
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2" wire:key="zone-{{ $index }}">
                            <flux:input wire:model="zones.{{ $index }}.name" label="Name" />
                            <flux:input wire:model="zones.{{ $index }}.prefixesText" label="Prefixes" placeholder="SW1A, SW1, SW" />
                            <flux:input type="number" wire:model="zones.{{ $index }}.fee_cents" label="Fee (cents)" />
                            <flux:input type="number" wire:model="zones.{{ $index }}.free_over_cents" label="Free over (cents)" />
                            <flux:input wire:model="zones.{{ $index }}.lead_time" label="Lead time" maxlength="40" />
                            <flux:input type="number" wire:model="zones.{{ $index }}.min_order_cents" label="Min order (cents)" />
                            <flux:button wire:click="removeZone({{ $index }})" wire:target="removeZone">{{ __('Remove zone') }}</flux:button>
                            <flux:error name="delivery.zones.{{ $index }}.prefixes" />
                            <flux:error name="delivery.zones.{{ $index }}.name" />
                            <flux:error name="delivery.zones.{{ $index }}.fee_cents" />
                        </div>
                    @endforeach
                    <flux:button wire:click="addZone" wire:target="addZone">{{ __('Add zone') }}</flux:button>
                    <flux:error name="delivery.zones" />
                </div>
            @endif
        </div>

        <div class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-neutral-700">
            <flux:checkbox wire:model.live="collectEnabled" label="Click & collect" />
            <flux:input wire:model="collectLabel" label="Collect label" />
            <flux:input wire:model="collectAddress" label="Collect address" />
            <flux:input wire:model="collectHours" label="Hours" />
            <flux:input wire:model="collectLeadTime" label="Collect lead time" maxlength="40" />
        </div>

        <div class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-neutral-700">
            <flux:checkbox wire:model.live="shippingEnabled" label="Shipping" />
            <flux:input wire:model="shippingLabel" label="Shipping label" />
            <flux:input wire:model="shippingNote" label="Shipping note" />
        </div>

        <div class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-neutral-700">
            <h4 class="text-sm font-medium">{{ __('Postcode widget') }}</h4>
            <flux:input wire:model="widgetPrompt" label="Widget prompt" />
            <flux:input type="number" wire:model="rememberDays" label="Remember days" min="1" max="365" />
        </div>
    </div>

    <flux:button variant="primary" wire:click="save" wire:target="save">{{ __('Save fulfilment') }}</flux:button>
</div>
