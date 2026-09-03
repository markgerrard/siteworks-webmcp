{{-- Accreditation / trust logos as a tile grid (motion device G8). Each
     trust item's existing `icon` image field supplies the logo; tiles are
     rendered in full colour by default. When the `logo_tile_hover` device
     option is stamped on, a section-scoped <style> block greyscales the
     tiles via CSS filter and returns them to full colour on hover AND
     keyboard focus-visible. The filter styles are emitted ONLY when the
     option is on — option-off / unstamped sites get static colour logos
     with no filter bytes. `prefers-reduced-motion` users get an instant
     swap (no transition). Markers mirror the family superset rule so the
     parity harness stays green. --}}
@php
    $items = array_values($items ?? []);
    $hoverOn = (($section['__options']['logo_tile_hover'] ?? null) === true);
    $eyebrow = $section['eyebrow'] ?? 'Why Choose Us';
    $title = (string) ($section['title'] ?? '');
    $titleMatchesEyebrow = $title !== '' && strcasecmp(trim($title), $eyebrow) === 0;
    $hideEyebrow = $titleMatchesEyebrow || ! empty($section['__suppress_eyebrow']);
@endphp
@if ($items !== [])
    <div data-svc-variant="logo-tiles" class="site-section-spacing"
         style="background-color: var(--color-surface-alt); border-top: 1px solid var(--color-border);">
        <div class="site-shell-container px-4 sm:px-6 lg:px-8">
            @if ($title !== '')
                <div class="text-center mb-12">
                    @if (! $hideEyebrow)
                        <span class="text-sm font-bold tracking-widest uppercase mb-4 block" style="color: var(--brand-accent-text);"
                              {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                    @elseif ($emitMarkers)
                        <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                    @endif
                    <h2 class="text-4xl md:text-5xl font-extrabold leading-tight text-balance" style="color: var(--color-text);"
                        {!! $editor('title', 'plain') !!}>{{ $title }}</h2>
                </div>
            @elseif ($emitMarkers)
                <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                <span class="hidden"{!! $editor('title', 'plain') !!}></span>
            @endif

            @if ($hoverOn)
                <style>
                    .logo-tile-img { filter: grayscale(100%); transition: filter 0.35s ease; }
                    .logo-tile-img:hover,
                    .logo-tile-img:focus-visible { filter: none; }
                    @media (prefers-reduced-motion: reduce) { .logo-tile-img { transition: none; } }
                </style>
            @endif

            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 md:gap-6">
                @foreach ($items as $i => $item)
                    @php
                        $logoUrl = $item['icon'] ?? '';
                        $logoAlt = trim((string) ($item['title'] ?? ''));
                        $tileClass = 'max-h-16 w-auto object-contain'.($hoverOn ? ' logo-tile-img' : '');
                    @endphp
                    <figure class="flex items-center justify-center p-5"
                            style="background-color: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-card); min-height: 6rem;">
                        @if ($emitMarkers)
                            <span class="hidden"{!! $editor("items.{$i}.title", 'plain') !!}></span>
                            <span class="hidden"{!! $editor("items.{$i}.body", 'plain') !!}></span>
                            <button type="button" class="hidden"{!! $editor("items.{$i}.icon", 'image') !!}></button>
                        @endif
                        @if ($logoUrl !== '')
                            <img src="{{ $logoUrl }}" alt="{{ $logoAlt }}"
                                 loading="lazy" class="{{ $tileClass }}">
                        @endif
                    </figure>
                @endforeach
            </div>
        </div>
    </div>
@endif