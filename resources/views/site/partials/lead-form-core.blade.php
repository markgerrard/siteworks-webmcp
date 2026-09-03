<div class="{{ $cardClass }}"
                     style="{{ $cardStyle }}"
                     x-data="{ submitted: false, sending: false, error: '',
                               async submit(form) {
                                   this.sending = true; this.error = '';
                                   try {
                                       const data = Object.fromEntries(new FormData(form).entries());
                                       const res = await fetch('/enquiries', {
                                           method: 'POST',
                                           headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                                           body: JSON.stringify(data),
                                       });
                                       if (!res.ok) throw new Error('bad response');
                                       this.submitted = true;
                                   } catch (e) {
                                       this.error = 'Sorry, something went wrong — please try again or call us.';
                                   } finally { this.sending = false; }
                               } }">

@php
    $thanksHeadingClass = (($chrome['surface'] ?? null) === 'panel-inverted') ? 'text-white' : 'text-gray-900';
    $thanksCopyClass = (($chrome['surface'] ?? null) === 'panel-inverted') ? 'text-white' : 'text-gray-600';
    $requiredMarkClass = (($chrome['surface'] ?? null) === 'panel-inverted') ? 'text-red-50' : 'text-red-500';
    $thanksDiscStyle = (($chrome['surface'] ?? null) === 'panel-inverted') ? 'background-color: rgba(255,255,255,0.16);' : 'background-color: var(--brand-primary);';
@endphp
                    {{-- Thank-you state --}}
                    <div x-show="submitted" x-cloak class="text-center py-10">
                        <div class="w-16 h-16 rounded-full mx-auto mb-5 flex items-center justify-center text-white"
                             style="{{ $thanksDiscStyle }}">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                        </div>
                        <h3 class="text-2xl font-bold mb-2 {{ $thanksHeadingClass }}">Thanks — we'll be in touch.</h3>
                        <p class="{{ $thanksCopyClass }}">We've received your message and will get back to you shortly.</p>
                    </div>

                    {{-- Form posts to /enquiries (SiteEnquirySubmitController):
                         stored always, emailed to the owner only when the site
                         has enquiry_notification_email set. Labels carry `for`
                         and inputs carry a matching unique `id` (page+section
                         scoped so multiple forms on the same DOM don't
                         collide). --}}
                    @php
    // byte trap: stacked sending-span line — {{ '' }} restores the newline the inline @if…@endif swallows (D0-pinned); do not remove
    // byte trap: tiles description @if (stacked + row radio loops) must sit at column 0 — an indented directive emits its indentation (D0-pinned)
    $operatorFieldCount = 0;
    $fields = array_values(array_filter($fields, function ($field) use (&$operatorFieldCount) {
        if (! is_array($field) || empty($field['name']) || empty($field['type'])) {
            return false;
        }

        if ($field['name'] === 'message') {
            return true;
        }

        if ($operatorFieldCount >= \App\Support\FormFieldDefinition::MAX_FIELDS) {
            return false;
        }

        $operatorFieldCount++;

        return true;
    }));
    $hasMessageField = collect($fields)->contains('name', 'message');
    $inputClass = \App\Services\Site\FormChrome::inputClass($site ?? null, 'lead', $chrome['input_style'] ?? null, $chrome['surface'] ?? null);
    $labelClass = \App\Services\Site\FormChrome::labelClass($site ?? null, $chrome['input_style'] ?? null, $chrome['surface'] ?? null);
    $selectClass = \App\Services\Site\FormChrome::selectClass($site ?? null, 'lead', $chrome['input_style'] ?? null, $chrome['surface'] ?? null);
    $radioOptionClass = \App\Services\Site\FormChrome::radioOptionClass($site ?? null, 'lead', $chrome['input_style'] ?? null, $chrome['surface'] ?? null, $chrome['radio_style'] ?? null);
    $errorClass = \App\Services\Site\FormChrome::errorClass($chrome['surface'] ?? null);
                    @endphp
                    <form x-show="!submitted" @submit.prevent="submit($event.target)" class="space-y-5"{!! $formMarker !!}>
                        <input type="hidden" name="page_type" value="{{ $pageType ?? '' }}">
                        {{-- Honeypot: hidden from humans; bots that fill it get a fake success. --}}
                        <input type="text" name="website" tabindex="-1" autocomplete="off"
                               style="position: absolute; left: -9999px;" aria-hidden="true">
