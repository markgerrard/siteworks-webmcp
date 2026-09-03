@php
    $inputs = is_array($inputs ?? null) ? $inputs : [];
    $fieldStyle = 'width: 100%; min-height: 44px; padding: 0.5rem 0.75rem; color: var(--color-text); border: 1px solid var(--color-border); border-radius: var(--radius-button); background-color: var(--color-surface); outline-color: var(--color-accent);';
    $prefix = $namePrefix ?? 'personalisation';
    $values = is_array($values ?? null) ? $values : [];
@endphp
@foreach ($inputs as $input)
    @php
        $slug = (string) ($input['slug'] ?? '');
        $label = (string) ($input['label'] ?? $slug);
        $kind = (string) ($input['kind'] ?? 'text');
        $required = (bool) ($input['required'] ?? false);
        $help = (string) ($input['help'] ?? '');
        $maxChars = (int) ($input['max_chars'] ?? 500);
        $maxFiles = (int) ($input['max_files'] ?? 1);
        $options = is_array($input['options'] ?? null) ? $input['options'] : [];
        $value = $values[$slug] ?? null;
        $value = is_scalar($value) ? (string) $value : '';
        $name = $prefix.'['.$slug.']';
        $errorKey = $prefix.'.'.$slug;
    @endphp
    <div class="block" data-customer-input="{{ $slug }}" data-kind="{{ $kind }}">
        @if ($kind === 'textarea')
            <label class="block" x-data="{ len: {{ mb_strlen($value) }} }">
                <span class="text-sm font-medium">{{ $label }}@if ($required) <span aria-hidden="true">*</span>@endif</span>
                <textarea
                    name="{{ $name }}"
                    maxlength="{{ $maxChars }}"
                    @if ($required) required @endif
                    rows="4"
                    class="mt-1 w-full max-w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                    style="{{ $fieldStyle }}"
                    x-on:input="len = $event.target.value.length"
                >{{ old($name, $value) }}</textarea>
                <span class="mt-1 block text-xs" style="color: var(--color-text-muted)" aria-live="polite"><span x-text="len">{{ mb_strlen(old($name, $value)) }}</span>/{{ $maxChars }}</span>
            </label>
        @elseif ($kind === 'choice')
            @if (count($options) <= 4)
                <fieldset class="block">
                    <legend class="text-sm font-medium">{{ $label }}@if ($required) <span aria-hidden="true">*</span>@endif</legend>
                    <div class="mt-2 space-y-2">
                        @foreach ($options as $option)
                            <label class="flex items-center gap-2 min-h-11">
                                <input
                                    type="radio"
                                    name="{{ $name }}"
                                    value="{{ $option }}"
                                    @if ($required) required @endif
                                    @checked(old($name, $value) === $option)
                                >
                                <span>{{ $option }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @else
                <label class="block">
                    <span class="text-sm font-medium">{{ $label }}@if ($required) <span aria-hidden="true">*</span>@endif</span>
                    <select
                        name="{{ $name }}"
                        class="mt-1 w-full max-w-full p-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                        style="{{ $fieldStyle }}"
                        @if ($required) required @endif
                    >
                        <option value="">Choose</option>
                        @foreach ($options as $option)
                            <option value="{{ $option }}" @selected(old($name, $value) === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
        @elseif ($kind === 'image')
            <label class="block" x-data="{ previews: [] }">
                <span class="text-sm font-medium">{{ $label }}@if ($required) <span aria-hidden="true">*</span>@endif</span>
                <input
                    type="file"
                    name="{{ $name }}[]"
                    accept="image/jpeg,image/png,image/webp,image/heic,image/heif"
                    class="mt-1 w-full max-w-full"
                    @if ($required) required @endif
                    @if ($maxFiles > 1) multiple @endif
                    x-on:change="previews = Array.from($event.target.files).slice(0, {{ $maxFiles }}).map(file => URL.createObjectURL(file))"
                >
                <div class="mt-2 flex flex-wrap gap-2" x-show="previews.length">
                    <template x-for="src in previews" :key="src">
                        <img :src="src" alt="" width="72" height="72" class="object-cover" style="width: 72px; height: 72px; border-radius: var(--radius-card);">
                    </template>
                </div>
            </label>
        @else
            <label class="block" x-data="{ len: {{ mb_strlen($value) }} }">
                <span class="text-sm font-medium">{{ $label }}@if ($required) <span aria-hidden="true">*</span>@endif</span>
                <input
                    type="text"
                    name="{{ $name }}"
                    maxlength="{{ $maxChars }}"
                    value="{{ old($name, $value) }}"
                    @if ($required) required @endif
                    class="mt-1 w-full max-w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                    style="{{ $fieldStyle }}"
                    x-on:input="len = $event.target.value.length"
                >
                <span class="mt-1 block text-xs" style="color: var(--color-text-muted)" aria-live="polite"><span x-text="len">{{ mb_strlen(old($name, $value)) }}</span>/{{ $maxChars }}</span>
            </label>
        @endif
        @if ($help !== '')
            <p class="mt-1 text-xs" style="color: var(--color-text-muted)">{{ $help }}</p>
        @endif
        @error($errorKey)
            <p class="mt-1 text-sm" role="alert">{{ $message }}</p>
        @enderror
    </div>
@endforeach
