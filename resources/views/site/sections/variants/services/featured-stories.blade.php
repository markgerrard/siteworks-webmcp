{{-- Editorial featured stories: first-N services as photo-led split
     rows (ghosted display ordinals, image/copy alternating), remaining
     items as an unnumbered two-column "Also covered" index. Missing or
     duplicate photos degrade that row to type-led copy. Same hairline
     ledger kit as numbered-rows; CSS-only hover (tint + arrow slide). --}}
@php
    $svcItems = array_values($section['items'] ?? []);
    $eyebrow = $section['eyebrow'] ?? 'Our Services';
    $featuredCount = max(1, (int) ($section['__options']['featured_count'] ?? 4));
    $featuredItems = array_slice($svcItems, 0, $featuredCount);
    $tailItems = array_slice($svcItems, $featuredCount);
    $isContrast = ($section['__surface'] ?? null) === 'contrast';
    $wrapperBg = $isContrast ? 'var(--color-surface-contrast)' : 'var(--color-surface)';
    $textColor = $isContrast ? 'var(--color-text-on-contrast)' : 'var(--color-text)';
    $mutedColor = $isContrast ? 'var(--color-text-muted-on-contrast)' : 'var(--color-text-muted)';
    $accentColor = $isContrast ? 'var(--brand-accent-text-on-contrast)' : 'var(--brand-accent-text)';
    $hairlineBase = $isContrast ? 'var(--color-text-on-contrast)' : 'var(--color-text)';

    $watermarkOn = (bool) (($profile ?? [])['watermark_enabled'] ?? true);
    $slugger = app(\App\Services\Site\ServicePageSlugger::class);
    $storyLocation = $site?->location ?? '';
    $storyScope = is_string(($profile ?? [])['geo']['scope'] ?? null) ? $profile['geo']['scope'] : 'local';
    $storyPhotoUrls = [];
    foreach ($featuredItems as $i => $item) {
        $src = $item['source_service'] ?? null;
        $slug = (is_string($src) && trim($src) !== '') ? $slugger->makeSlug($src, $storyLocation, $storyScope) : null;
        $heroData = ($slug !== null) ? (($heroImages ?? [])[$slug] ?? []) : [];
        $url = null;
        if (is_array($heroData) && $heroData !== []) {
            $url = ($watermarkOn && ! empty($heroData['watermark_url'])) ? $heroData['watermark_url'] : ($heroData['url'] ?? null);
        }
        $storyPhotoUrls[$i] = (is_string($url) && $url !== '') ? $url : null;
    }
    $storyCounts = array_count_values(array_filter($storyPhotoUrls, fn ($u) => $u !== null));
    foreach ($storyPhotoUrls as $i => $u) {
        if ($u !== null && ($storyCounts[$u] ?? 0) > 1) { $storyPhotoUrls[$i] = null; }
    }
@endphp
<div id="home-content"
     data-svc-variant="featured-stories"
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
                    <span class="hidden"{!! $editor('intro', 'rich') !!}</span>
                @endif
            </div>
        @elseif ($emitMarkers)
            <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
            <span class="hidden"{!! $editor('title', 'plain') !!}></span>
            <span class="hidden"{!! $editor('intro', 'rich') !!}</span>
        @endif

        @if ($featuredItems !== [])
            <div class="mt-10">
                @foreach ($featuredItems as $i => $item)
                    @php $storyImg = $storyPhotoUrls[$i]; @endphp
                    <div class="group relative grid grid-cols-1 {{ $storyImg ? 'lg:grid-cols-[2fr_3fr]' : '' }} gap-x-8 gap-y-4 items-center py-8 pl-6 -ml-6 transition-colors hover:bg-[color-mix(in_oklab,var(--brand-accent)_8%,transparent)]"
                         style="border-bottom: 1px solid color-mix(in oklab, {{ $hairlineBase }} 18%, transparent);@if ($i === 0) border-top: 1px solid color-mix(in oklab, {{ $hairlineBase }} 18%, transparent);@endif">
                        @if ($storyImg)
                            <div data-svc-media @class(['overflow-hidden', 'lg:order-last' => $i % 2 === 1])>
                                <img src="{{ $storyImg }}" alt="{{ $item['title'] ?? '' }}" loading="lazy" class="w-full aspect-[16/10] object-cover">
                            </div>
                        @endif
                        <div>
                            <span class="font-light tabular-nums leading-none block mb-2" style="font-size: clamp(2.5rem, 4.5vw, 3.75rem); color: color-mix(in oklab, {{ $accentColor }} 28%, transparent);">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>
                            <h3 class="text-2xl md:text-3xl font-bold" style="color: {{ $textColor }};"
                                {!! $editor("items.{$i}.title", 'plain') !!}>@if ($emitMarkers)<button type="button" class="hidden"{!! $editor("items.{$i}.icon", 'image') !!}></button>@endif@if ($href = $resolveItemHrefForVariants($item))<a href="{{ $href }}" @unless ($emitMarkers) class="after:absolute after:inset-0 after:content-['']" @endunless style="color: inherit;">{{ $item['title'] ?? '' }}</a>@else{{ $item['title'] ?? '' }}@endif<span class="whitespace-nowrap">&#160;<span class="inline-block transition-transform group-hover:translate-x-1" style="color: {{ $accentColor }};">&rarr;</span></span></h3>
                            <div class="mt-2 text-base leading-relaxed prose prose-sm" style="color: {{ $mutedColor }};"
                                 {!! $editor("items.{$i}.body", 'rich', is_array($item['body'] ?? null) ? $item['body'] : null) !!}>{!! $richHtml($item['body'] ?? '') !!}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($tailItems !== [])
            <p class="mt-10 text-sm font-bold tracking-widest uppercase" style="color: {{ $accentColor }};">Also covered</p>
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
