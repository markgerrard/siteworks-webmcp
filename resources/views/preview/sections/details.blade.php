@php
    // Check if a contact_form section exists in the parent page's content.
    // It's passed via the layout loop as a sibling section — we access it
    // from the snapshot via the $pages variable that layout.blade injects.
    $contactForm = null;
    if (isset($pages) && is_array($pages)) {
        foreach ($pages as $pt => $pageContent) {
            if (isset($pageContent['contact_form'])) {
                $contactForm = $pageContent['contact_form'];
                break;
            }
        }
    }
    $formEnabled = $contactFormEnabled ?? true;
    $hasForm = $formEnabled && !empty($contactForm) && !empty($contactForm['fields']);

    $lat = $profile['geo']['latitude'] ?? null;
    $lng = $profile['geo']['longitude'] ?? null;
@endphp

<div class="py-20 lg:py-24 bg-gray-50" id="contact">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        @if (!empty($data['heading']))
            <div class="text-center mb-12">
                <span class="text-sm font-bold tracking-widest uppercase mb-3 block" style="color: var(--brand-accent);">Get In Touch</span>
                <h2 class="text-3xl md:text-4xl font-extrabold text-gray-900">
                    {{ $data['heading'] }}
                </h2>
            </div>
        @endif

        @if ($hasForm)
            {{-- ===== TWO-COLUMN LAYOUT: form 2/3 left, details+map 1/3 right ===== --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                {{-- LEFT: Contact form (2/3) --}}
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-lg shadow-md p-8 md:p-10 border-t-4" style="border-top-color: var(--brand-primary);"
                         x-data="{ submitted: false }">

                        <div x-show="submitted" x-cloak class="text-center py-12">
                            <div class="w-16 h-16 rounded-full mx-auto mb-6 flex items-center justify-center text-white" style="background-color: var(--brand-primary);">
                                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            </div>
                            <h3 class="text-2xl font-bold text-gray-900 mb-2">Thank You!</h3>
                            <p class="text-gray-600">We've received your message and will get back to you shortly.</p>
                        </div>

                        <form x-show="!submitted" action="#" @submit.prevent="submitted = true" class="space-y-5">
                            @if (!empty($contactForm['heading']))
                                <h3 class="text-xl font-bold text-gray-900 mb-2">{{ $contactForm['heading'] }}</h3>
                            @endif
                            @foreach ($contactForm['fields'] as $field)
                                <div>
                                    <label for="field-{{ $field['name'] }}" class="block text-sm font-semibold text-gray-700 mb-1.5">
                                        {{ $field['label'] ?? ucfirst($field['name']) }}
                                        @if (!empty($field['required']))
                                            <span class="text-red-500">*</span>
                                        @endif
                                    </label>
                                    @if (($field['type'] ?? 'text') === 'textarea')
                                        <textarea id="field-{{ $field['name'] }}" name="{{ $field['name'] }}" rows="4"
                                                  placeholder="{{ $field['placeholder'] ?? '' }}"
                                                  {{ !empty($field['required']) ? 'required' : '' }}
                                                  class="w-full rounded-md border border-gray-300 px-4 py-2.5 text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:border-transparent transition-shadow"></textarea>
                                    @elseif (($field['type'] ?? 'text') === 'select')
                                        <select id="field-{{ $field['name'] }}" name="{{ $field['name'] }}"
                                                {{ !empty($field['required']) ? 'required' : '' }}
                                                class="w-full rounded-md border border-gray-300 px-4 py-2.5 text-gray-900 focus:outline-none focus:ring-2 focus:border-transparent transition-shadow bg-white">
                                            <option value="">Select an option...</option>
                                            @foreach ($field['options'] ?? [] as $option)
                                                <option value="{{ $option }}">{{ $option }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <input type="{{ $field['type'] ?? 'text' }}" id="field-{{ $field['name'] }}" name="{{ $field['name'] }}"
                                               placeholder="{{ $field['placeholder'] ?? '' }}"
                                               {{ !empty($field['required']) ? 'required' : '' }}
                                               class="w-full rounded-md border border-gray-300 px-4 py-2.5 text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:border-transparent transition-shadow" />
                                    @endif
                                </div>
                            @endforeach
                            <button type="submit"
                                    class="w-full px-6 py-3 rounded-md font-bold text-white text-sm shadow-md transition-all hover:shadow-lg hover:brightness-110"
                                    style="background-color: var(--brand-accent);">
                                {{ $contactForm['submit_label'] ?? 'Send Message' }}
                            </button>
                            @if (!empty($contactForm['privacy_note']))
                                <p class="text-xs text-gray-400 text-center mt-2">
                                    <svg class="w-3.5 h-3.5 inline-block mr-1 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                    {{ $contactForm['privacy_note'] }}
                                </p>
                            @endif
                        </form>
                    </div>
                </div>

                {{-- RIGHT: Contact details + map (1/3) --}}
                <div class="space-y-6">
                    <div class="bg-white rounded-lg shadow-md p-6 border-l-4" style="border-left-color: var(--brand-primary);">
                        <h3 class="font-bold text-gray-900 text-lg mb-5">Contact Details</h3>
                        <div class="space-y-4">
                            @if (!empty($data['phone']))
                                <a href="tel:{{ $data['phone'] }}" class="flex items-center gap-3 group">
                                    <div class="w-9 h-9 rounded-md flex items-center justify-center text-white flex-shrink-0"
                                         style="background-color: var(--brand-primary);">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                                    </div>
                                    <div>
                                        <span class="text-[10px] font-medium text-gray-400 uppercase tracking-wide">Phone</span>
                                        <span class="block text-sm font-bold text-gray-900 group-hover:underline">{{ $data['phone'] }}</span>
                                    </div>
                                </a>
                            @endif
                            @if (!empty($data['email']))
                                <a href="mailto:{{ $data['email'] }}" class="flex items-center gap-3 group">
                                    <div class="w-9 h-9 rounded-md flex items-center justify-center text-white flex-shrink-0"
                                         style="background-color: var(--brand-primary);">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                    </div>
                                    <div>
                                        <span class="text-[10px] font-medium text-gray-400 uppercase tracking-wide">Email</span>
                                        <span class="block text-sm font-semibold text-gray-900 group-hover:underline break-all">{{ $data['email'] }}</span>
                                    </div>
                                </a>
                            @endif
                            @if (!empty($data['address']))
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-md flex items-center justify-center text-white flex-shrink-0"
                                         style="background-color: var(--brand-primary);">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                    </div>
                                    <div>
                                        <span class="text-[10px] font-medium text-gray-400 uppercase tracking-wide">Address</span>
                                        <span class="block text-sm text-gray-900 font-medium">{{ $data['address'] }}</span>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>

                    @if (!empty($data['coverage']))
                        <div class="bg-white rounded-lg shadow-md p-6 border-l-4" style="border-left-color: var(--brand-accent);">
                            <h3 class="font-bold text-gray-900 text-sm mb-2 flex items-center gap-2">
                                <svg class="w-4 h-4" style="color: var(--brand-accent);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/></svg>
                                Areas We Cover
                            </h3>
                            <p class="text-sm text-gray-600 leading-relaxed">{{ $data['coverage'] }}</p>
                        </div>
                    @endif

                    @if ($lat && $lng)
                        <div class="rounded-lg overflow-hidden shadow-md" style="height: 220px;" id="contact-map"></div>
                        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
                        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
                        <script>
                            document.addEventListener('DOMContentLoaded', function() {
                                var map = L.map('contact-map').setView([{{ $lat }}, {{ $lng }}], 14);
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

        @else
            {{-- ===== ORIGINAL LAYOUT: no form, details only ===== --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <div class="bg-white rounded-lg shadow-md p-8 border-l-4" style="border-left-color: var(--brand-primary);">
                    <h3 class="font-bold text-gray-900 text-lg mb-6">Contact Details</h3>
                    <div class="space-y-5">
                        @if (!empty($data['phone']))
                            <a href="tel:{{ $data['phone'] }}" class="flex items-center gap-4 group">
                                <div class="w-10 h-10 rounded-md flex items-center justify-center text-white flex-shrink-0"
                                     style="background-color: var(--brand-primary);">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                                </div>
                                <div>
                                    <span class="text-xs font-medium text-gray-400 uppercase tracking-wide">Phone</span>
                                    <span class="block text-lg font-bold text-gray-900 group-hover:underline">{{ $data['phone'] }}</span>
                                </div>
                            </a>
                        @endif
                        @if (!empty($data['email']))
                            <a href="mailto:{{ $data['email'] }}" class="flex items-center gap-4 group">
                                <div class="w-10 h-10 rounded-md flex items-center justify-center text-white flex-shrink-0"
                                     style="background-color: var(--brand-primary);">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                </div>
                                <div>
                                    <span class="text-xs font-medium text-gray-400 uppercase tracking-wide">Email</span>
                                    <span class="block font-semibold text-gray-900 group-hover:underline">{{ $data['email'] }}</span>
                                </div>
                            </a>
                        @endif
                        @if (!empty($data['address']))
                            <div class="flex items-center gap-4">
                                <div class="w-10 h-10 rounded-md flex items-center justify-center text-white flex-shrink-0"
                                     style="background-color: var(--brand-primary);">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                </div>
                                <div>
                                    <span class="text-xs font-medium text-gray-400 uppercase tracking-wide">Address</span>
                                    <span class="block text-gray-900 font-medium">{{ $data['address'] }}</span>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                @if (!empty($data['coverage']))
                    <div class="bg-white rounded-lg shadow-md p-8 border-l-4" style="border-left-color: var(--brand-accent);">
                        <h3 class="font-bold text-gray-900 text-lg mb-4 flex items-center gap-2">
                            <svg class="w-5 h-5" style="color: var(--brand-accent);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/></svg>
                            Areas We Cover
                        </h3>
                        <p class="text-gray-600 leading-relaxed">{{ $data['coverage'] }}</p>
                    </div>
                @endif
            </div>

            @if ($lat && $lng)
                <div class="mt-10 rounded-lg overflow-hidden shadow-md" style="height: 300px;" id="contact-map"></div>
                <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
                <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        var map = L.map('contact-map').setView([{{ $lat }}, {{ $lng }}], 14);
                        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                            maxZoom: 19,
                            attribution: '&copy; <a href="https://openstreetmap.org/copyright">OpenStreetMap</a>'
                        }).addTo(map);
                        L.marker([{{ $lat }}, {{ $lng }}]).addTo(map);
                    });
                </script>
            @endif
        @endif
    </div>
</div>
