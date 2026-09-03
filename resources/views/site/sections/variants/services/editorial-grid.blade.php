{{-- Editorial grid: flat photo "cards without cardness" (plusbuilders
     reference) — squarish image, title with a small right-hung accent
     numeral suffix, muted one-liner. No borders, shadows, radius or
     card chrome; whitespace does the work. Featured-N tiles + the
     shared unnumbered two-column "Also covered" tail. Photos resolve
     like photo-cards; a tile without one degrades to type-only. --}}
@php
    $svcItems = array_values($section['items'] ?? []);
    $eyebrow = $section['eyebrow'] ?? 'Our Services';
    // Grid knobs: tiles = grid_columns x grid_rows, or every item when
    // grid_rows is 'all' (then there is no tail). Defaults reproduce the
    // launch behaviour: 4 columns, one row.
    $gridColumns = (int) ($section['__options']['grid_columns'] ?? 4);
    $gridColumns = in_array($gridColumns, [2, 3, 4], true) ? $gridColumns : 4;
    $gridRows = $section['__options']['grid_rows'] ?? 1;
    $featuredCount = $gridRows === 'all'
        ? count($svcItems)
        : max(1, $gridColumns * max(1, min(4, (int) $gridRows)));
    $featuredItems = array_slice($svcItems, 0, $featuredCount);
    $tailItems = array_slice($svcItems, $featuredCount);
    $showNumbers = (bool) ($section['__options']['grid_numbers'] ?? true);
    $gridCornerMap = [
        'square' => null,
        'round-top' => 'var(--radius-card) var(--radius-card) 0 0',
        'round-bottom' => '0 0 var(--radius-card) var(--radius-card)',
        'round-all' => 'var(--radius-card)',
    ];
    $tileRadius = $gridCornerMap[$section['__options']['grid_image_corners'] ?? 'square'] ?? null;
    $gridColsClass = [
        2 => 'sm:grid-cols-2',
        3 => 'sm:grid-cols-2 lg:grid-cols-3',
        4 => 'sm:grid-cols-2 lg:grid-cols-4',
    ][$gridColumns];
    $isContrast = ($section['__surface'] ?? null) === 'contrast';
    $wrapperBg = $isContrast ? 'var(--color-surface-contrast)' : 'var(--color-surface)';
    $textColor = $isContrast ? 'var(--color-text-on-contrast)' : 'var(--color-text)';
    $mutedColor = $isContrast ? 'var(--color-text-muted-on-contrast)' : 'var(--color-text-muted)';
    $accentColor = $isContrast ? 'var(--brand-accent-text-on-contrast)' : 'var(--brand-accent-text)';
    $hairlineBase = $isContrast ? 'var(--color-text-on-contrast)' : 'var(--color-text)';

    $watermarkOn = (bool) (($profile ?? [])['watermark_enabled'] ?? true);
    $slugger = app(\App\Services\Site\ServicePageSlugger::class);
    $gridLocation = $site?->location ?? '';
    $gridScope = is_string(($profile ?? [])['geo']['scope'] ?? null) ? $profile['geo']['scope'] : 'local';
    $tilePhotoUrls = [];
    foreach ($featuredItems as $i => $item) {
        $src = $item['source_service'] ?? null;
        $slug = (is_string($src) && trim($src) !== '') ? $slugger->makeSlug($src, $gridLocation, $gridScope) : null;
        $heroData = ($slug !== null) ? (($heroImages ?? [])[$slug] ?? []) : [];
        $url = null;
        if (is_array($heroData) && $heroData !== []) {
            $url = ($watermarkOn && ! empty($heroData['watermark_url'])) ? $heroData['watermark_url'] : ($heroData['url'] ?? null);
        }
        $tilePhotoUrls[$i] = (is_string($url) && $url !== '') ? $url : null;
    }
    $tileCounts = array_count_values(array_filter($tilePhotoUrls, fn ($u) => $u !== null));
    foreach ($tilePhotoUrls as $i => $u) {
        if ($u !== null && ($tileCounts[$u] ?? 0) > 1) { $tilePhotoUrls[$i] = null; }
    }
