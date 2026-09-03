<?php

use App\Jobs\Shop\RebuildShopSnapshot;
use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Services\Site\PublicPageCache;
use App\Support\Shop\ProductFacts;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use AuthorizesSiteAccess;

    #[Locked]
    public int $siteId;

    /** @var list<array{slug: string, label: string, kind: string, show_on_card: bool, schema: string|null}> */
    public array $groups = [];

    /** @var array<string, int> */
    public array $valueCounts = [];

    public string $newLabel = '';

    public string $newKind = 'text';

    public ?string $pendingPreset = null;

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        $this->hydrateFromSite($this->assertAuthorizedSiteAccess());
    }

    public function addGroup(): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        $this->validate([
            'newLabel' => ['required', 'string', 'max:'.ProductFacts::MAX_GROUP_LABEL],
            'newKind' => ['required', 'in:pairs,text'],
        ]);

        $groups = $this->groups;
        if (count($groups) >= ProductFacts::MAX_GROUPS) {
            throw ValidationException::withMessages([
                'newLabel' => 'A store may have at most '.ProductFacts::MAX_GROUPS.' fact groups.',
            ]);
        }

        $groups[] = [
            'slug' => ProductFacts::uniqueSlug($this->newLabel, array_column($groups, 'slug')),
            'label' => trim($this->newLabel),
            'kind' => $this->newKind,
            'show_on_card' => false,
            'schema' => null,
        ];
        $this->persist($site, $groups);
        $this->newLabel = '';
        $this->newKind = 'text';
    }

    public function setGroupField(int $index, string $field, mixed $value): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        if (! isset($this->groups[$index])) {
            return;
        }

        $allowed = ['label', 'kind', 'show_on_card', 'schema'];
        if (! in_array($field, $allowed, true)) {
            return;
        }

        $groups = $this->groups;
        if ($field === 'show_on_card') {
            $groups[$index]['show_on_card'] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        } elseif ($field === 'schema') {
            $schema = is_string($value) ? $value : '';
            $groups[$index]['schema'] = $schema === '' ? null : $schema;
        } elseif ($field === 'kind') {
            if (! is_string($value) || ! in_array($value, ProductFacts::KINDS, true)) {
                return;
            }
            $previousKind = $groups[$index]['kind'];
            $slug = $groups[$index]['slug'];
            $groups[$index]['kind'] = $value;
            if ($previousKind !== $value) {
                ProductFacts::convertProductsToKind($site, $slug, $value);
            }
        } else {
            if (! is_string($value)) {
                return;
            }
            $groups[$index]['label'] = $value;
        }

        try {
            $this->persist($site, $groups);
        } catch (ValidationException) {
            $this->hydrateFromSite($site);
        }
    }

    public function moveGroup(int $index, string $direction): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        $groups = $this->groups;
        $swap = $direction === 'up' ? $index - 1 : $index + 1;
        if (! isset($groups[$index], $groups[$swap])) {
            return;
        }
        [$groups[$index], $groups[$swap]] = [$groups[$swap], $groups[$index]];
        $this->persist($site, array_values($groups));
    }

    public function removeGroup(int $index): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        if (! isset($this->groups[$index])) {
            return;
        }
        $groups = $this->groups;
        array_splice($groups, $index, 1);
        $this->persist($site, array_values($groups));
    }

    public function applyPreset(string $key): void
    {
        $this->assertAuthorizedSiteAccess();
        ProductFacts::presetGroups($key);
        if ($this->groups !== []) {
            $this->pendingPreset = $key;

            return;
        }
        $this->writePreset($key);
    }

    public function confirmApplyPreset(): void
    {
        $key = $this->pendingPreset;
        if (! is_string($key) || $key === '') {
            return;
        }
        $this->writePreset($key);
    }

    public function cancelPreset(): void
    {
        $this->assertAuthorizedSiteAccess();
        $this->pendingPreset = null;
    }

    private function writePreset(string $key): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        $this->persist($site, ProductFacts::presetGroups($key));
        $this->pendingPreset = null;
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     */
    private function persist(\App\Models\Site $site, array $groups): void
    {
        $validated = ProductFacts::validateGroups($groups);
        $site->update(['product_fact_groups' => $validated === [] ? null : $validated]);
        $this->hydrateFromSite($site->fresh() ?? $site);
        app(PublicPageCache::class)->invalidate($site);
        RebuildShopSnapshot::dispatch($site->id)->afterCommit();
    }

    private function hydrateFromSite(\App\Models\Site $site): void
    {
        $this->groups = ProductFacts::groups($site->product_fact_groups);
        $this->valueCounts = [];
        foreach ($this->groups as $group) {
            $this->valueCounts[$group['slug']] = ProductFacts::productsWithValuesCount($site, $group['slug']);
        }
    }

    public function with(): array
    {
        return [
            'presets' => ProductFacts::presets(),
            'schemas' => ProductFacts::SCHEMAS,
        ];
    }
}; ?>

