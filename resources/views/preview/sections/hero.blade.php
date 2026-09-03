@php
    // Multi-slide hero scenes — same early-return pattern as the public
    // template so the preview surface mirrors what visitors actually see.
    // $project here is the live Site model.
    $heroSceneResolved = null;
    if ((($pageType ?? '') === 'home' || ($pageType ?? '') === '') && isset($project)) {
        $heroSceneResolved = app(\App\Services\Site\HeroSceneService::class)->resolve($project, [
            'heading' => $data['heading'] ?? null,
            'subheading' => $data['subheading'] ?? null,
            'cta_label' => $data['cta_label'] ?? null,
        ], false);
    }
    // Mirror site/sections/hero.blade.php: use the scene partial whenever
    // we have a non-legacy resolved scene with at least one slide, not just
    // when count > 1.
    $heroUsesSceneRenderer = $heroSceneResolved
        && ! ($heroSceneResolved['is_legacy'] ?? false)
        && ! empty($heroSceneResolved['slides']);
    if ($heroUsesSceneRenderer) {
        $heroSceneSizes = $heroSizeConfig ?? [];
        $heroSceneH = $heroSceneSizes['home'] ?? '55vh';
        $heroSceneMinH = '280px';
        // Optional per-site height from home_hero_scene (validated in
        // HeroSceneService — never raw JSON). Mirrors public site template.
        if (! empty($heroSceneResolved['height'])) {
            $heroSceneH = $heroSceneResolved['height'];
        }
    }
@endphp

@if ($heroUsesSceneRenderer)
    @php
        // The preview partial expects $site + $pagesBySlug; map from preview locals.
        $site = $project;
        $pagesBySlug = $pagesBySlug ?? [];
    @endphp
    @include('site.sections._hero_scene', [
        'scene' => $heroSceneResolved,
        'heroH' => $heroSceneH,
        'heroMinH' => $heroSceneMinH,
        // Mirror the public template — preserve user-edited section eyebrow
        // + accent word overrides on the preview surface too.
        'sceneEyebrowOverride' => $data['eyebrow'] ?? null,
        'sceneAccentWord' => $data['accent_word'] ?? null,
    ])
    @php return; @endphp
@endif

