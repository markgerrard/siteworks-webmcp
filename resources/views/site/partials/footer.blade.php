{{-- Brand-themed dark footer. Visual break from any dark band above
     (lead_form on service pages) is achieved through depth, not a
     coloured rule:
     - 1px inset highlight at top simulates a light catch on a raised
       panel edge (SaaS/tech sites — Stripe, Linear)
     - Soft gradient fade in the top ~120px: ~6% white tint at edge,
       fading to pure --color-band — creates the illusion of the
       footer sitting "below" the form rather than continuing it
     Brand-aware — adapts per site through --color-band. --}}
@php
    $pinnedById = collect($pinnedPages ?? [])->keyBy(
        fn ($p) => (int) (is_array($p) ? ($p['id'] ?? 0) : ($p->id ?? 0)),
    );
    $resolvedFooterColumns = [];
    foreach ($composition['footer']['columns'] ?? [] as $column) {
        if (! is_array($column)) {
            continue;
        }
        $title = is_string($column['title'] ?? null) ? $column['title'] : '';
        $resolvedItems = [];
        foreach ($column['items'] ?? [] as $item) {
            if (count($resolvedItems) >= 8) {
                break;
            }
            if (! is_array($item)) {
                continue;
            }
            $pinned = $pinnedById->get((int) ($item['page_id'] ?? 0));
            if ($pinned === null) {
                continue;
            }
            $label = is_array($pinned) ? ($pinned['nav_label'] ?? '') : ($pinned->nav_label ?? '');
            $href = is_array($pinned) ? ($pinned['url'] ?? '') : ($pinned->url ?? '');
            if (! is_string($label) || $label === '' || ! is_string($href) || $href === '') {
                continue;
            }
            $resolvedItems[] = [
                'label' => $label,
                'href' => $href,
            ];
        }
        if ($resolvedItems !== []) {
            $resolvedFooterColumns[] = [
                'title' => $title,
                'items' => $resolvedItems,
            ];
        }
    }
    $footerMotto = is_string($site->footer_motto) && trim($site->footer_motto) !== '' ? trim($site->footer_motto) : null;
    $showCredit = $composition['footer']['show_credit'] ?? true;
    $softBrandSections = ($theme['brand_section_scheme'] ?? null) === 'soft';
    $footerSchemeAttribute = $softBrandSections ? ' data-brand-section-scheme="soft"' : '';
