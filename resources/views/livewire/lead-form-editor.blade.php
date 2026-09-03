<?php

use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Models\GeneratedPage;
use App\Models\Site;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Admin edit surface for the home page lead_form section.
 *
 * Editable fields:
 *   - title (headline, max 60 chars)
 *   - intro (supporting line, max 200 chars)
 *   - benefits (exactly 3 trust signals, max 6 words each)
 *   - submit_label (CTA button text, max 20 chars)
 *   - extra_fields (up to FormFieldDefinition::MAX_FIELDS descriptors: name/label/type/options/required)
 *
 * Saves via CompositionService::applyAdminChange so admin_revision is bumped
 * and PublicPageCache is invalidated automatically.
 */
new class extends Component
{
    use AuthorizesSiteAccess;

    #[Locked]
    public int $siteId;

    public string $pageType = 'home';

    public string $title = '';

    public string $intro = '';

    /** @var list<string> */
    public array $benefits = ['', '', ''];

    public string $submitLabel = '';

    /**
     * Extra form fields. Each entry: {name, label, type, options, placeholder, required}.
     *
     * @var list<array{name: string, label: string, type: string, options: array, placeholder: string, required: bool}>
     */
    public array $extraFields = [];

    public bool $saved = false;

    /** Collapsed by default — matches the expand-on-click pattern of other sections. */
    public bool $open = false;

    public function toggleOpen(): void
    {
        $this->open = ! $this->open;
    }

    public function mount(int $siteId, string $pageType = 'home', bool $open = false): void
    {
        $this->siteId = $siteId;
        $this->pageType = $pageType;
        $this->open = $open;
        $this->loadFromPage();
    }

    private function loadFromPage(): void
    {
        $site = $this->findAuthorizedSite();
        if (! $site) {
            return;
        }

        $gp = $site->generatedPages()->where('page_type', $this->pageType)->first();
        if (! $gp) {
            return;
        }

        $section = $this->findLeadFormSection($gp->content_data);
        if (! $section) {
            return;
        }

        $this->title = $section['title'] ?? '';
        $this->intro = is_string($section['intro'] ?? null) ? $section['intro'] : '';
        $this->submitLabel = $section['submit_label'] ?? '';

        $benefits = $section['benefits'] ?? [];
        if (is_array($benefits)) {
            // Ensure exactly 3 entries (pad or truncate).
            $benefits = array_values(array_slice(array_merge(array_values($benefits), ['', '', '']), 0, 3));
        } else {
            $benefits = ['', '', ''];
        }
        $this->benefits = $benefits;

        $this->extraFields = [];
        foreach ($section['extra_fields'] ?? [] as $field) {
            if (! is_array($field)) {
                continue;
            }
            $this->extraFields[] = [
                'name' => $field['name'] ?? '',
                'label' => $field['label'] ?? '',
                'type' => $field['type'] ?? 'text',
                // A list, not a comma-joined string. The old text box made
                // commas structural, so "Repairs, servicing and callouts"
                // silently became three options with no way to notice.
                'options' => array_values(array_map(
                    fn ($o) => (string) $o,
                    is_array($field['options'] ?? null) ? $field['options'] : []
                )),
                'placeholder' => (string) ($field['placeholder'] ?? ''),
                'required' => (bool) ($field['required'] ?? false),
            ];
        }
    }

    public function addExtraField(): void
    {
        if (\App\Support\FormFieldDefinition::countableFieldTotal('lead_form', $this->extraFields) >= \App\Support\FormFieldDefinition::capFor('lead_form')) {
            return;
        }

        $this->extraFields[] = [
            'name' => '',
            'label' => '',
            'type' => 'text',
            'options' => [],
            'placeholder' => '',
            'required' => false,
        ];
    }

    public function removeExtraField(int $index): void
    {
        unset($this->extraFields[$index]);
        $this->extraFields = array_values($this->extraFields);
    }

    /** Append a blank option row for the client to type into. */
    public function addOption(int $fieldIndex): void
    {
        if (! isset($this->extraFields[$fieldIndex])) {
            return;
        }

        if (! is_array($this->extraFields[$fieldIndex]['options'] ?? null)) {
            $this->extraFields[$fieldIndex]['options'] = [];
        }

        // Ten is well past any real select on these forms, and stops a
        // held-down button growing the snapshot without bound.
        if (count($this->extraFields[$fieldIndex]['options']) >= 10) {
            return;
        }

        $this->extraFields[$fieldIndex]['options'][] = '';
    }

    public function removeOption(int $fieldIndex, int $optionIndex): void
    {
        if (! isset($this->extraFields[$fieldIndex]['options'][$optionIndex])) {
            return;
        }

        unset($this->extraFields[$fieldIndex]['options'][$optionIndex]);

        // Re-index: a gap would serialise as a JSON object rather than a
        // list, and the renderer's @foreach would then emit keys as values.
        $this->extraFields[$fieldIndex]['options'] = array_values(
            $this->extraFields[$fieldIndex]['options']
        );
    }

    public function moveExtraFieldUp(int $index): void
    {
        if ($index <= 0 || ! isset($this->extraFields[$index])) {
            return;
        }

        [$this->extraFields[$index - 1], $this->extraFields[$index]] = [$this->extraFields[$index], $this->extraFields[$index - 1]];
        $this->extraFields = array_values($this->extraFields);
    }

    public function moveExtraFieldDown(int $index): void
    {
        if ($index >= count($this->extraFields) - 1 || ! isset($this->extraFields[$index])) {
            return;
        }

        [$this->extraFields[$index], $this->extraFields[$index + 1]] = [$this->extraFields[$index + 1], $this->extraFields[$index]];
        $this->extraFields = array_values($this->extraFields);
    }

    public function save(): void
    {
        $site = $this->findAuthorizedSite();
        if (! $site) {
            return;
        }

        $this->validate([
            'title' => ['required', 'string', 'max:60'],
            'intro' => ['nullable', 'string', 'max:200'],
            'submitLabel' => ['nullable', 'string', 'max:20'],
            'benefits' => ['array', 'size:3'],
            'benefits.*' => ['string', 'max:60'],
            'extraFields' => ['array'],
            'extraFields.*.name' => ['required_with:extraFields.*', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'extraFields.*.label' => ['required_with:extraFields.*', 'string', 'max:40'],
            'extraFields.*.type' => ['required_with:extraFields.*', 'in:'.implode(',', \App\Support\FormFieldDefinition::TYPES)],
            'extraFields.*.options' => ['array', 'max:'.\App\Support\FormFieldDefinition::MAX_OPTIONS],
            'extraFields.*.options.*' => ['string', 'max:60'],
            'extraFields.*.placeholder' => ['nullable', 'string', 'max:100'],
            'extraFields.*.required' => ['boolean'],
        ]);

        if (\App\Support\FormFieldDefinition::countableFieldTotal('lead_form', $this->extraFields) > \App\Support\FormFieldDefinition::capFor('lead_form')) {
            $this->addError('extraFields', 'Maximum '.\App\Support\FormFieldDefinition::MAX_FIELDS.' extra fields allowed.');

            return;
        }

        $gp = $site->generatedPages()->where('page_type', $this->pageType)->first();
        if (! $gp) {
            return;
        }

        $content = $gp->content_data;
        $existingSection = $this->findLeadFormSection($content) ?? [];

        // Merge so markers and any future section keys survive. Rebuilding
        // the section from the fields this editor knows about dropped
        // message_field_migrated and resurrected a deleted Message.
        $updatedSection = array_merge($existingSection, [
            'type' => 'lead_form',
            'title' => trim($this->title),
            'intro' => trim($this->intro),
            'benefits' => array_values(array_map('trim', $this->benefits)),
            'submit_label' => trim($this->submitLabel),
            'extra_fields' => array_values(array_map(function (array $f): array {
                $field = [
                    'name' => trim($f['name']),
                    'label' => trim($f['label']),
                    'type' => $f['type'],
                    'required' => (bool) ($f['required']),
                ];

                $placeholder = trim((string) ($f['placeholder'] ?? ''));
                if ($placeholder !== '') {
                    $field['placeholder'] = $placeholder;
                }

                if (in_array($f['type'], ['select', 'radio'], true) && ! empty($f['options'])) {
                    // Blank rows are dropped, not saved: addOption() appends an
                    // empty one, and a client who adds a row then changes their
                    // mind must not ship an empty <option>.
                    $field['options'] = array_values(
                        array_filter(
                            array_map('trim', (array) $f['options']),
                            fn ($o) => $o !== ''
                        )
                    );
                }

                return $field;
            }, $this->extraFields)),
        ]);

        // Mutate content_data — handle both list (sections array) and legacy map shapes.
        if (isset($content['sections']) && is_array($content['sections'])) {
            $found = false;
            foreach ($content['sections'] as $i => $section) {
                if (is_array($section) && ($section['type'] ?? null) === 'lead_form') {
                    $content['sections'][$i] = $updatedSection;
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $content['sections'][] = $updatedSection;
            }
        } else {
            $content['lead_form'] = $updatedSection;
        }

        // Persist via applyAdminChange so admin_revision bumps and the public
        // cache is invalidated in one atomic transaction.
        app(\App\Services\Site\CompositionService::class)->applyAdminChange(
            $site,
            function () use ($gp, $content): void {
                app(\App\Services\Site\PageService::class)->replaceContent(
                    $gp,
                    $content,
                    aiGenerated: false,
                    userId: auth()->id(),
                );
            },
            userId: auth()->id(),
        );

        $this->dispatch('composition-dirty');
        $this->saved = true;
    }

    /**
     * @param  array<string, mixed>  $contentData
     * @return array<string, mixed>|null
     */
    private function findLeadFormSection(array $contentData): ?array
    {
        if (isset($contentData['sections']) && is_array($contentData['sections'])) {
            foreach ($contentData['sections'] as $section) {
                if (is_array($section) && ($section['type'] ?? null) === 'lead_form') {
                    return $section;
                }
            }

            return null;
        }

        return is_array($contentData['lead_form'] ?? null) ? $contentData['lead_form'] : null;
    }
};
?>

<div>

    {{-- Collapsed summary row — matches the expand-on-click pattern of the sections editor --}}
    @if (!$open)
        <div class="flex items-center justify-between mt-2">
            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                @if ($title)
                    <span class="font-medium text-zinc-700 dark:text-zinc-200">{{ \Illuminate\Support\Str::limit($title, 50) }}</span>
                    &middot;
                @endif
                {{ count(array_filter($benefits)) }} trust signal{{ count(array_filter($benefits)) !== 1 ? 's' : '' }}
                &middot;
                {{ count($extraFields) }} extra field{{ count($extraFields) !== 1 ? 's' : '' }}
                &middot;
                submit: <em>{{ $submitLabel ?: '—' }}</em>
            </p>
            <button
                type="button"
                wire:click="toggleOpen"
                class="text-xs font-medium text-amber-600 dark:text-amber-400 hover:text-amber-800 dark:hover:text-amber-300 underline underline-offset-2 cursor-pointer"
            >
                Edit
            </button>
        </div>
    @endif

    {{-- Expanded form --}}
    @if ($open)
    <div class="space-y-5 mt-4">

    {{-- Title --}}
    <div>
        <label class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 block mb-1">
            Headline <span class="text-zinc-400 font-normal normal-case">(max 60 chars — conversion copy, not a label)</span>
        </label>
        <input type="text" wire:model.blur="title" maxlength="60"
               class="w-full text-sm rounded-md border border-zinc-200 bg-white px-3 py-2 dark:bg-neutral-900 dark:border-neutral-700"
               placeholder="Get a free no-obligation quote today" />
        @error('title') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
    </div>

    {{-- Intro --}}
    <div>
        <label class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 block mb-1">
            Supporting Line <span class="text-zinc-400 font-normal normal-case">(what happens after submit)</span>
        </label>
        <textarea wire:model.blur="intro" rows="2" maxlength="200"
                  class="w-full text-sm rounded-md border border-zinc-200 bg-white px-3 py-2 dark:bg-neutral-900 dark:border-neutral-700"
                  placeholder="We'll get back to you within 24 hours with a no-obligation quote."></textarea>
        @error('intro') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
    </div>

    {{-- Benefits (exactly 3) --}}
    <div>
        <label class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 block mb-2">
            Trust Signals <span class="text-zinc-400 font-normal normal-case">(exactly 3, max 6 words each)</span>
        </label>
        <div class="space-y-2">
            @foreach ($benefits as $bi => $benefit)
                <div class="flex items-center gap-2">
                    <span class="text-xs text-zinc-400 w-4 flex-shrink-0">{{ $bi + 1 }}</span>
                    <input type="text" wire:model.blur="benefits.{{ $bi }}" maxlength="60"
                           class="flex-1 text-sm rounded-md border border-zinc-200 bg-white px-3 py-2 dark:bg-neutral-900 dark:border-neutral-700"
                           placeholder="{{ ['Free no-obligation quote', 'Reply within 24 hours', 'Fully insured & certified'][$bi] }}" />
                </div>
                @error("benefits.{$bi}") <p class="text-xs text-red-500 mt-0.5 ml-6">{{ $message }}</p> @enderror
            @endforeach
        </div>
    </div>

    {{-- Submit label --}}
    <div>
        <label class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 block mb-1">
            Button Text <span class="text-zinc-400 font-normal normal-case">(max 20 chars — e.g. "Get my quote")</span>
        </label>
        <input type="text" wire:model.blur="submitLabel" maxlength="20"
               class="w-full text-sm rounded-md border border-zinc-200 bg-white px-3 py-2 dark:bg-neutral-900 dark:border-neutral-700"
               placeholder="Get my quote" />
        @error('submitLabel') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
    </div>

    {{-- Extra fields --}}
    <div>
        <div class="flex items-center justify-between mb-2">
            <label class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                Extra Form Fields <span class="text-zinc-400 font-normal normal-case">(up to {{ \App\Support\FormFieldDefinition::MAX_FIELDS }} — skip name / email, those are always on the form)</span>
            </label>
            @if (\App\Support\FormFieldDefinition::countableFieldTotal('lead_form', $extraFields) < \App\Support\FormFieldDefinition::capFor('lead_form'))
                <button type="button" wire:click="addExtraField"
                        class="text-xs font-medium text-amber-600 dark:text-amber-400 hover:text-amber-800 dark:hover:text-amber-300 underline underline-offset-2 cursor-pointer">
                    + Add field
                </button>
            @endif
        </div>

        @error('extraFields') <p class="text-xs text-red-500 mb-2">{{ $message }}</p> @enderror

        @if (empty($extraFields))
            <p class="text-xs text-zinc-400 italic">No extra fields yet — click "+ Add field" to add up to 5.</p>
        @else
            <div class="space-y-3">
                @foreach ($extraFields as $fi => $field)
                    <div class="p-3 rounded-lg border border-zinc-200 dark:border-neutral-700 bg-zinc-50 dark:bg-neutral-800 space-y-3">
                        {{-- Reorder + remove controls --}}
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Field {{ $fi + 1 }}</span>
                            <div class="flex items-center gap-2">
                                @if ($fi > 0)
                                    <button type="button" wire:click="moveExtraFieldUp({{ $fi }})"
                                            class="text-xs text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200 cursor-pointer" title="Move up">↑</button>
                                @endif
                                @if ($fi < count($extraFields) - 1)
                                    <button type="button" wire:click="moveExtraFieldDown({{ $fi }})"
                                            class="text-xs text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200 cursor-pointer" title="Move down">↓</button>
                                @endif
                                <button type="button" wire:click="removeExtraField({{ $fi }})"
                                        class="text-xs text-red-500 hover:text-red-700 cursor-pointer" title="Remove">✕</button>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-xs text-zinc-400 block mb-0.5">Field name <span class="text-zinc-400">(snake_case)</span></label>
                                <input type="text" wire:model.blur="extraFields.{{ $fi }}.name"
                                       class="w-full text-xs rounded border border-zinc-200 bg-white px-2 py-1.5 font-mono dark:bg-neutral-900 dark:border-neutral-700"
                                       placeholder="service_type" />
                                @error("extraFields.{$fi}.name") <p class="text-xs text-red-500 mt-0.5">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="text-xs text-zinc-400 block mb-0.5">Label</label>
                                <input type="text" wire:model.blur="extraFields.{{ $fi }}.label" maxlength="40"
                                       class="w-full text-xs rounded border border-zinc-200 bg-white px-2 py-1.5 dark:bg-neutral-900 dark:border-neutral-700"
                                       placeholder="Service required" />
                                @error("extraFields.{$fi}.label") <p class="text-xs text-red-500 mt-0.5">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-xs text-zinc-400 block mb-0.5">Type</label>
                                <select wire:model.live="extraFields.{{ $fi }}.type"
                                        class="w-full text-xs rounded border border-zinc-200 bg-white pl-2 pr-6 py-1.5 dark:bg-neutral-900 dark:border-neutral-700">
                                    <option value="text">text</option>
                                    <option value="tel">tel</option>
                                    <option value="select">select</option>
                                    <option value="radio">radio</option>
                                    <option value="textarea">textarea</option>
                                    <option value="date">date</option>
                                </select>
                                @error("extraFields.{$fi}.type") <p class="text-xs text-red-500 mt-0.5">{{ $message }}</p> @enderror
                            </div>
                            <div class="flex items-end pb-1.5">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" wire:model.blur="extraFields.{{ $fi }}.required"
                                           class="rounded border-zinc-300 dark:border-neutral-600" />
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">Required</span>
                                </label>
                            </div>
                        </div>

                        @if (in_array($field['type'] ?? '', ['select', 'radio']))
                            <div>
                                <label class="text-xs text-zinc-400 block mb-1">Options</label>

                                <div class="space-y-1.5">
                                    @foreach ($field['options'] ?? [] as $oi => $option)
                                        <div class="flex items-center gap-2" wire:key="opt-{{ $fi }}-{{ $oi }}">
                                            <input type="text" wire:model.blur="extraFields.{{ $fi }}.options.{{ $oi }}"
                                                   class="flex-1 text-xs rounded border border-zinc-200 bg-white px-2 py-1.5 dark:bg-neutral-900 dark:border-neutral-700"
                                                   placeholder="Option {{ $oi + 1 }}" />
                                            <button type="button"
                                                    wire:click="removeOption({{ $fi }}, {{ $oi }})"
                                                    title="Remove option"
                                                    aria-label="Remove option {{ $oi + 1 }}"
                                                    class="text-zinc-400 hover:text-red-600 dark:hover:text-red-400 cursor-pointer">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                            </button>
                                        </div>
                                    @endforeach
                                </div>

                                @if (empty($field['options']))
                                    <p class="text-xs text-zinc-400 mt-1">No options yet — add the choices customers pick from.</p>
                                @endif

                                @if (count($field['options'] ?? []) < 10)
                                    <button type="button"
                                            wire:click="addOption({{ $fi }})"
                                            class="mt-1.5 text-xs text-accent hover:underline cursor-pointer">
                                        + Add option
                                    </button>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Save + Done --}}
    <div class="flex items-center gap-3 pt-1">
        <flux:button variant="primary" size="sm" wire:click="save" icon="check">
            Save Lead Form
        </flux:button>
        <flux:button variant="ghost" size="sm" wire:click="toggleOpen">
            Done
        </flux:button>
        @if ($saved)
            <span class="text-sm text-green-600 dark:text-green-400">Saved — preview updated.</span>
        @endif
    </div>
    </div>
    @endif
</div>