@if ($layout === 'stacked')
                        <div class="grid gap-5 md:grid-cols-2">
                            <div>
                                <label for="{{ $formId }}-name" class="{{ $labelClass }}">Name <span class="{{ $requiredMarkClass }}">*</span></label>
                                <input id="{{ $formId }}-name" type="text" name="name" required
                                       autocomplete="name" placeholder="Your name"
                                       class="{{ $inputClass }}"
                                       style="--tw-ring-color: var(--brand-accent);" />
                            </div>
                            <div>
                                <label for="{{ $formId }}-email" class="{{ $labelClass }}">Email <span class="{{ $requiredMarkClass }}">*</span></label>
                                <input id="{{ $formId }}-email" type="email" name="email" required
                                       autocomplete="email" inputmode="email" placeholder="your@email.com"
                                       class="{{ $inputClass }}"
                                       style="--tw-ring-color: var(--brand-accent);" />
                            </div>
                        </div>

                        {{-- the picked extras --}}
                        @foreach ($fields as $i => $field)
                            @php
                                $name = $field['name'];
                                $label = $field['label'] ?? $name;
                                $type = in_array($field['type'] ?? 'text', $allowedTypes, true) ? $field['type'] : 'text';
                                $options = is_array($field['options'] ?? null) ? $field['options'] : [];
                                $normOptions = [];
                                foreach ($options as $opt) {
                                    $normOptions[] = is_array($opt)
                                        ? [
                                            'value' => (string) ($opt['value'] ?? $opt['label'] ?? ''),
                                            'label' => (string) ($opt['label'] ?? $opt['value'] ?? ''),
                                            'description' => (string) ($opt['description'] ?? ''),
                                        ]
                                        : [
                                            'value' => (string) $opt,
                                            'label' => (string) $opt,
                                            'description' => '',
                                        ];
                                }
                                $required = ! empty($field['required']);
                                $placeholder = $field['placeholder'] ?? $label;
                                $fieldId = "{$formId}-{$name}";
                                // Best-effort autocomplete / inputmode inference from the field name.
                                $nameLower = strtolower($name);
                                $autocomplete = null;
                                $inputmode = null;
                                if ($type === 'tel' || str_contains($nameLower, 'phone') || str_contains($nameLower, 'mobile')) {
                                    $autocomplete = 'tel';
                                    $inputmode = 'tel';
                                } elseif (str_contains($nameLower, 'postcode') || str_contains($nameLower, 'postal') || str_contains($nameLower, 'zip')) {
                                    $autocomplete = 'postal-code';
                                } elseif (str_contains($nameLower, 'address') || str_contains($nameLower, 'location') || str_contains($nameLower, 'suburb') || str_contains($nameLower, 'city')) {
                                    $autocomplete = 'street-address';
                                }
                            @endphp

                            @if ($type === 'radio')
                                {{-- Radio groups are wrapped in fieldset + legend
                                     so screen-readers announce the question
                                     correctly when focus lands on any radio. --}}
                                <fieldset>
                                    <legend class="{{ $labelClass }}">
                                        {{ $label }}
                                        @if ($required)
                                            <span class="{{ $requiredMarkClass }}">*</span>
                                        @endif
                                    </legend>
                                    <div class="flex flex-wrap gap-3">
                                        @foreach ($normOptions as $j => $opt)
                                            @php
                                                $radioId = "{$fieldId}-{$j}";
                                                $optValue = $opt['value'];
                                                $optLabel = $opt['label'];
                                                $optDescription = $opt['description'];
                                            @endphp
                                            <label for="{{ $radioId }}" class="{{ $radioOptionClass }}">
                                                <input id="{{ $radioId }}" type="radio" name="{{ $name }}" value="{{ $optValue }}" @if ($required && $j === 0) required @endif class="text-current" />
                                                <span class="text-sm {{ ($chrome['surface'] ?? null) === 'panel-inverted' ? 'text-white' : 'text-gray-800' }}">{{ $optLabel }}</span>