@php
    $p = $heroPlacement ?? [];
    // text_zone is the new 3x3 grid cell from the vision analyser. Legacy
    // placements that only had text_position + text_vertical are converted
    // on the fly so historical snapshots still render.
    $textZone = $p['text_zone'] ?? null;
    if (! $textZone) {
        $legacyH = $p['text_position'] ?? 'left';
        $legacyV = $p['text_vertical'] ?? 'center';
        $textZone = ($legacyV === 'bottom' ? 'bottom' : 'middle').'-'.$legacyH;
    }
    [$textRow, $textCol] = explode('-', $textZone);

    $overlayDir = $p['overlay_direction'] ?? 'to-r';
    $overlayStrength = $p['overlay_strength'] ?? 'medium';
    $textColor = $p['text_color'] ?? 'white';

    // The overlay colour depends on the text colour. Dark text needs the
    // area underneath BRIGHTENED (white overlay) so the letters read; white
    // text needs it DARKENED (black overlay). Applying a black overlay to
    // a scene destined for dark text makes the copy completely unreadable
    // — classic low-contrast failure.
    $overlayColour = $textColor === 'dark' ? 'white' : 'black';

    // Map overlay direction to Tailwind gradient. to-t/to-b added so top
    // and bottom zones can weight their overlay to the chosen edge.
    $gradientMap = [
        'to-r' => "bg-gradient-to-r from-{$overlayColour}/{opacity} via-{$overlayColour}/{half} to-transparent",
        'to-l' => "bg-gradient-to-l from-{$overlayColour}/{opacity} via-{$overlayColour}/{half} to-transparent",
        'to-br' => "bg-gradient-to-br from-{$overlayColour}/{opacity} via-{$overlayColour}/{half} to-transparent",
        'to-bl' => "bg-gradient-to-bl from-{$overlayColour}/{opacity} via-{$overlayColour}/{half} to-transparent",
        'to-t' => "bg-gradient-to-t from-{$overlayColour}/{opacity} via-{$overlayColour}/{half} to-transparent",
        'to-b' => "bg-gradient-to-b from-{$overlayColour}/{opacity} via-{$overlayColour}/{half} to-transparent",
    ];

    // Dark-text overlays need to be STRONGER than dark-text would suggest,
    // because brightening a photo to near-white leaves more colour intact
    // than darkening one does. Bias the opacity up one tier when the
    // overlay is white so "light" actually lifts the area enough for the
    // text to read.
    if ($overlayColour === 'white') {
        $opacityMap = [
            'light' => ['opacity' => '70', 'half' => '45'],
            'medium' => ['opacity' => '85', 'half' => '60'],
            'heavy' => ['opacity' => '95', 'half' => '75'],
        ];
    } else {
        $opacityMap = [
            'light' => ['opacity' => '50', 'half' => '25'],
            'medium' => ['opacity' => '70', 'half' => '40'],
            'heavy' => ['opacity' => '85', 'half' => '55'],
        ];
    }

    $opVals = $opacityMap[$overlayStrength] ?? $opacityMap['medium'];
    $gradientTemplate = $gradientMap[$overlayDir] ?? $gradientMap['to-r'];
    $gradientClass = str_replace(['{opacity}', '{half}'], [$opVals['opacity'], $opVals['half']], $gradientTemplate);

    // Map the chosen zone onto the hero container (vertical) and the inner
    // block (horizontal). Top zones need enough top padding to clear the
    // sticky site header (~64px) plus breathing room; bottom zones need a
    // comfortable gap above the accent bar / next section.
    $verticalClass = match ($textRow) {
        'top' => 'justify-start',
        'bottom' => 'justify-end',
        default => 'justify-center',
    };
    $paddingClass = match ($textRow) {
        'top' => 'pt-28 md:pt-36 pb-10',
        'bottom' => 'pt-10 pb-16 md:pb-20',
        default => 'py-28 md:py-36 lg:py-40',
    };
    $horizontalClass = match ($textCol) {
        'right' => 'ml-auto text-right',
        'center' => 'mx-auto text-center',
        default => 'text-left',
    };
    $flexJustifyClass = match ($textCol) {
        'right' => 'justify-end',
        'center' => 'justify-center',
        default => '',
    };

    // Background-position so the chosen zone of the 16:9 image stays visible
    // when the container is cropped to a non-16:9 aspect (narrow mobile, tall
    // desktop, etc). The vision analyser assumes the full image — we pin the
    // crop so its chosen zone never gets cut off.
    $bgPositionX = match ($textCol) {
        'right' => 'right',
        'center' => 'center',
        default => 'left',
    };
    $customCropY = $p['bg_position_y'] ?? null;
    $bgPositionY = $customCropY !== null
        ? $customCropY.'%'
        : match ($textRow) {
            'top' => 'top',
            'bottom' => 'bottom',
            default => 'center',
        };
    $bgPosition = $bgPositionX.' '.$bgPositionY;

    $textColorClass = $textColor === 'dark' ? 'text-gray-900' : 'text-white';
    $subTextClass = $textColor === 'dark' ? 'text-gray-700' : 'text-white/80';

    // Same knob resolver as the public hero — live Site, not a snapshot
    // scalar. preset keeps $data['variant']; plain/panel/boxed override it.
    // Knob boxed = compact painted box (36rem, chrome radius); knob panel
    // = full-height left band; preset keeps the historical 44rem left panel.
    $heroCopyVariant = isset($project)
        ? \App\Support\ChromeKnobs::heroCopyVariant($project, $data['variant'] ?? null)
        : (is_string($data['variant'] ?? null) && $data['variant'] !== '' ? $data['variant'] : null);
    $heroCopyKnob = isset($project) ? \App\Support\ChromeKnobs::heroCopyStyle($project) : 'preset';
    $isBoxedLeft = $heroCopyVariant === 'boxed-left';
    $isPanelLeft = $heroCopyVariant === 'panel-left';
    $usesLeftPanel = $isBoxedLeft || $isPanelLeft;
    if ($usesLeftPanel) {
        $horizontalClass = match ($textCol) {
            'center' => 'text-center mx-auto items-center',
            'right' => 'text-left ml-auto items-start',
            default => 'text-left mr-auto items-start',
        };
    }
    $heroCopyBoxClass = '';
    $heroCopySurfaceStyle = 'background-color: color-mix(in srgb, var(--brand-primary) 78%, transparent); border-radius: var(--radius-card); padding: 1.5rem 2rem; max-width: 44rem;';
    if ($usesLeftPanel && $heroCopyKnob === 'boxed') {
        $copyRadius = isset($project) && \App\Support\ChromeKnobs::heroCorners($project) === 'square'
            ? '0'
            : 'var(--radius-card)';
        $heroCopyBoxClass = ' hero-copy-box';
        $heroCopySurfaceStyle = 'background-color: color-mix(in srgb, var(--brand-primary) 78%, transparent); border-radius: '.$copyRadius.'; padding: 1.5rem 2rem; max-width: 36rem;';
    } elseif ($usesLeftPanel && $heroCopyKnob === 'panel') {
        $heroCopySurfaceStyle = 'background-color: color-mix(in srgb, var(--brand-primary) 78%, transparent); padding: 1.5rem 2rem; max-width: 44rem; min-height: 100%;';
    }
    // Strong drop-shadow so hero copy stays legible regardless of image contents
    $textShadow = $textColor === 'dark'
        ? 'drop-shadow-[0_2px_4px_rgba(255,255,255,0.9)] drop-shadow-[0_0_12px_rgba(255,255,255,0.6)]'
        : 'drop-shadow-[0_2px_8px_rgba(0,0,0,0.7)]';

    // Wrap any occurrence of the business name in the heading with
    // whitespace-nowrap so short acronym-style names like "B A Berry" stay
    // on a single line instead of breaking on the inner spaces.
    $rawHeading = $data['heading'] ?? '';
    $headingHtml = e($rawHeading);
    $nameCandidates = array_values(array_filter(array_unique([
        $project->business_name ?? null,
        $profile['name'] ?? null,
    ])));
    foreach ($nameCandidates as $name) {
        $escapedName = e($name);
        if ($escapedName !== '' && str_contains($headingHtml, $escapedName)) {
            $headingHtml = str_replace(
                $escapedName,
                '<span class="whitespace-nowrap">'.$escapedName.'</span>',
                $headingHtml,
            );
            break;
        }
    }