@endphp
<div id="home-content"
     data-svc-variant="editorial-grid"
     @class([
         'scroll-mt-24 md:scroll-mt-28',
         'site-section-spacing' => $isContrast,
         'pt-10 lg:pt-12' => ! $isContrast,
     ])
     style="background-color: {{ $wrapperBg }};@unless ($isContrast) padding-bottom: var(--section-spacing);@endunless">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        @if (!empty($section['title']))
            <div class="max-w-3xl mb-6">
                @if (empty($section['__suppress_eyebrow']))
                    <span class="text-sm font-bold tracking-widest uppercase mb-3 block" style="color: {{ $accentColor }};" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                @endif
                <h2 class="text-3xl md:text-4xl font-extrabold text-pretty" style="color: {{ $textColor }};"
                    {!! $editor('title', 'plain') !!}>{!! app(App\Services\Site\AccentWordRenderer::class)->wrap($section['title'], $section['accent_word'] ?? null, isset($site) && \App\Support\ChromeKnobs::accentStyle($site) === 'italic' ? 'italic' : null, $section['accent_ranges'] ?? null) !!}</h2>
                @if (!empty($section['intro']))
                    <div class="mt-3 text-base md:text-lg prose" style="color: {{ $mutedColor }};"
                         {!! $editor('intro', 'rich', is_array($section['intro']) ? $section['intro'] : null) !!}>{!! $richHtml($section['intro']) !!}</div>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('intro', 'rich') !!}></span>
                @endif
            </div>
        @elseif ($emitMarkers)
            <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
            <span class="hidden"{!! $editor('title', 'plain') !!}></span>
            <span class="hidden"{!! $editor('intro', 'rich') !!}></span>
        @endif

        @if ($featuredItems !== [])
            <div class="mt-10 grid grid-cols-1 {{ $gridColsClass }} gap-x-6 lg:gap-x-8 gap-y-10">
                @foreach ($featuredItems as $i => $item)
                    <div class="group relative">
                        @if ($tilePhotoUrls[$i])
                            <div data-svc-media class="overflow-hidden mb-4"@if ($tileRadius) style="border-radius: {{ $tileRadius }};"@endif>
                                <img src="{{ $tilePhotoUrls[$i] }}" alt="{{ $item['title'] ?? '' }}" loading="lazy"
                                     class="w-full aspect-[4/3] object-cover transition duration-700 ease-out group-hover:scale-105 group-hover:brightness-105">
                            </div>
                        @endif
                        <h3 class="text-lg md:text-xl font-bold flex items-baseline justify-between gap-3" style="color: {{ $textColor }};"
                            {!! $editor("items.{$i}.title", 'plain') !!}>@if ($emitMarkers)<button type="button" class="hidden"{!! $editor("items.{$i}.icon", 'image') !!}></button>@endif@if ($href = $resolveItemHrefForVariants($item))<a href="{{ $href }}" @unless ($emitMarkers) class="after:absolute after:inset-0 after:content-['']" @endunless style="color: inherit;">{{ $item['title'] ?? '' }}</a>@else{{ $item['title'] ?? '' }}@endif@if ($showNumbers)<span class="text-sm font-light tabular-nums shrink-0" style="color: {{ $accentColor }};">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>@endif</h3>
                        <div class="mt-1 text-sm leading-relaxed prose prose-sm" style="color: {{ $mutedColor }};"
                             {!! $editor("items.{$i}.body", 'rich', is_array($item['body'] ?? null) ? $item['body'] : null) !!}>{!! $richHtml($item['body'] ?? '') !!}</div>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($tailItems !== [])
            <p class="mt-12 text-sm font-bold tracking-widest uppercase" style="color: {{ $accentColor }};">Also covered</p>
            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-x-10">
                @foreach ($tailItems as $t => $item)
                    @php $i = $t + $featuredCount; @endphp
                    <div class="group relative flex items-baseline justify-between gap-4 py-3 px-4 -mx-4 transition-colors hover:bg-[color-mix(in_oklab,var(--brand-accent)_8%,transparent)]" style="border-bottom: 1px solid color-mix(in oklab, {{ $hairlineBase }} 12%, transparent);">
                        <div class="min-w-0">
                            <h4 class="text-base font-bold" style="color: {{ $textColor }};"
                                {!! $editor("items.{$i}.title", 'plain') !!}>@if ($emitMarkers)<button type="button" class="hidden"{!! $editor("items.{$i}.icon", 'image') !!}></button>@endif@if ($href = $resolveItemHrefForVariants($item))<a href="{{ $href }}" @unless ($emitMarkers) class="after:absolute after:inset-0 after:content-['']" @endunless style="color: inherit;">{{ $item['title'] ?? '' }}</a>@else{{ $item['title'] ?? '' }}@endif</h4>
                            <div class="text-sm leading-relaxed prose prose-sm" style="color: {{ $mutedColor }};"
                                 {!! $editor("items.{$i}.body", 'rich', is_array($item['body'] ?? null) ? $item['body'] : null) !!}>{!! $richHtml($item['body'] ?? '') !!}</div>
                        </div>
                        <span class="shrink-0 transition-transform group-hover:translate-x-1" style="color: {{ $accentColor }};">&rarr;</span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