@if (($chrome['radio_style'] ?? null) === 'tiles' && $optDescription !== '')<span class="text-xs {{ ($chrome['surface'] ?? null) === 'panel-inverted' ? 'text-white' : 'opacity-70' }}">{{ $optDescription }}</span>@endif                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>
                            @else
                                <div>
                                    <label for="{{ $fieldId }}" class="{{ $labelClass }}">
                                        {{ $label }}
                                        @if ($required)
                                            <span class="{{ $requiredMarkClass }}">*</span>
                                        @endif
                                    </label>

                                    @if ($type === 'select')
                                        <select id="{{ $fieldId }}" name="{{ $name }}" @if ($required) required @endif
                                                class="{{ $selectClass }}"
                                                style="--tw-ring-color: var(--brand-accent);">
                                            <option value="">Please choose…</option>
                                            @foreach ($normOptions as $opt)
                                                <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                                            @endforeach
                                        </select>
                                    @elseif ($type === 'textarea')
                                        <textarea id="{{ $fieldId }}" name="{{ $name }}" rows="3" @if ($required) required @endif
                                                  placeholder="{{ $placeholder }}"
                                                  class="{{ $inputClass }}"
                                                  style="--tw-ring-color: var(--brand-accent);"></textarea>
                                    @elseif ($type === 'date')
                                        <input id="{{ $fieldId }}" type="text" name="{{ $name }}"
                                               data-flatpickr
                                               @if ($required) required @endif
                                               autocomplete="off"
                                               placeholder="{{ $placeholder }}"
                                               class="{{ $inputClass }}"
                                               style="--tw-ring-color: var(--brand-accent);" />
                                    @else
                                        <input id="{{ $fieldId }}" type="{{ $type === 'tel' ? 'tel' : 'text' }}" name="{{ $name }}"
                                               @if ($required) required @endif
                                               @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
                                               @if ($inputmode) inputmode="{{ $inputmode }}" @endif
                                               placeholder="{{ $placeholder }}"
                                               class="{{ $inputClass }}"
                                               style="--tw-ring-color: var(--brand-accent);" />
                                    @endif
                                </div>
                            @endif
                        @endforeach

                        @if (! $hasMessageField && ! $messageFieldMigrated)
                            {{-- Forward-only fallback for untouched lead forms. --}}
                            <div>
                                <label for="{{ $formId }}-message" class="{{ $labelClass }}">Message <span class="{{ $requiredMarkClass }}">*</span></label>
                                <textarea id="{{ $formId }}-message" name="message" rows="4" required placeholder="How can we help?"
                                          class="{{ $inputClass }}"
                                          style="--tw-ring-color: var(--brand-accent);"></textarea>
                            </div>
                        @endif

                        <p x-show="error" x-text="error" x-cloak class="{{ $errorClass }}"></p>
                        <button type="submit" x-bind:disabled="sending"
                                class="{{ \App\Services\Site\FormChrome::submitClass($chrome['submit_style'] ?? null) }}"
                                style="background-color: var(--brand-accent); color: var(--color-text-on-accent, #ffffff); font-size: 1rem;"
                                {!! $editor('submit_label', 'plain') !!}>
                            <span x-show="!sending">{{ $submitLabel }}</span>
                            <span x-show="sending" x-cloak>Sending…</span>@if (($chrome['submit_style'] ?? null) === 'auto-arrow')<span aria-hidden="true">→</span>@endif{{ '' }}
                        </button>