@endphp

@php
    // Optional per-site self-hosted MP4 background video on the home hero —
    // see site/sections/hero.blade.php for the same pattern in the
    // public-site template. $project here is the live Site model
    // (PreviewController:96).
    $heroIsHome = (($pageType ?? '') === 'home' || ($pageType ?? '') === '');
    $heroVideoUrl = null;
    if ($heroIsHome && ($project->home_hero_video_enabled ?? false)) {
        // Versioned: prefer the active HeroVideoVersion's S3 key (mirrored
        // onto sites.home_hero_video_path). Legacy canonical path is kept
        // as a fallback for sites that pre-date the versioning rollout.
        $heroVideoKey = $project->home_hero_video_path
            ?: 'dev-previews/'.$project->id.'/hero-home-video.mp4';
        $disk = \Illuminate\Support\Facades\Storage::disk('s3');
        if ($disk->exists($heroVideoKey)) {
            $heroVideoUrl = $disk->url($heroVideoKey);
        }
    }
@endphp
<div class="relative overflow-hidden"
     @if ($heroVideoUrl)
         style="background-color: #000;"
     @elseif (!empty($heroImageUrl))
         style="background-image: url('{{ $heroImageUrl }}'); background-size: cover; background-position: {{ $bgPosition }};"
     @else
         style="background-color: var(--brand-primary);"
     @endif>
    @if ($heroVideoUrl)
        <video
            class="absolute inset-0 w-full h-full object-cover z-0 pointer-events-none"
            autoplay muted loop playsinline preload="auto"
        >
            <source src="{{ $heroVideoUrl }}" type="video/mp4">
        </video>
        <div class="absolute inset-0 z-[1] {{ $gradientClass }}"></div>
    @elseif (!empty($heroImageUrl))
        <div class="absolute inset-0 {{ $gradientClass }}"></div>
    @else
        {!! \App\Support\Textures\TextureLayer::html($siteTexture ?? null, $section ?? $data ?? null, true, $project ?? $site ?? null) !!}
        <div class="absolute inset-0 bg-gradient-to-br from-black/40 via-black/20 to-black/50"></div>
    @endif
    @php
        $heroSizes = $heroSizeConfig ?? [];
        $isHomePage = ($pageType ?? '') === 'home' || ($pageType ?? '') === '';
        $homeSize = $heroSizes['home'] ?? '55vh';
        $innerSize = $heroSizes['inner'] ?? '35vh';
        $heroH = $isHomePage ? $homeSize : $innerSize;

        // For inner pages without a custom crop, default vertical center
        // so the narrow strip captures the action.
        if (! $isHomePage && $customCropY === null) {
            $bgPosition = $bgPositionX.' center';
        }
    @endphp
    <div class="relative z-[2] max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 {{ $isHomePage ? $paddingClass : 'py-8 md:py-12' }} flex flex-col {{ $isHomePage ? $verticalClass : 'justify-center' }} overflow-hidden"
         style="height: {{ $heroH }}; min-height: {{ $isHomePage ? '280px' : '180px' }}">
        <div class="max-w-3xl {{ $horizontalClass }}{{ $heroCopyBoxClass }}"
             @if ($usesLeftPanel)
                 data-hero-variant="{{ $isPanelLeft ? 'panel-left' : 'boxed-left' }}"
                 style="{{ $heroCopySurfaceStyle }}"
             @endif>
            @if ($isHomePage)
                {{-- FULL HERO: eyebrow + large heading + subheading + dual CTAs --}}
                @if (!empty($profile['geo']['service_area']))
                    <p class="hidden sm:flex text-sm font-semibold tracking-widest uppercase mb-4 items-center gap-2 {{ $textColorClass }} {{ $textShadow }} {{ $flexJustifyClass }}">
                        <span class="w-8 h-px inline-block bg-current"></span>
                        Trusted in {{ $profile['geo']['service_area'] }}
                    </p>
                @endif
                <h1 class="text-3xl md:text-5xl lg:text-6xl font-extrabold {{ $textColorClass }} {{ $textShadow }} mb-6 leading-tight text-pretty">
                    {!! $headingHtml !!}
                </h1>
                <p class="text-lg md:text-xl {{ $subTextClass }} {{ $textShadow }} mb-10 max-w-2xl leading-relaxed {{ $textCol === 'right' ? 'ml-auto' : ($textCol === 'center' ? 'mx-auto' : '') }}">
                    {{ $data['subheading'] ?? '' }}
                </p>
            @else
                {{-- COMPACT BANNER: heading only, smaller text, tighter --}}
                <h1 class="text-3xl md:text-4xl lg:text-5xl font-extrabold {{ $textColorClass }} {{ $textShadow }} mb-3 leading-tight text-pretty">
                    {!! $headingHtml !!}
                </h1>
                @if (!empty($data['subheading']))
                    <p class="text-base md:text-lg {{ $subTextClass }} {{ $textShadow }} max-w-xl leading-relaxed {{ $textCol === 'right' ? 'ml-auto' : ($textCol === 'center' ? 'mx-auto' : '') }}">
                        {{ $data['subheading'] }}
                    </p>
                @endif
            @endif

            @php
                $contactHref = ($layout ?? 'one_page') === 'multi_page'
                    ? ($pageUrl ?? fn ($p) => route('preview.page', [$previewSlug ?? '', $p]))('contact')
                    : '#contact';
            @endphp

            @if ($isHomePage)
            <div class="flex flex-col sm:flex-row gap-4 {{ $flexJustifyClass }}">
                @if (!empty($data['cta_label']))
                    <a href="{{ $contactHref }}"
                       class="inline-flex items-center justify-center gap-2 font-bold px-8 py-4 rounded-md text-white shadow-lg transition-all hover:shadow-xl hover:brightness-110 text-lg"
                       style="background-color: var(--brand-accent);">
                        {{ $data['cta_label'] }}
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                    </a>
                @endif
                @if ($phone = ($profile['contact']['phones'][0] ?? null))
                    <a href="tel:{{ $phone }}"
                       class="inline-flex items-center justify-center gap-2 font-semibold px-8 py-4 rounded-md {{ $textColorClass }} border-2 {{ $textColor === 'dark' ? 'border-gray-400 hover:border-gray-600' : 'border-white/30 hover:border-white/60' }} transition-all text-lg">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                        {{ $phone }}
                    </a>
                @endif
            </div>
            @endif
        </div>
    </div>
    {{-- Bottom accent bar --}}
    <div class="h-1.5" style="background-color: var(--brand-accent);"></div>
</div>
