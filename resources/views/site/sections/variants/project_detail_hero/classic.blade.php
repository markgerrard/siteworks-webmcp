@php
    $title = $section['title'] ?? '';
    $intro = $section['intro'] ?? '';
    $isNested = isset($page) && $page->parent_id !== null;
    $ancestors = [];
    if ($isNested) {
        $cursor = $page->parent;
        while ($cursor) {
            array_unshift($ancestors, $cursor);
            $cursor = $cursor->parent;
        }
    }
    $crumbPages = $ancestors;
    $homeHref = $pagesBySlug['home'] ?? '/';
    $jsonLd = null;
    if ($isNested) {
        $elements = [[
            '@type' => 'ListItem',
            'position' => 1,
            'name' => 'Home',
            'item' => $homeHref,
        ]];
        $position = 2;
        foreach ($crumbPages as $ancestor) {
            $elements[] = [
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => $ancestor->nav_label ?: $ancestor->defaultNavLabel(),
                'item' => $pagesBySlug[$ancestor->page_type] ?? '/'.$ancestor->page_type,
            ];
        }
        $elements[] = [
            '@type' => 'ListItem',
            'position' => $position,
            'name' => $page->nav_label ?: ($title !== '' ? $title : $page->defaultNavLabel()),
            'item' => $pagesBySlug[$page->page_type] ?? '/'.$page->page_type,
        ];
        $jsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $elements,
        ];
    }
@endphp
@php
    // Inherit the projects-hero band treatment: image +
    // canonical scrim + accent bar + inner-page height, breadcrumb overlaid.
    $heroMedia = null;
    $heroMediaId = $section['hero_image_id'] ?? null;
    if ($heroMediaId !== null && isset($mediaById)) {
        $heroMedia = $mediaById->get((int) $heroMediaId);
    }
    $detailHeroImage = $heroMedia?->url;

    $detailHeroH = $profile['hero_sizes']['inner'] ?? '35vh';
    $detailHeroH = is_string($detailHeroH) && preg_match('/^\d{1,3}vh$/', $detailHeroH)
        ? $detailHeroH
        : '35vh';
    $detailCopyStyle = ($effectiveOverlay ?? false)
        ? 'min-height: calc('.$detailHeroH.' + var(--overlay-header-h, 0px));'
        : 'height: '.$detailHeroH.'; min-height: 260px;';
    $textShadowClass = $detailHeroImage ? 'drop-shadow-[0_2px_8px_rgba(0,0,0,0.7)]' : '';
    $crumbMuted = $detailHeroImage ? 'rgba(255,255,255,0.6)' : 'var(--color-text-muted)';
@endphp
<div class="relative overflow-hidden w-full" style="background-color: var(--color-surface);" data-project-detail-hero data-svc-variant="{{ $svcVariant }}">
    @if (! $detailHeroImage)
        <div class="absolute inset-0"
             style="background: linear-gradient(
                135deg,
                color-mix(in oklab, var(--color-accent) 18%, var(--color-surface)) 0%,
                var(--color-surface) 70%
             );">
        </div>
    @endif
    @if ($detailHeroImage)
        <img class="absolute inset-0 w-full h-full object-cover" src="{{ $detailHeroImage }}" alt="">
        <div class="absolute inset-0 bg-gradient-to-r from-black/70 via-black/40 to-transparent"></div>
    @endif

    <div class="relative site-shell-container px-4 sm:px-6 lg:px-8 flex flex-col justify-center{{ ($effectiveOverlay ?? false) ? ' overlay-hero-copy-inner' : '' }}"
         style="{{ $detailCopyStyle }}">
        <div class="max-w-3xl text-left py-8">
            @if ($isNested)
                <nav aria-label="Breadcrumb" class="mb-4">
                    <ol class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs font-bold uppercase tracking-[0.18em] {{ $textShadowClass }}"
                        style="color: {{ $detailHeroImage ? '#ffffff' : 'var(--brand-accent-text)' }};">
                        <li><a href="{{ $homeHref }}" class="hover:underline">Home</a></li>
                        @foreach ($crumbPages as $ancestor)
                            <li aria-hidden="true" class="px-1" style="color: {{ $crumbMuted }};">/</li>
                            <li><a href="{{ $pagesBySlug[$ancestor->page_type] ?? '/'.$ancestor->page_type }}" class="hover:underline">{{ $ancestor->nav_label ?: $ancestor->defaultNavLabel() }}</a></li>
                        @endforeach
                        <li aria-hidden="true" class="px-1" style="color: {{ $crumbMuted }};">/</li>
                        <li aria-current="page" style="color: {{ $detailHeroImage ? 'rgba(255,255,255,0.85)' : 'var(--color-text)' }};">{{ $page->nav_label ?: ($title !== '' ? $title : $page->defaultNavLabel()) }}</li>
                    </ol>
                </nav>
            @endif

            @if ($title !== '')
                <h1 class="text-3xl md:text-4xl lg:text-5xl font-extrabold leading-tight text-pretty mb-3 {{ $textShadowClass }}"
                    style="color: {{ $detailHeroImage ? '#ffffff' : 'var(--color-text)' }}; font-family: var(--font-display);"
                    {!! $editor('title', 'plain') !!}>{{ $title }}</h1>
            @elseif ($emitMarkers)
                <span class="hidden"{!! $editor('title', 'plain') !!}></span>
            @endif

            @if ($intro !== '')
                <p class="text-base md:text-lg max-w-2xl {{ $textShadowClass }}"
                   style="color: {{ $detailHeroImage ? 'rgba(255,255,255,0.9)' : 'var(--color-text-muted)' }}; font-family: var(--font-body);"
                   {!! $editor('intro', 'plain') !!}>{{ $intro }}</p>
            @elseif ($emitMarkers)
                <span class="hidden"{!! $editor('intro', 'plain') !!}></span>
            @endif
        </div>
    </div>
    <div class="relative z-[3] h-1.5" style="background-color: var(--brand-accent);"></div>
</div>
@if ($jsonLd)
    <script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endif