@else
@php
    $rowOne = array_values(array_filter($fields, fn ($f) => ! empty($f['required']) && ($f['name'] ?? '') !== 'message'));
    $rowTwo = array_values(array_filter($fields, fn ($f) => empty($f['required']) || ($f['name'] ?? '') === 'message'));
    $rowSubmitClass = \App\Services\Site\FormChrome::submitClass('auto-arrow');
    $rowTwoOpen = $rowTwo !== [] || (! $hasMessageField && ! $messageFieldMigrated);
@endphp
<div data-band-row="1" class="grid gap-4 md:grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] items-end">
    {{-- Name + Email: the same two field groups as the stacked branch, copied verbatim (label + input + style) --}}
                            <div>
                                <label for="{{ $formId }}-name" class="{{ $labelClass }}">Name <span class="{{ $requiredMarkClass }}">*</span></label>
                                <input id="{{ $formId }}-name" type="text" name="name" required
                                       autocomplete="name" placeholder="Your name"
                                       class="{{ $inputClass }}"
                                       style="--tw-ring-color: var(--brand-accent);" />
                            </div>
                            <div>
                                <label for="{{ $formId }}-email" class="{{ $labelClass }}">Email <span class="{{ $requiredMarkClass }}">*</span></label>
                                <input id="{{ $formId }}-email" type="email" name="email" required
                                       autocomplete="email" inputmode="email" placeholder="your@email.com"
                                       class="{{ $inputClass }}"
                                       style="--tw-ring-color: var(--brand-accent);" />
                            </div>
@foreach (array_merge($rowOne, $rowTwo) as $i => $field)
@if ($i === count($rowOne))
</div>
<div data-band-row="2" class="grid gap-4 md:grid-cols-3 mt-4">
@endif
                            @php
                                $name = $field['name'];
                                $label = $field['label'] ?? $name;
                                $type = in_array($field['type'] ?? 'text', $allowedTypes, true) ? $field['type'] : 'text';
                                $options = is_array($field['options'] ?? null) ? $field['options'] : [];
                                $normOptions = [];
                                foreach ($options as $opt) {
                                    $normOptions[] = is_array($opt)
                                        ? [
                                            'value' => (string) ($opt['value'] ?? $opt['label'] ?? ''),
                                            'label' => (string) ($opt['label'] ?? $opt['value'] ?? ''),
                                            'description' => (string) ($opt['description'] ?? ''),
                                        ]
                                        : [
                                            'value' => (string) $opt,
                                            'label' => (string) $opt,
                                            'description' => '',
                                        ];
                                }
                                $required = ! empty($field['required']);
                                $placeholder = $field['placeholder'] ?? $label;
                                $fieldId = "{$formId}-{$name}";
                                // Best-effort autocomplete / inputmode inference from the field name.
                                $nameLower = strtolower($name);
                                $autocomplete = null;
                                $inputmode = null;
                                if ($type === 'tel' || str_contains($nameLower, 'phone') || str_contains($nameLower, 'mobile')) {
                                    $autocomplete = 'tel';
                                    $inputmode = 'tel';
                                } elseif (str_contains($nameLower, 'postcode') || str_contains($nameLower, 'postal') || str_contains($nameLower, 'zip')) {
                                    $autocomplete = 'postal-code';
                                } elseif (str_contains($nameLower, 'address') || str_contains($nameLower, 'location') || str_contains($nameLower, 'suburb') || str_contains($nameLower, 'city')) {
                                    $autocomplete = 'street-address';
                                }
                            @endphp

                            @if ($type === 'radio')
                                {{-- Radio groups are wrapped in fieldset + legend
                                     so screen-readers announce the question
                                     correctly when focus lands on any radio. --}}
                                <fieldset>
                                    <legend class="{{ $labelClass }}">
                                        {{ $label }}
                                        @if ($required)
                                            <span class="{{ $requiredMarkClass }}">*</span>
                                        @endif
                                    </legend>
                                    <div class="flex flex-wrap gap-3">
                                        @foreach ($normOptions as $j => $opt)
                                            @php
                                                $radioId = "{$fieldId}-{$j}";
                                                $optValue = $opt['value'];
                                                $optLabel = $opt['label'];
                                                $optDescription = $opt['description'];
                                            @endphp
                                            <label for="{{ $radioId }}" class="{{ $radioOptionClass }}">
                                                <input id="{{ $radioId }}" type="radio" name="{{ $name }}" value="{{ $optValue }}" @if ($required && $j === 0) required @endif class="text-current" />
                                                <span class="text-sm {{ ($chrome['surface'] ?? null) === 'panel-inverted' ? 'text-white' : 'text-gray-800' }}">{{ $optLabel }}</span>
