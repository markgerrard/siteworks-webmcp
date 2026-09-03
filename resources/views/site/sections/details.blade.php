@php
    $editor = function ($field, $type) use ($pageId, $sectionIndex, $emitMarkers, $section) {
        if (! $emitMarkers) {
            return '';
        }
        $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";
        $sectionType = $section['type'] ?? '';
        return ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="'.e($sectionType).'" data-editable-field="'.e($field).'"';
    };

    // Icon map for common contact labels
    $labelIcons = [
        'phone' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>',
        'mobile' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 2a2 2 0 00-2 2v16a2 2 0 002 2h10a2 2 0 002-2V4a2 2 0 00-2-2H7zm5 18a1 1 0 110-2 1 1 0 010 2z"/>',
        'email' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>',
        'address' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>',
        'coverage' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>',
        'hours' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>',
        'whatsapp' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M21 12a9 9 0 01-13.815 7.605L3 21l1.395-4.185A9 9 0 1121 12z"/>',
    ];

    $items = $section['items'] ?? [];
    // If the profile carries a mobile number alongside the landline, surface
    // it as its own Mobile row. Skip if a Mobile item was already emitted
    // (future-proofing — prompt may eventually include it directly).
    $profileMobile = $profile['contact']['mobile'] ?? null;
    $alreadyHasMobile = collect($items)->contains(fn ($i) => strtolower($i['label'] ?? '') === 'mobile');
    if (is_string($profileMobile) && $profileMobile !== '' && ! $alreadyHasMobile) {
        $items[] = ['label' => 'Mobile', 'value' => $profileMobile];
    }
    $hasEmail = collect($items)->contains(fn ($i) => strtolower($i['label'] ?? '') === 'email' && !empty($i['value'] ?? ''));

    $form = $contactFormSection ?? null;
    $hasForm = $hasEmail && ! empty($form);
    // The marker must name the absorbed contact_form section, not this
    // details section. page.blade.php threads the index alongside the section.
    $formSectionIndex = $contactFormSectionIndex ?? $sectionIndex;
    $formMarker = ($hasForm && ($emitFormMarkers ?? false))
        ? ' data-form-editable="page.'.e($pageId).'.section.'.e($formSectionIndex).'" data-form-kind="'.e($form['type'] ?? 'contact_form').'"'
        : '';

    $lat = $profile['geo']['latitude'] ?? null;
    $lng = $profile['geo']['longitude'] ?? null;
    $hasMap = $lat && $lng;
    $eyebrow = $section['eyebrow'] ?? 'Get In Touch';
    $enquireId = ! empty($enquireAnchor) ? ' id="enquire"' : '';
    $enquireClass = ! empty($enquireAnchor) ? ' scroll-mt-24 md:scroll-mt-28' : '';
    $contactDark = $surfaceDark['surface'] ?? false;
    $formSurface = $contactDark ? 'panel-inverted' : null;
    $ink = $contactDark ? 'text-white' : 'text-gray-900';
    $muted = $contactDark ? 'text-white/80' : 'text-gray-600';
    $faint = $contactDark ? 'text-white/60' : 'text-gray-400';
    $cardClass = $contactDark ? '' : 'bg-white ';
    $cardMix = $contactDark ? ' background-color: color-mix(in srgb, #ffffff 6%, var(--color-surface));' : '';
@endphp

