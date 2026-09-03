@php
    $editor = function ($field, $type, $valueDoc = null) use ($pageId, $sectionIndex, $emitMarkers, $section) {
        if (! $emitMarkers) {
            return '';
        }
        $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";
        $sectionType = $section['type'] ?? '';
        $attrs = ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="'.e($sectionType).'" data-editable-field="'.e($field).'"';
        if ($type === 'rich' && $valueDoc !== null) {
            $attrs .= ' data-editable-doc="'.e(json_encode($valueDoc)).'"';
        }
        return $attrs;
    };
    $richHtml = fn ($val) => app(\App\Services\Site\RichTextRenderer::class)->renderValue($val);
    $eyebrow = $section['eyebrow'] ?? 'Contact Form';

    // Client-defined fields are rendered by site/partials/contact-form-fields,
    // shared with the absorbed form in details.blade.php. name and email are
    // deliberately not part of that list -- see the partial's header.
    $contactDark = $surfaceDark['surface'] ?? false;
    $formSurface = $contactDark ? 'panel-inverted' : null;
    $ink = $contactDark ? 'text-white' : 'text-gray-900';
    $muted = $contactDark ? 'text-white/80' : 'text-gray-600';
    $cardClass = $contactDark ? '' : 'bg-white ';
    $cardMix = $contactDark ? ' background-color: color-mix(in srgb, #ffffff 6%, var(--color-surface));' : '';
    $inputClass = \App\Services\Site\FormChrome::inputClass($site ?? null, 'contact', null, $formSurface);
    $labelClass = \App\Services\Site\FormChrome::labelClass($site ?? null, null, $formSurface);
    $selectClass = \App\Services\Site\FormChrome::selectClass($site ?? null, 'contact', null, $formSurface);
    $radioOptionClass = \App\Services\Site\FormChrome::radioOptionClass($site ?? null, 'contact', null, $formSurface);

    $formMarker = ($emitFormMarkers ?? false)
        ? ' data-form-editable="page.'.e($pageId).'.section.'.e($sectionIndex).'" data-form-kind="'.e($section['type'] ?? '').'"'
        : '';
    $enquireId = ! empty($enquireAnchor) ? ' id="enquire"' : '';
    $enquireClass = ! empty($enquireAnchor) ? ' scroll-mt-24 md:scroll-mt-28' : '';
@endphp

<div{!! $enquireId !!} class="py-20 lg:py-24{{ $contactDark ? '' : ' bg-white' }}{{ $enquireClass }}"@if ($contactDark) style="background-color: var(--color-surface); color: var(--color-text);"@endif>
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        @if (!empty($section['title']))
            <div class="text-center mb-12">
                <span class="text-sm font-bold tracking-widest uppercase mb-3 block" style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                <h2 class="text-3xl md:text-4xl font-extrabold {{ $ink }}"
                    {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
            </div>
        @else
            @if ($emitMarkers)
                <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                <span class="hidden"{!! $editor('title', 'plain') !!}></span>
            @endif
        @endif

        @if (!empty($section['intro']))
            <div class="text-center {{ $muted }} mb-8 prose max-w-none"
                 {!! $editor('intro', 'rich', is_array($section['intro']) ? $section['intro'] : null) !!}>{!! $richHtml($section['intro']) !!}</div>
        @else
            @if ($emitMarkers)
                <span class="hidden"{!! $editor('intro', 'rich') !!}></span>
            @endif
        @endif

        <div class="{{ $cardClass }}rounded-lg shadow-md p-8 md:p-10 border-t-4" style="border-top-color: var(--brand-primary);{{ $cardMix }}"
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

            {{-- Thank you state --}}
            <div x-show="submitted" x-cloak class="text-center py-12">
                <div class="w-16 h-16 rounded-full mx-auto mb-6 flex items-center justify-center text-white"
                     style="background-color: var(--brand-primary);">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <h3 class="text-2xl font-bold {{ $ink }} mb-2">Thank You!</h3>
                <p class="{{ $muted }}">We've received your message and will get back to you shortly.</p>
            </div>

            {{-- Form posts to /enquiries — stored always, emailed to the
                 owner only when enquiry_notification_email is set. --}}
            <form x-show="!submitted" @submit.prevent="submit($event.target)" class="space-y-6"{!! $formMarker !!}>
                <input type="hidden" name="page_type" value="{{ $pageType ?? '' }}">
                {{-- Honeypot: hidden from humans; bots that fill it get a fake success. --}}
                <input type="text" name="website" tabindex="-1" autocomplete="off"
                       style="position: absolute; left: -9999px;" aria-hidden="true">
                <div>
                    <label class="{{ $labelClass }}">Name</label>
                    <input type="text" name="name" required placeholder="Your name"
                           class="{{ $inputClass }}" />
                </div>
                <div>
                    <label class="{{ $labelClass }}">Email</label>
                    <input type="email" name="email" required placeholder="your@email.com"
                           class="{{ $inputClass }}" />
                </div>
                {{-- Shared with the absorbed form in details.blade.php: when a page
                     has both sections, THAT template renders the form a visitor
                     sees, and the two silently disagreeing is the bug this closes. --}}
                @include('site.partials.contact-form-fields', [
                    'fields' => $section['fields'] ?? null,
                    'inputClass' => $inputClass,
                    'labelClass' => $labelClass,
                    'selectClass' => $selectClass,
                    'radioOptionClass' => $radioOptionClass,
                    'idPrefix' => '',
                    'formSurface' => $formSurface,
                ])
                <p x-show="error" x-text="error" x-cloak class="{{ \App\Services\Site\FormChrome::errorClass($formSurface) }}"></p>
                <div>
                    <button type="submit" x-bind:disabled="sending"
                            class="w-full px-6 py-3 rounded-md font-bold text-white text-sm shadow-md transition-all hover:shadow-lg hover:brightness-110 disabled:opacity-60"
                            style="background-color: var(--brand-accent);"
                            {!! $editor('submit_label', 'plain') !!}>
                        <span x-show="!sending">{{ $section['submit_label'] ?? 'Send Message' }}</span>
                        <span x-show="sending" x-cloak>Sending…</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