@endphp
<footer{!! $footerSchemeAttribute !!} style="background-color: {{ $softBrandSections ? 'var(--color-brand-section-surface)' : 'var(--color-band)' }};
               background-image: {{ $softBrandSections ? 'linear-gradient(180deg, color-mix(in srgb, var(--brand-primary) 5%, transparent) 0%, transparent 120px)' : 'linear-gradient(180deg, rgb(255 255 255 / 0.06) 0%, transparent 120px)' }};
               color: {{ $softBrandSections ? 'var(--color-brand-section-ink)' : 'var(--color-text-on-band)' }};@if($softBrandSections)
               --brand-accent-text: var(--color-brand-section-accent-ink);
               --color-text-on-band: var(--color-brand-section-ink);@endif
               box-shadow: inset 0 1px 0 {{ $softBrandSections ? 'color-mix(in srgb, var(--brand-primary) 14%, transparent)' : 'rgb(255 255 255 / 0.10)' }};">{!! \App\Support\Textures\TextureLayer::html($siteTexture ?? null, ['style_overrides' => []], false, $site ?? null) !!}
    <div class="site-shell-container px-4 sm:px-6 lg:px-8 py-12">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-10">
            {{-- Company info --}}
            <div>
                @if ($site->footer_show_logo && ! empty($logoUrl))<img src="{{ $logoUrl }}" alt="{{ $site->business_name }}" class="h-12 w-auto object-contain mb-4">@endif<h3 class="text-lg font-bold mb-3" style="color: var(--color-text-on-band);">{{ $site->business_name }}</h3>
                @php
                    $footerArea = $profile['geo']['service_area'] ?? null;
                    if ($footerArea) {
                        $footerArea = trim(preg_replace('/\s*\(.*$/', '', $footerArea));
                        $footerArea = \Illuminate\Support\Str::limit($footerArea, 80, '…');
                    }
                @endphp
                @if ($footerArea)
                    <p class="text-sm opacity-80 flex items-center gap-2 mb-3">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        Serving {{ $footerArea }}
                    </p>
                @endif
                @if (!empty($profile['credibility']['trade_bodies']))
                    <div class="mt-4 pt-4 border-t" style="border-color: {{ $softBrandSections ? 'color-mix(in srgb, var(--brand-primary) 18%, transparent)' : 'rgb(255 255 255 / 0.15)' }};">
                        @foreach ($profile['credibility']['trade_bodies'] as $body)
                            <span class="inline-flex items-center gap-1.5 text-xs font-medium opacity-80 mr-3 mb-2">
                                <svg class="w-3.5 h-3.5" style="color: var(--brand-accent-text);" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-.723-1.745 3.066 3.066 0 010-3.976 3.066 3.066 0 00.723-1.745 3.066 3.066 0 012.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                {{ $body }}
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Contact details --}}
            <div>
                <h3 class="text-lg font-bold mb-3" style="color: var(--color-text-on-band);">Get In Touch</h3>
                <div class="space-y-3">
                    @if ($phone = ($profile['contact']['phones'][0] ?? null))
                        <a href="tel:{{ preg_replace('/\s+/', '', $phone) }}" class="flex items-center gap-2 text-sm opacity-80 hover:opacity-100 transition-colors">
                            <svg class="w-4 h-4 flex-shrink-0" style="color: var(--brand-accent-text);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                            {{ $phone }}
                        </a>
                    @endif
                    @if ($mobile = ($profile['contact']['mobile'] ?? null))
                        <a href="tel:{{ preg_replace('/\s+/', '', $mobile) }}"
                           aria-label="Call mobile {{ $mobile }}"
                           class="flex items-center gap-2 text-sm opacity-80 hover:opacity-100 transition-colors">
                            <svg class="w-4 h-4 flex-shrink-0" style="color: var(--brand-accent-text);" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 2a2 2 0 00-2 2v16a2 2 0 002 2h10a2 2 0 002-2V4a2 2 0 00-2-2H7zm5 18a1 1 0 110-2 1 1 0 010 2z"/></svg>
                            {{ $mobile }}
                        </a>
                    @endif
                    @if ($email = ($profile['contact']['emails'][0] ?? null))
                        <a href="mailto:{{ $email }}" class="flex items-center gap-2 text-sm opacity-80 hover:opacity-100 transition-colors">
                            <svg class="w-4 h-4 flex-shrink-0" style="color: var(--brand-accent-text);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            {{ $email }}
                        </a>
                    @endif
                </div>
            </div>

            {{-- Quick links — flatten groups so service pages appear as
                 individual links. Nav items auto-group into a "Services"
                 dropdown in the header; the footer lists each page flat. --}}
            <div class="md:col-span-2">
                <h3 class="text-lg font-bold mb-3" style="color: var(--color-text-on-band);">Quick Links</h3>
                <div class="grid grid-cols-2 gap-x-6 gap-y-2">
                    @php
                        // Prefer the generated footer_label variant
                        // for each page (more descriptive than the
                        // nav/header label). Falls through to the nav
                        // label on legacy pages that don't have a
                        // footer_label yet.
                        $footerLinks = [];
                        foreach ($navItems ?? [] as $item) {
                            if (($item['type'] ?? '') === 'group') {
                                foreach ($item['children'] ?? [] as $child) {
                                    $footerLinks[] = [
                                        'label' => $child['footer_label'] ?? $child['label'] ?? '',
                                        'href'  => $child['href'] ?? '#',
                                    ];
                                }
                            } else {
                                $footerLinks[] = [
                                    'label' => $item['footer_label'] ?? $item['label'] ?? '',
                                    'href'  => $item['href'] ?? '#',
                                ];
                            }
                        }
                    @endphp
                    @foreach ($footerLinks as $link)
                        <a href="{{ $link['href'] }}" class="text-sm opacity-80 hover:opacity-100 transition-colors">
                            {{ $link['label'] }}
                        </a>
                    @endforeach
                </div>
            </div>@if ($resolvedFooterColumns !== [])
            @foreach ($resolvedFooterColumns as $column)
            <div data-footer-column="{{ $column['title'] }}">
                <h3 class="text-lg font-bold mb-3" style="color: var(--color-text-on-band);">{{ $column['title'] }}</h3>
                <div class="space-y-2">
                    @foreach ($column['items'] as $item)
                        <a href="{{ $item['href'] }}" class="block text-sm opacity-80 hover:opacity-100 transition-colors" style="color: var(--color-text-on-band);">
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </div>
            </div>
            @endforeach
@endif
        </div>

@if ($footerMotto === null)
        <div class="mt-10 border-t pt-6 flex flex-col sm:flex-row justify-between items-center gap-4 text-sm opacity-70" style="border-color: {{ $softBrandSections ? 'color-mix(in srgb, var(--brand-primary) 18%, transparent)' : 'rgb(255 255 255 / 0.15)' }};">
            <p>&copy; {{ date('Y') }} {{ $site->business_name }}. All rights reserved.</p>
            @if ($showCredit)
                <p class="text-xs opacity-80">Site by {{ config('app.name') }}</p>
            @endif
        </div>
@else
        <div class="mt-10 border-t pt-6 grid grid-cols-1 sm:grid-cols-3 items-center gap-4 text-sm" style="border-color: {{ $softBrandSections ? 'color-mix(in srgb, var(--brand-primary) 18%, transparent)' : 'rgb(255 255 255 / 0.15)' }};">
            <p class="opacity-70">&copy; {{ date('Y') }} {{ $site->business_name }}. All rights reserved.</p>
            <p data-footer-motto class="italic opacity-100 text-base sm:text-right {{ $showCredit ? 'sm:col-start-2' : 'sm:col-start-2 sm:col-span-2' }}" style="font-family: var(--font-display); color: var(--color-text-on-band);">{{ $footerMotto }}</p>
            @if ($showCredit)<p class="text-xs opacity-70 sm:text-right">Site by {{ config('app.name') }}</p>@endif
        </div>
@endif
    </div>
</footer>
