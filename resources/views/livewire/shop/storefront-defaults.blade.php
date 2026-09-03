<?php

use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Services\Shop\CustomerInputDefinition;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    use AuthorizesSiteAccess;

    #[Locked]
    public int $siteId;

    /** @var list<array<string, mixed>> */
    public array $defaultCustomerInputs = [];

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        $site = $this->assertAuthorizedSiteAccess();
        $this->hydrateFromSite($site);
    }

    public function setKnob(string $column, mixed $value = null): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        if ($column !== 'default_customer_inputs') {
            return;
        }

        $normalized = CustomerInputDefinition::normalize(is_array($value) ? $value : $this->rowsForSave());
        $site->update(['default_customer_inputs' => $normalized]);
        $this->hydrateFromSite($site->fresh());
    }

    public function saveDefaults(): void
    {
        $this->setKnob('default_customer_inputs', $this->rowsForSave());
    }

    public function addInput(): void
    {
        $this->assertAuthorizedSiteAccess();
        if (count($this->defaultCustomerInputs) >= 3) {
            return;
        }
        $this->defaultCustomerInputs[] = [
            'slug' => '',
            'label' => '',
            'kind' => 'text',
            'required' => false,
            'max_chars' => 80,
            'pattern' => null,
            'optionsText' => "One\nTwo",
            'max_files' => 1,
            'help' => '',
        ];
    }

    public function applyPreset(string $key): void
    {
        $this->assertAuthorizedSiteAccess();
        if (count($this->defaultCustomerInputs) >= 3) {
            return;
        }
        foreach (CustomerInputDefinition::presets() as $preset) {
            if (($preset['key'] ?? '') !== $key) {
                continue;
            }
            $definition = $preset['definition'] ?? [];
            if (isset($definition['options']) && is_array($definition['options'])) {
                $definition['optionsText'] = implode("\n", $definition['options']);
            }
            $this->defaultCustomerInputs[] = $definition;
            $this->setKnob('default_customer_inputs', $this->rowsForSave());

            return;
        }
    }

    public function removeInput(int $index): void
    {
        $this->assertAuthorizedSiteAccess();
        unset($this->defaultCustomerInputs[$index]);
        $this->defaultCustomerInputs = array_values($this->defaultCustomerInputs);
    }

    /**
     * @param  \App\Models\Site  $site
     */
    private function hydrateFromSite($site): void
    {
        $this->defaultCustomerInputs = [];
        foreach (is_array($site->default_customer_inputs) ? $site->default_customer_inputs : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (isset($row['options']) && is_array($row['options'])) {
                $row['optionsText'] = implode("\n", $row['options']);
            }
            $this->defaultCustomerInputs[] = $row;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsForSave(): array
    {
        $rows = [];
        foreach ($this->defaultCustomerInputs as $row) {
            if (! is_array($row)) {
                continue;
            }
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($slug === '') {
                $slug = Str::slug((string) ($row['label'] ?? ''));
            }
            $options = $row['options'] ?? [];
            if (isset($row['optionsText']) && is_string($row['optionsText'])) {
                $options = array_values(array_filter(array_map(trim(...), preg_split('/\r\n|\r|\n/', $row['optionsText']) ?: [])));
            }
            $rows[] = array_merge($row, ['slug' => $slug, 'options' => $options]);
        }

        return $rows;
    }
}; ?>

<div class="space-y-4" data-livewire-component="shop.storefront-defaults">
    <flux:heading size="lg">{{ __('Default customer inputs') }}</flux:heading>
    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Copied onto new products. Labels stay editable.') }}</p>

    <div class="flex flex-wrap gap-2">
        @foreach (\App\Services\Shop\CustomerInputDefinition::presets() as $preset)
            <flux:button size="sm" variant="ghost" wire:click="applyPreset('{{ $preset['key'] }}')" wire:target="applyPreset">
                {{ $preset['label'] }}
            </flux:button>
        @endforeach
        <flux:button size="sm" wire:click="addInput" wire:target="addInput" :disabled="count($defaultCustomerInputs) >= 3">{{ __('Add input') }}</flux:button>
        <flux:button size="sm" wire:click="saveDefaults" wire:target="saveDefaults">{{ __('Save defaults') }}</flux:button>
    </div>

    @forelse ($defaultCustomerInputs as $index => $input)
        <div wire:key="default-input-{{ $index }}" class="rounded-lg border border-zinc-200 p-3 dark:border-neutral-700 space-y-3">
            <div class="flex justify-between gap-2">
                <flux:input wire:model="defaultCustomerInputs.{{ $index }}.label" label="Label" />
                <flux:button size="xs" variant="ghost" wire:click="removeInput({{ $index }})">{{ __('Remove') }}</flux:button>
            </div>
            <flux:input wire:model="defaultCustomerInputs.{{ $index }}.slug" label="Slug" />
            <flux:select wire:model.live="defaultCustomerInputs.{{ $index }}.kind" label="Kind">
                <flux:select.option value="text">Text</flux:select.option>
                <flux:select.option value="textarea">Long text</flux:select.option>
                <flux:select.option value="choice">Choice</flux:select.option>
                <flux:select.option value="image">Image</flux:select.option>
            </flux:select>
            <flux:checkbox wire:model="defaultCustomerInputs.{{ $index }}.required" label="Required" />
            @if (in_array($input['kind'] ?? 'text', ['text', 'textarea'], true))
                <flux:input wire:model="defaultCustomerInputs.{{ $index }}.max_chars" type="number" label="Max characters" />
                <flux:select wire:model="defaultCustomerInputs.{{ $index }}.pattern" label="Pattern">
                    <flux:select.option value="">None</flux:select.option>
                    @foreach (\App\Services\Shop\CustomerInputDefinition::patterns() as $key => $pattern)
                        <flux:select.option value="{{ $key }}">{{ $pattern['label'] ?? $key }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
            @if (($input['kind'] ?? '') === 'choice')
                <flux:textarea wire:model="defaultCustomerInputs.{{ $index }}.optionsText" label="Options (one per line)" rows="4" />
            @endif
            @if (($input['kind'] ?? '') === 'image')
                <flux:input wire:model="defaultCustomerInputs.{{ $index }}.max_files" type="number" label="Max files" />
            @endif
            <flux:input wire:model="defaultCustomerInputs.{{ $index }}.help" label="Help" />
        </div>
    @empty
        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No site defaults.') }}</p>
    @endforelse
</div>