<div data-livewire-component="shop.product-fact-groups">
    <p class="mb-4 text-sm text-zinc-500 dark:text-zinc-400">
        Tabs shown on product pages. Labels shoppers see come from this list. Removing a tab keeps any values already stored on products.
    </p>

    <label class="mb-4 flex flex-col gap-1">
        <span class="text-xs text-zinc-500 dark:text-zinc-400">Apply preset…</span>
        <select
            wire:change="applyPreset($event.target.value)"
            class="rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
            aria-label="Apply preset"
        >
            <option value="">Apply preset…</option>
            @foreach ($presets as $key => $preset)
                <option value="{{ $key }}">{{ $preset['label'] }}</option>
            @endforeach
        </select>
    </label>

    @if ($pendingPreset)
        <div class="mb-4 rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
            <p>Applying this preset replaces the current tab set. Product values are kept.</p>
            <div class="mt-2 flex gap-2">
                <button type="button" class="underline underline-offset-2 cursor-pointer" wire:click="confirmApplyPreset">Replace tabs</button>
                <button type="button" class="underline underline-offset-2 cursor-pointer" wire:click="cancelPreset">Cancel</button>
            </div>
        </div>
    @endif

    <div class="space-y-3">
        @foreach ($groups as $index => $group)
            <div wire:key="fact-group-{{ $group['slug'] }}" class="rounded border border-zinc-200 p-3 dark:border-neutral-700">
                <div class="flex flex-wrap items-end gap-3">
                    <label class="flex min-w-[10rem] flex-1 flex-col gap-1">
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">Label</span>
                        <input
                            type="text"
                            value="{{ $group['label'] }}"
                            maxlength="40"
                            wire:change="setGroupField({{ $index }}, 'label', $event.target.value)"
                            class="rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
                            aria-label="Fact group label"
                        >
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">Kind</span>
                        <select
                            wire:change="setGroupField({{ $index }}, 'kind', $event.target.value)"
                            class="rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
                            aria-label="Fact group kind"
                        >
                            <option value="text" @selected($group['kind'] === 'text')>Text</option>
                            <option value="pairs" @selected($group['kind'] === 'pairs')>Pairs</option>
                        </select>
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">Schema</span>
                        <select
                            wire:change="setGroupField({{ $index }}, 'schema', $event.target.value)"
                            class="rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
                            aria-label="Fact group schema"
                        >
                            <option value="" @selected($group['schema'] === null)>None</option>
                            @foreach ($schemas as $schema)
                                <option value="{{ $schema }}" @selected($group['schema'] === $schema)>{{ $schema }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            @checked($group['show_on_card'])
                            wire:change="setGroupField({{ $index }}, 'show_on_card', $event.target.checked)"
                        >
                        <span>Show on card</span>
                    </label>
                    <div class="flex gap-1">
                        <button type="button" class="text-xs underline cursor-pointer" wire:click="moveGroup({{ $index }}, 'up')" @disabled($index === 0)>Up</button>
                        <button type="button" class="text-xs underline cursor-pointer" wire:click="moveGroup({{ $index }}, 'down')" @disabled($index === count($groups) - 1)>Down</button>
                        <button
                            type="button"
                            class="text-xs underline cursor-pointer"
                            wire:click="removeGroup({{ $index }})"
                            wire:confirm="{{ (int) ($valueCounts[$group['slug']] ?? 0) }} products have values in this tab"
                        >Remove</button>
                    </div>
                </div>
                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ (int) ($valueCounts[$group['slug']] ?? 0) }} products have values in this tab
                </p>
            </div>
        @endforeach
    </div>

    <div class="mt-4 flex flex-wrap items-end gap-2">
        <label class="flex min-w-[10rem] flex-1 flex-col gap-1">
            <span class="text-xs text-zinc-500 dark:text-zinc-400">New tab label</span>
            <input wire:model="newLabel" type="text" maxlength="40" class="rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm" aria-label="New tab label">
        </label>
        <label class="flex flex-col gap-1">
            <span class="text-xs text-zinc-500 dark:text-zinc-400">Kind</span>
            <select wire:model="newKind" class="rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm" aria-label="New tab kind">
                <option value="text">Text</option>
                <option value="pairs">Pairs</option>
            </select>
        </label>
        <button type="button" class="rounded border border-zinc-300 px-3 py-1 text-sm dark:border-neutral-600 cursor-pointer" wire:click="addGroup">Add tab</button>
    </div>
    @error('newLabel')
        <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
    @enderror
</div>
