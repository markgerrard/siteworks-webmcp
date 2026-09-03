{{--
    The variable part of a public contact form: either the client's defined
    fields, or the standard phone + message pair when they have none.

    Shared by site/sections/contact_form.blade.php and the absorbed form in
    site/sections/details.blade.php. It exists because those two drifted:
    contact_form honoured $section['fields'] while details hardcoded four
    inputs, so editing a form changed the page only when details was absent.
    One copy means they cannot disagree again.

    NOT included here: name and email. SiteEnquirySubmitController validates
    on both, so each caller renders them itself, always, outside anything a
    client can remove. They are filtered out of the custom list below too --
    two inputs sharing a name submit twice and the last one wins.

    @param array|null $fields            Raw fields from the contact_form section
    @param string     $inputClass        Caller's input styling
    @param string     $labelClass        Optional; defaults to FormChrome::labelClass($site)
    @param string     $selectClass       Optional; defaults to $inputClass
    @param string     $radioOptionClass  Optional; defaults to FormChrome::radioOptionClass($site)
    @param string     $idPrefix          Optional id prefix ('' for none)
    @param string|null $formSurface      FormChrome surface ('panel-inverted' or null).
                                         The ONE variable both callers pass so absorbed
                                         and standalone chrome cannot diverge.
--}}
@php
    $reservedFieldNames = ['name', 'email', 'website', 'page_type'];

    $customFields = collect(is_array($fields ?? null) ? $fields : [])
        ->filter(fn ($f) => is_array($f) && ! empty($f['name']) && ! empty($f['type']))
        ->reject(fn ($f) => in_array($f['name'], $reservedFieldNames, true))
        ->values();

    // No field list at all means an untouched site: keep what it has always
    // rendered rather than silently dropping phone and message.
    $useDefaultTail = ! is_array($fields ?? null) || $customFields->isEmpty();
    $idFor = fn (string $suffix) => ($idPrefix ?? '') !== '' ? ($idPrefix.'-'.$suffix) : '';
    $formSurface = $formSurface ?? null;
    $inputClass = $inputClass ?? \App\Services\Site\FormChrome::inputClass($site ?? null, 'contact', null, $formSurface);
    $labelClass = $labelClass ?? \App\Services\Site\FormChrome::labelClass($site ?? null, null, $formSurface);
    $selectClass = $selectClass ?? $inputClass;
    $radioOptionClass = $radioOptionClass ?? \App\Services\Site\FormChrome::radioOptionClass($site ?? null, 'contact', null, $formSurface);
@endphp

@if ($useDefaultTail)
    <div>
        <label @if ($idFor('phone')) for="{{ $idFor('phone') }}" @endif class="{{ $labelClass }}">Phone</label>
        <input @if ($idFor('phone')) id="{{ $idFor('phone') }}" @endif
               type="tel" name="phone" autocomplete="tel" inputmode="tel" placeholder="Your phone number"
               class="{{ $inputClass }}" />
    </div>
    <div>
        <label @if ($idFor('message')) for="{{ $idFor('message') }}" @endif class="{{ $labelClass }}">Message</label>
        <textarea @if ($idFor('message')) id="{{ $idFor('message') }}" @endif
                  name="message" rows="4" placeholder="How can we help?"
                  class="{{ $inputClass }}"></textarea>
    </div>
@else
    @foreach ($customFields as $field)
        @php
            $fieldName = $field['name'];
            $fieldLabel = $field['label'] ?? $fieldName;
            $fieldPlaceholder = $field['placeholder'] ?? '';
            $fieldRequired = ! empty($field['required']);
            $fieldOptions = array_values(array_filter(
                is_array($field['options'] ?? null) ? $field['options'] : [],
                fn ($o) => is_scalar($o) && trim((string) $o) !== ''
            ));
            $fieldId = $idFor($fieldName);
        @endphp
        <div>
            <label @if ($fieldId) for="{{ $fieldId }}" @endif class="{{ $labelClass }}">
                {{ $fieldLabel }}@if ($fieldRequired) <span aria-hidden="true">*</span>@endif
            </label>

            @if ($field['type'] === 'textarea')
                <textarea @if ($fieldId) id="{{ $fieldId }}" @endif
                          name="{{ $fieldName }}" rows="4"
                          placeholder="{{ $fieldPlaceholder }}"
                          @if ($fieldRequired) required @endif
                          class="{{ $inputClass }}"></textarea>
            @elseif ($field['type'] === 'select')
                <select @if ($fieldId) id="{{ $fieldId }}" @endif
                        name="{{ $fieldName }}"
                        @if ($fieldRequired) required @endif
                        class="{{ $selectClass }}">
                    <option value="">{{ $fieldPlaceholder !== '' ? $fieldPlaceholder : 'Please choose…' }}</option>
                    @foreach ($fieldOptions as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            @elseif ($field['type'] === 'radio')
                <div class="space-y-2">
                    @foreach ($fieldOptions as $oi => $option)
                        <label class="{{ $radioOptionClass }}">
                            <input type="radio" name="{{ $fieldName }}" value="{{ $option }}"
                                   @if ($fieldRequired && $oi === 0) required @endif />
                            <span>{{ $option }}</span>
                        </label>
                    @endforeach
                </div>
            @else
                {{-- text, tel, date and anything unrecognised: an input is the
                     safe default, since the answer is stored as a free-form
                     payload string either way. --}}
                <input @if ($fieldId) id="{{ $fieldId }}" @endif
                       type="{{ in_array($field['type'], ['tel', 'date', 'email'], true) ? $field['type'] : 'text' }}"
                       name="{{ $fieldName }}"
                       placeholder="{{ $fieldPlaceholder }}"
                       @if ($fieldRequired) required @endif
                       class="{{ $inputClass }}" />
            @endif
        </div>
    @endforeach
@endif
