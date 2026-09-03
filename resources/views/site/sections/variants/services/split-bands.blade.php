{{-- Bespoke split-band services: the intro/split kit chrome (full-bleed
     photo half + solid brand-primary panel half, accent eyebrow-rule) at
     reduced band height — one band per service item, sides alternating.
     Header mirrors numbered-rows on the base surface. Item photos resolve
     exactly like photo-cards (service-page hero by source_service slug,
     watermark honoured, duplicate URLs fall back to a panel-only band).
     Fixed-surface bands: this variant deliberately ignores the surfaces
     stamp, so the registry allowlist must NOT include it. --}}
@php
    $svcItems = array_values($section['items'] ?? []);
    $eyebrow = $section['eyebrow'] ?? 'Our Services';

    $watermarkOn = (bool) (($profile ?? [])['watermark_enabled'] ?? true);
    $slugger = app(\App\Services\Site\ServicePageSlugger::class);
    $bandLocation = $site?->location ?? '';
    $bandScope = is_string(($profile ?? [])['geo']['scope'] ?? null) ? $profile['geo']['scope'] : 'local';
    $bandPhotoUrls = [];
    foreach ($svcItems as $i => $item) {
        $src = $item['source_service'] ?? null;
        $slug = (is_string($src) && trim($src) !== '') ? $slugger->makeSlug($src, $bandLocation, $bandScope) : null;
        $heroData = ($slug !== null) ? (($heroImages ?? [])[$slug] ?? []) : [];
        $url = null;
        if (is_array($heroData) && $heroData !== []) {
            $url = ($watermarkOn && ! empty($heroData['watermark_url']))
                ? $heroData['watermark_url']
                : ($heroData['url'] ?? null);
        }
        $bandPhotoUrls[$i] = (is_string($url) && $url !== '') ? $url : null;
    }
    $bandPhotoCounts = array_count_values(array_filter($bandPhotoUrls, fn ($u) => $u !== null));
    foreach ($bandPhotoUrls as $i => $u) {
        if ($u !== null && ($bandPhotoCounts[$u] ?? 0) > 1) {
            $bandPhotoUrls[$i] = null;
        }
    }
@endphp
{{-- Top spacing only: the section ends on a full-bleed band, so the
     neighbour's own top padding provides the seam below. --}}
<div id="home-content"
     data-svc-variant="split-bands"
     class="scroll-mt-24 md:scroll-mt-28"
     style="background-color: var(--color-surface); padding-top: var(--section-spacing);">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        @if (!empty($section['title']))
            <div class="max-w-3xl mb-6">
                @if (empty($section['__suppress_eyebrow']))
                    <span class="text-sm font-bold tracking-widest uppercase mb-3 block" style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                @endif
                <h2 class="text-3xl md:text-4xl font-extrabold text-pretty" style="color: var(--color-text);"
                    {!! $editor('title', 'plain') !!}>{!! app(App\Services\Site\AccentWordRenderer::class)->wrap($section['title'], $section['accent_word'] ?? null, isset($site) && \App\Support\ChromeKnobs::accentStyle($site) === 'italic' ? 'italic' : null, $section['accent_ranges'] ?? null) !!}</h2>
                @if (!empty($section['intro']))
                    <div class="mt-3 text-base md:text-lg prose" style="color: var(--color-text-muted);"
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

    </div>

    @if ($svcItems !== [])
        {{-- Bands bleed full-width like the service-page split; each
             panel's copy stays on the container grid — an inner block of
             container-width / 2 hugging the centre line, so text flows
             with the shell-contained header above. --}}
        <div class="mt-10">
            @foreach ($svcItems as $i => $item)
                @php
                    $bandImg = $bandPhotoUrls[$i];
                    $panelInnerStyle = $bandImg
                        ? 'width: min(100%, calc(var(--container-width) / 2)); margin-'.($i % 2 === 1 ? 'left' : 'right').': auto;'
                        : 'width: min(100%, var(--container-width)); margin-left: auto; margin-right: auto;';
                    $href = $resolveItemHrefForVariants($item);
                @endphp
                <div class="grid grid-cols-1 {{ $bandImg ? 'lg:grid-cols-2' : '' }} overflow-hidden" style="background-color: var(--brand-primary);">
                    @if ($bandImg)
                        <div data-svc-media @class(['relative min-h-[240px] lg:min-h-full', 'lg:order-last' => $i % 2 === 1])>
                            <img src="{{ $bandImg }}" alt="{{ $item['title'] ?? 'Service detail' }}"
                                 class="absolute inset-0 w-full h-full object-cover" loading="lazy">
                        </div>
                    @endif
                    <div class="flex flex-col justify-center">
                        <div class="px-4 py-10 sm:px-6 lg:px-8 lg:py-12" style="{{ $panelInnerStyle }}">
                            <h3 class="text-2xl md:text-3xl font-extrabold leading-tight max-w-xl text-pretty" style="color: var(--color-text-on-primary, #ffffff);"
                                {!! $editor("items.{$i}.title", 'plain') !!}>@if ($emitMarkers)<button type="button" class="hidden"{!! $editor("items.{$i}.icon", 'image') !!}></button>@endif{{ $item['title'] ?? '' }}</h3>
                            <div class="w-12 h-1 mt-4 mb-5" style="background-color: var(--brand-accent);"></div>
                            <div class="text-base leading-relaxed max-w-xl prose prose-invert [&>p]:mb-0"
                                 style="color: color-mix(in oklab, var(--color-text-on-primary, #ffffff) 82%, transparent);"
                                 {!! $editor("items.{$i}.body", 'rich', is_array($item['body'] ?? null) ? $item['body'] : null) !!}>{!! $richHtml($item['body'] ?? '') !!}</div>
                            @if ($href)
                                <a href="{{ $href }}"
                                   class="group inline-flex items-center gap-2 text-xs font-bold uppercase tracking-[0.12em] pb-1 border-b-2 transition-all hover:gap-3 hover:brightness-110 mt-6 self-start"
                                   style="color: var(--brand-accent); border-color: var(--brand-accent);">
                                    Read more
                                    <svg class="w-3.5 h-3.5 transition-transform group-hover:translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
