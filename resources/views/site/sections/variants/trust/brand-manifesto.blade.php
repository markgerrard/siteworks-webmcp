{{-- Editorial brand manifesto: the page's one full-bleed brand moment.
     Unnumbered — trust claims are principles, not a sequence. Headline +
     accent rule left, 2x2 pillar grid right. Consumes surfaces value
     `brand` only; unstamped it renders the same layout on base tokens. --}}
@php
    $items = array_values($items ?? []);
    $eyebrow = $section['eyebrow'] ?? 'Why Choose Us';
    $title = (string) ($section['title'] ?? '');
    $titleMatchesEyebrow = $title !== '' && strcasecmp(trim($title), $eyebrow) === 0;
    $hideEyebrow = $titleMatchesEyebrow || ! empty($section['__suppress_eyebrow']);
    $isBrand = ($section['__surface'] ?? null) === 'brand';
    $isSoftBrand = $isBrand && ($theme['brand_section_scheme'] ?? null) === 'soft';
    $wrapperBg = $isSoftBrand ? 'var(--color-brand-section-surface)' : ($isBrand ? 'var(--brand-primary)' : 'var(--color-surface)');
    $titleColor = $isSoftBrand ? 'var(--color-brand-section-ink)' : ($isBrand ? 'var(--color-text-on-primary, #ffffff)' : 'var(--color-text)');
    $bodyColor = $isSoftBrand ? 'var(--color-brand-section-muted-ink)' : ($isBrand ? 'color-mix(in oklab, var(--color-text-on-primary, #ffffff) 82%, transparent)' : 'var(--color-text-muted)');
    $eyebrowColor = $isSoftBrand ? 'var(--color-brand-section-accent-ink)' : ($isBrand ? 'var(--brand-accent)' : 'var(--brand-accent-text)');
@endphp
@if ($items !== [])
    <div data-svc-variant="brand-manifesto" class="site-section-spacing" style="background-color: {{ $wrapperBg }};">
        <div class="site-shell-container px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-[1fr_1.4fr] gap-12 lg:gap-16">
                <div>
                    @if (! $hideEyebrow)
                        <span class="text-sm font-bold tracking-widest uppercase mb-3 block" style="color: {{ $eyebrowColor }};" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                    @elseif ($emitMarkers)
                        <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                    @endif
                    @if ($title !== '')
                        <h2 class="text-3xl md:text-4xl font-extrabold text-pretty" style="color: {{ $titleColor }};"
                            {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
                    @elseif ($emitMarkers)
                        <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                    @endif
                    <div class="w-12 h-1 mt-6" style="background-color: var(--brand-accent);"></div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-8 lg:gap-10">
                    @foreach ($items as $i => $item)
                        <div class="border-l-2 pl-5" style="border-color: var(--brand-accent);">
                            <h3 class="text-lg md:text-xl font-bold" style="color: {{ $titleColor }};"
                                {!! $editor("items.{$i}.title", 'plain') !!}>@if ($emitMarkers)<button type="button" class="hidden"{!! $editor("items.{$i}.icon", 'image') !!}></button>@endif{{ $item['title'] ?? '' }}</h3>
                            <p class="mt-2 text-sm md:text-base leading-relaxed" style="color: {{ $bodyColor }};"
                               {!! $editor("items.{$i}.body", 'plain') !!}>{{ $item['body'] ?? '' }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
@endif