@if (($chrome['radio_style'] ?? null) === 'tiles' && $optDescription !== '')<span class="text-xs {{ ($chrome['surface'] ?? null) === 'panel-inverted' ? 'text-white' : 'opacity-70' }}">{{ $optDescription }}</span>@endif                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>
                            @else
                                <div>
                                    <label for="{{ $fieldId }}" class="{{ $labelClass }}">
                                        {{ $label }}
                                        @if ($required)
                                            <span class="{{ $requiredMarkClass }}">*</span>
                                        @endif
                                    </label>

                                    @if ($type === 'select')
                                        <select id="{{ $fieldId }}" name="{{ $name }}" @if ($required) required @endif
                                                class="{{ $selectClass }}"
                                                style="--tw-ring-color: var(--brand-accent);">
                                            <option value="">Please choose…</option>
                                            @foreach ($normOptions as $opt)
                                                <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                                            @endforeach
                                        </select>
                                    @elseif ($type === 'textarea')
                                        <textarea id="{{ $fieldId }}" name="{{ $name }}" rows="3" @if ($required) required @endif
                                                  placeholder="{{ $placeholder }}"
                                                  class="{{ $inputClass }}"
                                                  style="--tw-ring-color: var(--brand-accent);"></textarea>
                                    @elseif ($type === 'date')
                                        <input id="{{ $fieldId }}" type="text" name="{{ $name }}"
                                               data-flatpickr
                                               @if ($required) required @endif
                                               autocomplete="off"
                                               placeholder="{{ $placeholder }}"
                                               class="{{ $inputClass }}"
                                               style="--tw-ring-color: var(--brand-accent);" />
                                    @else
                                        <input id="{{ $fieldId }}" type="{{ $type === 'tel' ? 'tel' : 'text' }}" name="{{ $name }}"
                                               @if ($required) required @endif
                                               @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
                                               @if ($inputmode) inputmode="{{ $inputmode }}" @endif
                                               placeholder="{{ $placeholder }}"
                                               class="{{ $inputClass }}"
                                               style="--tw-ring-color: var(--brand-accent);" />
                                    @endif
                                </div>
                            @endif
@endforeach
@if ($rowTwo === [])
    {{-- no optional fields: close row one; row two opens only when the fallback message will render --}}
</div>
@if ($rowTwoOpen)
<div data-band-row="2" class="grid gap-4 md:grid-cols-3 mt-4">
@endif
@endif
@if (! $hasMessageField && ! $messageFieldMigrated)
    <div class="md:col-span-3">
        <label for="{{ $formId }}-message" class="{{ $labelClass }}">Message <span class="{{ $requiredMarkClass }}">*</span></label>
        <textarea id="{{ $formId }}-message" name="message" rows="1" @focus="$el.rows = 3" required placeholder="How can we help?" class="{{ $inputClass }}" style="--tw-ring-color: var(--brand-accent);"></textarea>
    </div>
@endif
@if ($rowTwoOpen)
</div>
@endif
<div class="md:flex md:justify-end">
    <button type="submit" x-bind:disabled="sending" class="{{ $rowSubmitClass }}" style="background-color: var(--brand-accent); color: var(--color-text-on-accent, #ffffff); font-size: 1rem;"{!! $editor('submit_label', 'plain') !!}>
        <span x-show="!sending">{{ $submitLabel }}</span>
        <span x-show="sending" x-cloak>Sending…</span>
        <span aria-hidden="true">→</span>
    </button>
</div>
<p x-show="error" x-text="error" x-cloak class="{{ $errorClass }}"></p>
@endif
                    </form>
                </div>