<div class="py-20 lg:py-24{{ $contactDark ? '' : ' bg-gray-50' }}" id="contact"@if ($contactDark) style="background-color: var(--color-surface); color: var(--color-text);"@endif>
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
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

        @if ($hasForm)
            {{-- ===== Two-column layout: form 3/5 left, details + map 2/5 right —
                 the wider sidebar keeps long emails and WhatsApp rows on one line. ===== --}}
            <div class="grid grid-cols-1 lg:grid-cols-5 gap-8">
                {{-- LEFT: Contact form --}}
                <div class="lg:col-span-3">
                    <div{!! $enquireId !!} class="{{ $cardClass }}rounded-lg shadow-md p-8 md:p-10 border-t-4{{ $enquireClass }}"
                         style="border-top-color: var(--brand-primary);{{ $cardMix }}"
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
                        {{-- Posts to /enquiries exactly like the standalone contact_form
                             section. This form is the one that actually renders whenever a
                             page pairs `details` with `contact_form` (page.blade.php absorbs
                             the standalone section), so it must carry the same wiring —
                             honeypot, page_type and all. --}}
                        <form x-show="!submitted" @submit.prevent="submit($event.target)" class="space-y-5"{!! $formMarker !!}>
                            @if (!empty($form['title']))
                                <h3 class="text-xl font-bold {{ $ink }} mb-2">{{ $form['title'] }}</h3>
                            @endif
                            @php
                                $cfId = "cf-{$pageId}-{$sectionIndex}";
                                $inputClass = \App\Services\Site\FormChrome::inputClass($site ?? null, 'contact', null, $formSurface);
                                $labelClass = \App\Services\Site\FormChrome::labelClass($site ?? null, null, $formSurface);
                                $selectClass = \App\Services\Site\FormChrome::selectClass($site ?? null, 'contact', null, $formSurface);
                                $radioOptionClass = \App\Services\Site\FormChrome::radioOptionClass($site ?? null, 'contact', null, $formSurface);
                            @endphp

                            <input type="hidden" name="page_type" value="{{ $pageType ?? '' }}">
                            {{-- Honeypot: real visitors never fill this; bots do. --}}
                            <input type="text" name="website" tabindex="-1" autocomplete="off"
                                   class="absolute -left-[9999px] w-px h-px opacity-0" aria-hidden="true" />

                            <div>
                                <label for="{{ $cfId }}-name" class="{{ $labelClass }}">Name</label>
                                <input id="{{ $cfId }}-name" type="text" name="name" autocomplete="name" placeholder="Your name"
                                       class="{{ $inputClass }}" />
                            </div>
                            <div>
                                <label for="{{ $cfId }}-email" class="{{ $labelClass }}">Email</label>
                                <input id="{{ $cfId }}-email" type="email" name="email" autocomplete="email" inputmode="email" placeholder="your@email.com"
                                       class="{{ $inputClass }}" />
                            </div>
                            {{-- Everything after name/email comes from the absorbed
                                 contact_form's own fields. Shared with the standalone
                                 contact_form section so the two cannot drift again --
                                 this template ignoring `fields` is precisely why
                                 editing a contact form changed nothing on most sites. --}}
                            @include('site.partials.contact-form-fields', [
                                'fields' => $form['fields'] ?? null,
                                'inputClass' => $inputClass,
                                'labelClass' => $labelClass,
                                'selectClass' => $selectClass,
                                'radioOptionClass' => $radioOptionClass,
                                'idPrefix' => $cfId,
                                'formSurface' => $formSurface,
                            ])
                            <p x-show="error" x-text="error" x-cloak class="{{ \App\Services\Site\FormChrome::errorClass($formSurface) }}"></p>
                            <button type="submit" x-bind:disabled="sending"
                                    class="w-full px-6 py-3 rounded-md font-bold text-sm shadow-md transition-all hover:shadow-lg hover:brightness-110 disabled:opacity-60"
                                    style="background-color: var(--brand-accent); color: var(--color-text-on-accent, #ffffff);">
                                <span x-show="! sending">{{ $form['submit_label'] ?? 'Send Message' }}</span>
                                <span x-show="sending" x-cloak>Sending…</span>
                            </button>
                        </form>
                    </div>
                </div>

                {{-- RIGHT: Contact details + map stacked --}}
                <div class="space-y-6 lg:col-span-2">
                    <div class="{{ $cardClass }}rounded-lg shadow-md p-6 border-l-4" style="border-left-color: var(--brand-primary);{{ $cardMix }}">
                        <h3 class="font-bold {{ $ink }} text-lg mb-5">Contact Details</h3>
                        <div class="space-y-4">
                            @foreach ($items as $i => $item)
                                {{-- Skip items with no content in either schema — an
                                     empty item renders as a bare icon pin otherwise
                                     (flyout-era saves left title/body husks behind). --}}
                                @continue(trim(($item['label'] ?? '').($item['value'] ?? '').($item['title'] ?? '').(is_string($item['body'] ?? null) ? $item['body'] : '')) === '')
                                @php
                                    $labelKey = strtolower($item['label'] ?? '');
                                    $labelKey = \App\Support\ContactLabelIcon::key($labelKey);
                                    $iconPath = $labelIcons[$labelKey] ?? $labelIcons['address'];
                                    $isPhone = in_array($labelKey, ['phone', 'mobile'], true);
                                    $isEmailItem = $labelKey === 'email';
                                    $isWhatsApp = $labelKey === 'whatsapp';
                                    if ($isWhatsApp) {
                                        // wa.me wants digits with a country code; UK-centric
                                        // leading-0 swap matches the phone formats agents enter.
                                        $waDigits = preg_replace('/\D/', '', (string) ($item['value'] ?? ''));
                                        $waDigits = str_starts_with($waDigits, '0') ? '44'.substr($waDigits, 1) : $waDigits;
                                    }
                                @endphp
                                <div class="flex items-center gap-3 {{ ($isPhone || $isEmailItem || $isWhatsApp) ? 'group' : '' }}">
                                    <div class="w-9 h-9 rounded-md flex items-center justify-center text-white flex-shrink-0"
                                         style="background-color: var(--brand-primary);">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $iconPath !!}</svg>
                                    </div>
                                    <div>
                                        <span class="text-[10px] font-medium {{ $faint }} uppercase tracking-wide"
                                              {!! $editor("items.{$i}.label", 'plain') !!}>{{ $item['label'] ?? '' }}</span>
                                        @if ($isWhatsApp)
                                            <a href="https://wa.me/{{ $waDigits }}" target="_blank" rel="noopener"
                                               class="block text-sm font-bold {{ $ink }} group-hover:underline"
                                               {!! $editor("items.{$i}.value", 'plain') !!}>{{ $item['value'] ?? '' }}</a>
                                        @elseif ($isPhone)
                                            <a href="tel:{{ $item['value'] ?? '' }}"
                                               class="block text-sm font-bold {{ $ink }} group-hover:underline"
                                               {!! $editor("items.{$i}.value", 'plain') !!}>{{ $item['value'] ?? '' }}</a>
                                        @elseif ($isEmailItem)
                                            <a href="mailto:{{ $item['value'] ?? '' }}"
                                               class="block text-sm font-semibold {{ $ink }} group-hover:underline break-all"
                                               {!! $editor("items.{$i}.value", 'plain') !!}>{{ $item['value'] ?? '' }}</a>
                                        @else
                                            <span class="block text-sm {{ $ink }} font-medium"
                                                  {!! $editor("items.{$i}.value", 'plain') !!}>{{ $item['value'] ?? '' }}</span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    @if ($hasMap)
                        <div class="rounded-lg overflow-hidden shadow-md" style="height: 220px;" id="contact-map-{{ $sectionIndex }}"></div>
                        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
                        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
                        <script>
                            document.addEventListener('DOMContentLoaded', function() {
                                var map = L.map('contact-map-{{ $sectionIndex }}').setView([{{ $lat }}, {{ $lng }}], 14);
                                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                    maxZoom: 19,
                                    attribution: '&copy; <a href="https://openstreetmap.org/copyright">OpenStreetMap</a>'
                                }).addTo(map);
                                L.marker([{{ $lat }}, {{ $lng }}]).addTo(map);
                            });
                        </script>
                    @endif
                </div>
            </div>

        @elseif (!empty($items))
            {{-- ===== No form: original 2-col layout — details left, map right (if lat/lng) ===== --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <div class="{{ $cardClass }}rounded-lg shadow-md p-8 border-l-4" style="border-left-color: var(--brand-primary);{{ $cardMix }}">
                    <h3 class="font-bold {{ $ink }} text-lg mb-6">Contact Details</h3>
                    <div class="space-y-5">
                        @foreach ($items as $i => $item)
                            @continue(trim(($item['label'] ?? '').($item['value'] ?? '').($item['title'] ?? '').(is_string($item['body'] ?? null) ? $item['body'] : '')) === '')
                            @php
                                $labelKey = strtolower($item['label'] ?? '');
                                $labelKey = \App\Support\ContactLabelIcon::key($labelKey);
                                $iconPath = $labelIcons[$labelKey] ?? $labelIcons['address'];
                                $isPhone = in_array($labelKey, ['phone', 'mobile'], true);
                                $isEmailItem = $labelKey === 'email';
                                $isWhatsApp = $labelKey === 'whatsapp';
                                if ($isWhatsApp) {
                                    $waDigits = preg_replace('/\D/', '', (string) ($item['value'] ?? ''));
                                    $waDigits = str_starts_with($waDigits, '0') ? '44'.substr($waDigits, 1) : $waDigits;
                                }
                            @endphp
                            <div class="flex items-center gap-4 {{ ($isPhone || $isEmailItem || $isWhatsApp) ? 'group' : '' }}">
                                <div class="w-10 h-10 rounded-md flex items-center justify-center text-white flex-shrink-0"
                                     style="background-color: var(--brand-primary);">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $iconPath !!}</svg>
                                </div>
                                <div>
                                    <span class="text-xs font-medium {{ $faint }} uppercase tracking-wide"
                                          {!! $editor("items.{$i}.label", 'plain') !!}>{{ $item['label'] ?? '' }}</span>
                                    @if ($isWhatsApp)
                                        <a href="https://wa.me/{{ $waDigits }}" target="_blank" rel="noopener"
                                           class="block font-bold {{ $ink }} group-hover:underline"
                                           {!! $editor("items.{$i}.value", 'plain') !!}>{{ $item['value'] ?? '' }}</a>
                                    @elseif ($isPhone)
                                        <a href="tel:{{ $item['value'] ?? '' }}"
                                           class="block font-bold {{ $ink }} group-hover:underline"
                                           {!! $editor("items.{$i}.value", 'plain') !!}>{{ $item['value'] ?? '' }}</a>
                                    @elseif ($isEmailItem)
                                        <a href="mailto:{{ $item['value'] ?? '' }}"
                                           class="block font-semibold {{ $ink }} group-hover:underline break-all"
                                           {!! $editor("items.{$i}.value", 'plain') !!}>{{ $item['value'] ?? '' }}</a>
                                    @else
                                        <span class="block {{ $ink }} font-medium"
                                              {!! $editor("items.{$i}.value", 'plain') !!}>{{ $item['value'] ?? '' }}</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                @if ($hasMap)
                    <div>
                        <div class="rounded-lg overflow-hidden shadow-md" style="height: 300px;" id="contact-map-{{ $sectionIndex }}"></div>
                        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
                        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
                        <script>
                            document.addEventListener('DOMContentLoaded', function() {
                                var map = L.map('contact-map-{{ $sectionIndex }}').setView([{{ $lat }}, {{ $lng }}], 14);
                                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                    maxZoom: 19,
                                    attribution: '&copy; <a href="https://openstreetmap.org/copyright">OpenStreetMap</a>'
                                }).addTo(map);
                                L.marker([{{ $lat }}, {{ $lng }}]).addTo(map);
                            });
                        </script>
                    </div>
                @endif
            </div>
        @endif
    </div>
</div>
