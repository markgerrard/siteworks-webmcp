{{--
    ═══════════════════════════════════════════════════════════════════════
    THIS TEMPLATE SERVES LIVE PUBLIC TRAFFIC.  Edit SEO/markup here.
    ═══════════════════════════════════════════════════════════════════════

    Two renderers exist. Which one runs is decided by a middleware flag, NOT
    by anything in these files:

        Host: branded preview FQDN / custom domain
          → App\Http\Middleware\ResolvePreviewHost  (prepended, global)
              ├── config('site.use_versioned_renderer') === true   ← CURRENT
              │     → PublicSiteController → PageRenderer → THIS FILE
              │       + resources/views/site/sections/*
              └── false  (the config default)                      ← LEGACY
                    → PreviewController → resources/views/preview/layout.blade.php
                      + resources/views/preview/sections/*

    SITE_USE_VERSIONED_RENDERER=true in every environment, so this file is what the public sees. resources/views/preview/
    is dormant: it still serves the /preview/{slug} routes and is the rollback
    if the flag is ever cleared.

    WHY THIS COMMENT EXISTS: this is the live-traffic template. The preview
    tree (resources/views/preview/layout.blade.php) looks equally
    authoritative but is dormant while its flag is off. If you are adding
    markup, schema, canonicals or meta — it goes HERE.
--}}
@php
    $themeKey = $composition['theme']['key'] ?? $site->theme ?? 'trades-bold';
    $primary = $renderTokens['primary'] ?? ($theme['primary_color'] ?? '#1e40af');
    $primaryText = $renderTokens['primary_text'] ?? $primary;
    $primaryTextOnAlt = $renderTokens['primary_text_on_alt'] ?? $primaryText;
    $accent = $renderTokens['accent'] ?? ($theme['accent_color'] ?? '#f59e0b');
    $accentText = $renderTokens['accent_text'] ?? $accent;
    $accentTextOnAlt = $renderTokens['accent_text_on_alt'] ?? $accentText;
    $tertiary = $renderTokens['tertiary'] ?? ($theme['tertiary_color'] ?? '#d2d9eb');
    $surface = $renderTokens['surface'] ?? ($theme['surface_color'] ?? '#ffffff');
    $surfaceAlt = $renderTokens['surface_alt'] ?? ($theme['surface_alt_color'] ?? '#f5f5f5');
    $border = $renderTokens['border'] ?? ($theme['border_color'] ?? '#e5e5e5');
    $text = $renderTokens['text'] ?? ($theme['text_color'] ?? '#111111');
    $textOnAlt = $renderTokens['text_on_alt'] ?? $text;
    $textMuted = $renderTokens['text_muted'] ?? ($theme['text_muted_color'] ?? '#6b7280');
    $textMutedOnAlt = $renderTokens['text_muted_on_alt'] ?? $textMuted;
    $band = $renderTokens['band'] ?? '#0f172a';
    $textOnBand = $renderTokens['text_on_band'] ?? '#ffffff';
    $bandOverlay = $renderTokens['band_overlay'] ?? $band;
    $textOnPrimary = $renderTokens['text_on_primary'] ?? '#ffffff';
    $textOnAccent = $renderTokens['text_on_accent'] ?? '#ffffff';
    $surfaceContrast = $renderTokens['surface_contrast'] ?? $surfaceAlt;
    $textOnContrast = $renderTokens['text_on_contrast'] ?? $textOnAlt;
    $textMutedOnContrast = $renderTokens['text_muted_on_contrast'] ?? $textMutedOnAlt;
    $accentTextOnContrast = $renderTokens['accent_text_on_contrast'] ?? $accentTextOnAlt;
    $brandSectionScheme = $renderTokens['brand_section_scheme'] ?? 'bold';
    $brandSectionSurface = $renderTokens['brand_section_surface'] ?? $surfaceAlt;
    $brandSectionInk = $renderTokens['brand_section_ink'] ?? $text;
    $brandSectionMutedInk = $renderTokens['brand_section_muted_ink'] ?? $textMuted;
    $brandSectionAccentInk = $renderTokens['brand_section_accent_ink'] ?? $accentTextOnAlt;
    $displayFontStack = $renderTokens['display_font_stack'] ?? '"Inter", system-ui, sans-serif';
    $bodyFontStack = $renderTokens['body_font_stack'] ?? '"Inter", system-ui, sans-serif';
    $radiusCard = $renderTokens['radius_card'] ?? '12px';
    $radiusButton = $renderTokens['radius_button'] ?? '8px';
    $sectionSpacing = $renderTokens['section_spacing'] ?? '6rem';
    $containerWidth = $renderTokens['container_width'] ?? '1200px';
    $shellInsetXl = $renderTokens['shell_inset_xl'] ?? '';
    $shellInsetXlRule = $shellInsetXl !== ''
        ? '@media (min-width: 1280px) { body[data-display-scale="grand"] .site-shell-container { padding-left: '.$shellInsetXl.'; padding-right: '.$shellInsetXl.'; } }'
        : '';
    $headingLetterSpacing = $renderTokens['heading_letter_spacing'] ?? '-0.01em';
    // Lucide icons default to stroke-width 2. Bolder archetypes bump
    // to 2.5; airy/boutique to 1.75. Kept as a float string so it can
    // flow into both CSS var (for inline SVGs) and the lucide.createIcons
    // attrs (for data-lucide icons replaced client-side).
    $iconStrokeWidth = $renderTokens['icon_stroke_width'] ?? '2';
    $fontLinkHref = $renderTokens['font_link_href'] ?? '/fonts/inter+inter.css';
    $themeResolver = app(\App\Services\Site\ThemeResolver::class);
    $siteTexture = $siteTexture ?? \App\Support\Textures\TextureResolver::resolve($site);
    $surfaceDark = [
        'primary' => $themeResolver->isDarkSurface($primary),
        'band' => $themeResolver->isDarkSurface($band),
        'surface' => $themeResolver->isDarkSurface($surface),
    ];
@endphp
@php
    // Generated SEO fields (service_page.md / page_content.md) land
    // on content_data.meta.seo. Fall back to nav_label + business_name when
    // missing (older sites generated before the prompt update, or pages the
    // LLM left blank).
    $seoMeta = $seoMeta ?? [];
    $fallbackTitle = $page->nav_label ? $page->nav_label.' | '.$site->business_name : $site->business_name;
    $rawSeoTitle = $seoMeta['meta_title'] ?? null;
    $seoTitle = is_string($rawSeoTitle) && $rawSeoTitle !== '' ? $rawSeoTitle : $fallbackTitle;
    $rawSeoDescription = $seoMeta['meta_description'] ?? null;
    $seoDescription = is_string($rawSeoDescription) ? $rawSeoDescription : '';
    $ogPageType = $page->page_type ?? 'home';
    $ogPath = ($ogPageType === '' || $ogPageType === 'home') ? '/' : '/'.$ogPageType;
    $ogUrl = request()->attributes->get('resolved_site') ? request()->url() : url($ogPath);
    $heroImages = $heroImages ?? [];
    $heroOgData = is_array($heroImages[$ogPageType] ?? null) ? $heroImages[$ogPageType] : [];
    $heroOg = $heroOgData['url'] ?? null;
    // The brand share card (logo on the palette) is the share image whenever
    // the site has one; the page hero is the fallback for sites without a card.
    $ogCard = $site->ogImageUrl();
    $ogImage = (is_string($ogCard) && $ogCard !== '') ? $ogCard : $heroOg;
    $ogImageIsCard = is_string($ogImage) && $ogImage !== '' && $ogImage === $ogCard;
    // Scrapers reject root-relative share URLs: absolutise against the request host.
    $ogAbsolute = static fn ($u) => (is_string($u) && str_starts_with($u, '/') && ! str_starts_with($u, '//')) ? url($u) : $u;
    $ogImage = $ogAbsolute($ogImage);
    $ogCardDimensions = $ogImageIsCard ? $site->ogImageCardDimensions() : ['width' => null, 'height' => null];
    $ogImageWidth = $ogImageIsCard ? $ogCardDimensions['width'] : (is_numeric($heroOgData['width'] ?? null) ? (int) $heroOgData['width'] : null);
    $ogImageHeight = $ogImageIsCard ? $ogCardDimensions['height'] : (is_numeric($heroOgData['height'] ?? null) ? (int) $heroOgData['height'] : null);
    $trustJsonLd = null;
    if (($page->page_type ?? null) === 'home') {
        foreach ($sections as $candidate) {
            if (! is_array($candidate) || ($candidate['type'] ?? null) !== 'trust_strip') {
                continue;
            }

            $trustSources = in_array($candidate['sources'] ?? null, ['site', 'product', 'both'], true)
                ? $candidate['sources']
                : 'both';
            $trustMinimum = max(1, (int) ($candidate['min_reviews'] ?? 3));
            $trustSummary = app(\App\Services\Site\TrustSummary::class)->for($site, $trustSources);
            if ($trustSummary['count'] >= $trustMinimum) {
                $trustJsonLd = \App\Support\Site\SiteJsonLd::localBusiness(
                    $site,
                    $ogUrl,
                    $seoDescription,
                    $trustSummary,
                );
            }

            break;
        }
    }
@endphp
<!DOCTYPE html>
<html lang="en-GB" data-theme="{{ $themeKey }}">
<head>
    <meta charset="utf-8">
    <title>{{ $seoTitle }}</title>
    @if ($seoDescription !== '')
        <meta name="description" content="{{ $seoDescription }}">
    @endif
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="{{ $fontLinkHref }}" rel="stylesheet" />
    @php
        // Surface-aware compiled Tailwind (replaces the Play CDN script —
        // ~300KB runtime JIT with a production console warning). Each
        // surface container builds its own bundle dir; 'all' single-container
        // deploys use the default build/.
        $surfaceKey = config('surfaces.current', 'all');
        $siteBuildDir = $surfaceKey === 'all' ? 'build' : 'build-'.$surfaceKey;
    @endphp
    @vite('resources/css/site.css', $siteBuildDir)
    <script defer src="/vendor/alpine.min.js"></script>
    <script defer src="/vendor/lucide.min.js"></script>
    {{-- Flatpickr — date pickers on lead_form.extra_fields of type=date.
         Auto-initialises any input with data-flatpickr on page load and
         also after Livewire/Alpine DOM mutations (x-data forms).

         SRI hashes pin the exact bytes for the immutable jsDelivr
         @4.6.13 response. If the CDN is ever compromised or the pinned
         resource changes, the browser refuses to apply it rather than
         executing tampered code. crossorigin=anonymous is required for
         SRI on cross-origin assets. --}}
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css"
          integrity="sha384-RkASv+6KfBMW9eknReJIJ6b3UnjKOKC5bOUaNgIY778NFbQ8MtWq9Lr/khUgqtTt"
          crossorigin="anonymous">
    <script defer
            src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"
            integrity="sha384-5JqMv4L/Xa0hfvtF06qboNdhvuYXUku9ZrhZh3bSk8VXF0A/RuSLHpLsSV9Zqhl6"
            crossorigin="anonymous"></script>
    <style>
        :root {
            --color-primary: {{ $primary }};
            /* Text-safe variant of primary, derived to pass WCAG AA 4.5:1
               against surface. Equal to --color-primary when the brand
               colour already passes; shifted darker/lighter when it
               doesn't. Used for logo text, rich-text links, and any
               other place where primary is rendered AS text rather than
               as a fill/background. */
            --color-primary-text: {{ $primaryText }};
            /* Variant used when primary is rendered as text on surface-alt
               bands (a design brief may pick a dark surface-alt — the
               normal primary-text colour would fail contrast against it). */
            --color-primary-text-on-alt: {{ $primaryTextOnAlt }};
            --color-accent: {{ $accent }};
            --color-accent-text: {{ $accentText }};
            --color-accent-text-on-alt: {{ $accentTextOnAlt }};
            --color-tertiary: {{ $tertiary }};
            --color-surface: {{ $surface }};
            --color-surface-alt: {{ $surfaceAlt }};
            --color-border: {{ $border }};
            --color-text: {{ $text }};
            /* When surface-alt is dark, the default text colour (picked for
               light surface) reads as dark-on-dark. text-on-alt is the
               WCAG-derived flip that consumers use inside surface-alt
               sections. */
            --color-text-on-alt: {{ $textOnAlt }};
            --color-text-muted: {{ $textMuted }};
            --color-text-muted-on-alt: {{ $textMutedOnAlt }};
            /* "Spotlight band" colour — a theme-aware high-contrast dark
               used behind dramatic sections like the lead form. Always
               dark enough that white text + white card float cleanly;
               brand-tinted on light-surface themes. */
            --color-band: {{ $band }};
            --color-text-on-band: {{ $textOnBand }};
            --color-band-overlay: {{ $bandOverlay }};
            /* WCAG-derived text colour for solid-primary surfaces (cta
               band etc). Avoids the bright-cyan-+-white-text contrast
               fail. */
            --color-text-on-primary: {{ $textOnPrimary }};
            /* WCAG-derived text colour for solid-accent surfaces —
               primarily CTA buttons which use brand-accent as their
               fill. Same derivation as text-on-primary. */
            --color-text-on-accent: {{ $textOnAccent }};
            /* Elevated band used only when a home recipe stamps
               __surface=contrast. Inert on classic: no wrapper reads it. */
            --color-surface-contrast: {{ $surfaceContrast }};
            --color-text-on-contrast: {{ $textOnContrast }};
            --color-text-muted-on-contrast: {{ $textMutedOnContrast }};
            --color-accent-text-on-contrast: {{ $accentTextOnContrast }};@if ($brandSectionScheme === 'soft')
            --color-brand-section-surface: {{ $brandSectionSurface }};
            --color-brand-section-ink: {{ $brandSectionInk }};
            --color-brand-section-muted-ink: {{ $brandSectionMutedInk }};
            --color-brand-section-accent-ink: {{ $brandSectionAccentInk }};@endif

            --font-display: {!! $displayFontStack !!};
            --font-body: {!! $bodyFontStack !!};

            --radius-card: {{ $radiusCard }};
            --radius-button: {{ $radiusButton }};
            --section-spacing: {{ $sectionSpacing }};
            --container-width: {{ $containerWidth }};
            --heading-letter-spacing: {{ $headingLetterSpacing }};
@include('site.partials.site-texture-css')
        }
        :root {
            --brand-primary: var(--color-primary);
            --brand-primary-text: var(--color-primary-text);
            --brand-primary-text-on-alt: var(--color-primary-text-on-alt);
            --brand-accent: var(--color-accent);
            --brand-accent-text: var(--color-accent-text);
            --brand-accent-text-on-alt: var(--color-accent-text-on-alt);
            --brand-accent-text-on-contrast: var(--color-accent-text-on-contrast);
        }
        [x-cloak] { display: none !important; }
        body {
            font-family: var(--font-body);
            background-color: var(--color-surface);
            color: var(--color-text);
        }
        h1, h2, h3, h4, h5, h6 {
            font-family: var(--font-display);
            letter-spacing: var(--heading-letter-spacing);
        }
        .site-shell-container {
            width: 100%;
            max-width: var(--container-width);
            margin-left: auto;
            margin-right: auto;
        }
{!! $shellInsetXlRule !!}        .site-section-spacing {
            padding-top: var(--section-spacing);
            padding-bottom: var(--section-spacing);
        }
        /* Rich-text fallback spacing. Tailwind CDN preflight zeroes p/ul/ol margins
           and the Typography plugin (which our .prose classes rely on) isn't loaded
           in CDN mode. Give paragraphs, lists, and blockquotes visible rhythm so
           multi-paragraph content doesn't collapse into a single block. Covers:
           - rendered rich markers in admin-edit mode ([data-editable-type="rich"])
           - TipTap active editor host (.ProseMirror) so edit-in-progress matches
           - .prose wrappers in non-edit mode (fallback when Typography isn't loaded) */
        [data-editable-type="rich"] > p + p, .ProseMirror > p + p, .prose > p + p,
        [data-editable-type="rich"] > ul,   .ProseMirror > ul,   .prose > ul,
        [data-editable-type="rich"] > ol,   .ProseMirror > ol,   .prose > ol,
        [data-editable-type="rich"] > blockquote, .ProseMirror > blockquote, .prose > blockquote,
        [data-editable-type="rich"] > h2,   .ProseMirror > h2,   .prose > h2,
        [data-editable-type="rich"] > h3,   .ProseMirror > h3,   .prose > h3 { margin-top: 1em; }
        [data-editable-type="rich"] ul, .ProseMirror ul, .prose ul { list-style: disc; padding-left: 1.5rem; }
        [data-editable-type="rich"] ol, .ProseMirror ol, .prose ol { list-style: decimal; padding-left: 1.5rem; }
        [data-editable-type="rich"] blockquote, .ProseMirror blockquote, .prose blockquote { border-left: 3px solid var(--brand-accent); padding-left: 1rem; color: var(--color-text-muted); font-style: italic; }
        [data-editable-type="rich"] a, .ProseMirror a, .prose a { color: var(--brand-primary-text); text-decoration: underline; }
        /* The host element around ProseMirror carries the bordered editing frame;
           suppress ProseMirror's own focus outline so we don't get a double border. */
        .ProseMirror { outline: none !important; }
        .ProseMirror:focus, .ProseMirror-focused { outline: none !important; }
@include('site.partials.site-texture-rules')
    </style>
    @if ($site->faviconUrl())
        {{-- No explicit type — browsers infer from the URL / data: mime, and
             hardcoding image/png broke sites whose favicon ended up as an
             inline SVG data URI (brand-gen fallback path). --}}
        <link rel="icon" href="{{ $site->faviconUrl() }}">
    @endif
    <meta property="og:title" content="{{ $seoTitle }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $site->business_name }}">
    <meta property="og:url" content="{{ $ogUrl }}">
    @if ($seoDescription !== '')
        <meta property="og:description" content="{{ $seoDescription }}">
        <meta name="twitter:description" content="{{ $seoDescription }}">
    @endif
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $seoTitle }}">
    @if ($ogImage)
        <meta property="og:image" content="{{ $ogImage }}">
        <meta name="twitter:image" content="{{ $ogImage }}">
        @if ($ogImageWidth)
            <meta property="og:image:width" content="{{ $ogImageWidth }}">
        @endif
        @if ($ogImageHeight)
            <meta property="og:image:height" content="{{ $ogImageHeight }}">
        @endif
        {{-- Second og:image is the 1:1 card for platforms that crop landscape previews (WhatsApp, some LinkedIn/iMessage clients). --}}
        @if ($ogImageIsCard && ! $site->ogImageIsCustom() && $site->ogImageSquareUrl())
            <meta property="og:image" content="{{ $ogAbsolute($site->ogImageSquareUrl()) }}">
            <meta property="og:image:width" content="1200">
            <meta property="og:image:height" content="1200">
        @endif
    @endif
@if ($trustJsonLd !== null)<script type="application/ld+json">{!! \App\Support\Site\SiteJsonLd::encode($trustJsonLd) !!}</script>@endif
</head>
<body class="antialiased" style="{{ config('site.layout_body_style') }}"{!! $shellInsetXl !== '' ? ' data-display-scale="grand"' : '' !!}>
    @php
        // $homeHref is now resolved by PageRenderer::resolveHomeHref based on
        // composition.homepage_page_id + mode — always points at the homepage,
        // never leaks into 'first nav item' which may be About when services
        // are auto-grouped.
        $homeHref = $homeHref ?? '/';
        $topBarEnabled = $profile['top_bar_enabled'] ?? true;
        $pageType = $page->page_type ?? 'home';
        // Match the legacy preview/layout.blade behaviour: when both `details`
        // and `contact_form` sections exist on the same page, details absorbs
        // the form as a 3-col layout (form 2/3 left, details+map stacked 1/3
        // right). Skip the standalone contact_form section in that case.
        $contactFormEnabled = (bool) ($profile['contact_form_enabled'] ?? true);
        $contactFormSection = $contactFormEnabled
            ? collect($sections)->firstWhere('type', 'contact_form')
            : null;
        $detailsSection = collect($sections)->firstWhere('type', 'details');
        $absorbContactForm = $contactFormSection && $detailsSection;
        $leadFormPolicy = $site->businessProfile?->leadFormPolicy();
        $leadFormAllowedHere = match (true) {
            ($page->kind ?? null) === \App\Enums\PageKind::ProjectDetail => false,
            ! $leadFormPolicy => (bool) ($profile['home_lead_form_enabled'] ?? false),
            $pageType === 'home' => $leadFormPolicy->includesHome(),
            in_array($pageType, ['about', 'contact'], true) => false,
            default => $leadFormPolicy->includesServices(),
        };
        $enquireAnchorIndex = null;
        $homeHeroFormIndex = null;
        $gatedFormIndex = null;
        foreach ($sections as $i => $candidate) {
            $candidateType = $candidate['type'] ?? null;
            if ($candidateType === 'lead_form' && $leadFormAllowedHere) {
                $gatedFormIndex = $i;
                break;
            }
            if ($candidateType === 'contact_form' && $contactFormEnabled && ! $absorbContactForm) {
                $gatedFormIndex = $i;
                break;
            }
            if ($candidateType === 'details' && $absorbContactForm) {
                $gatedFormIndex = $i;
                break;
            }
        }
        if (\App\Support\ChromeKnobs::navCtaTarget($site) === 'form') {
            $enquireAnchorIndex = $gatedFormIndex;
        }
        if (($page?->page_type ?? null) === 'home') {
            $homeHeroFormIndex = $gatedFormIndex;
        }
        $enquireScrollMargin = ($enquireAnchorIndex ?? $homeHeroFormIndex) !== null
            ? 'calc('.\App\Services\Site\HeaderPresentation::scrolledHeaderHeight($site, $renderTokens).' + 0.5rem)'
            : null;
        $headerOverlayCapable = \App\Support\ChromeKnobs::headerMode($site) === 'overlay'
            && app(\App\Services\Site\HeaderPresentation::class)->overlayCapable(
                $site,
                $page,
                $sections,
                $heroImages ?? [],
                $leadFormAllowedHere,
                $contactFormEnabled && ! $absorbContactForm,
            );
        $effectiveOverlay = $headerOverlayCapable;
        $overlayHeaderH = $effectiveOverlay
            ? \App\Services\Site\HeaderPresentation::overlayHeaderHeight($site, $site->overlay_logo_size !== null && (($page?->page_type ?? null) === 'home' || \App\Support\ChromeKnobs::overlayInnerScale($site) === 'overlay'), $renderTokens)
            : null;
        $overlayHeaderHMobile = $effectiveOverlay
            ? \App\Services\Site\HeaderPresentation::overlayHeaderHeightMobile($site, $site->overlay_logo_size !== null && (($page?->page_type ?? null) === 'home' || \App\Support\ChromeKnobs::overlayInnerScale($site) === 'overlay'), $renderTokens)
            : null;
    @endphp@if ($overlayHeaderH)<div class="overlay-header-scope" style="flex: 1 0 auto; --overlay-header-h: {{ $overlayHeaderHMobile }}"><style>@media (min-width: 768px) { .overlay-header-scope { --overlay-header-h: {{ $overlayHeaderH }}; } } html { overflow-anchor: none; } .overlay-hero-copy { padding-top: max(7rem, calc(var(--overlay-header-h, 0px) + 0.5rem)) !important; height: auto !important; justify-content: safe center !important; } @media (min-width: 768px) { .overlay-hero-copy { padding-top: max(9rem, calc(var(--overlay-header-h, 0px) + 0.5rem)) !important; } } @media (min-width: 1024px) { .overlay-hero-copy { padding-top: max(10rem, calc(var(--overlay-header-h, 0px) + 0.5rem)) !important; } } .overlay-hero-copy-inner { padding-top: calc(var(--overlay-header-h, 0px) + 1.5rem) !important; height: auto !important; justify-content: safe center !important; }</style>@endif

{!! $enquireScrollMargin !== null ? '<style>#enquire{scroll-margin-top:'.$enquireScrollMargin.'}</style>' : '' !!}@include('site.partials.announcement-strip')    @include('site.partials.nav', [
        'navItems'   => $navItems ?? [],
        'site'       => $site,
        'homeHref'   => $homeHref,
        'profile'    => $profile,
        'theme'      => $theme,
        'logoUrl'    => $logoUrl ?? null,
        'logoTransparent' => $logoTransparent ?? false,
        'logoPlate' => $logoPlate ?? true,
        'overlayLogoUrl' => $overlayLogoUrl ?? null,
        'topBarEnabled' => $topBarEnabled,
        'headerOverlayCapable' => $headerOverlayCapable,
        'effectiveOverlay' => $effectiveOverlay,
        'navRowHeroBelow' => in_array($sections[0]['type'] ?? null, ['hero', 'projects_hero', 'project_detail_hero'], true),
        'isHomePage' => ($page?->page_type ?? null) === 'home',
        // The gated decision (lead-form policy, contact_form_enabled, absorption) — not the raw type check.
        'pageHasForm' => $enquireAnchorIndex !== null,
    ])

    @php
        $contactFormSectionIndex = null;
        if ($absorbContactForm) {
            foreach ($sections as $candidate) {
                if (($candidate['type'] ?? null) === 'contact_form') {
                    $contactFormSectionIndex = $candidate['__stored_index'] ?? null;
                    break;
                }
            }
        }
        // Spec §D: last-EMITTED adjacency is inert unless a recipe opts in.
        // stampSection already copies recipe.options onto __options; scanning
        // that stamp keeps this loop from needing a new renderer view contract.
        $emitPreviousSurfaces = false;
        foreach ($sections as $candidate) {
            if (is_array($candidate) && ($candidate['__options']['previous_surfaces'] ?? false) === true) {
                $emitPreviousSurfaces = true;
                break;
            }
        }
        $lastEmittedSurface = null;
    @endphp
    <main style="flex: 1 0 auto;">
        @foreach ($sections as $idx => $section)
            @php
                $type = $section['type'] ?? 'unknown';
                $sectionPageType = $section['__page_type'] ?? $pageType;
                $storedIdx = $section['__stored_index'] ?? null;
                unset($section['__previous_surface']);
                $previousSurfaceClass = '';
                if ($emitPreviousSurfaces && is_string($lastEmittedSurface) && $lastEmittedSurface !== '') {
                    $section['__previous_surface'] = $lastEmittedSurface;
                    $previousSurfaceClass = 'previous-'.$lastEmittedSurface;
                }
                $emitSectionIndexMarker = $mode === 'admin-edit' && $storedIdx !== null;
                $sectionStyleOverrides = $themeResolver->inlineTokenOverrides($section['style_overrides'] ?? null);
                $sectionWrapOpen = '';
                if ($previousSurfaceClass !== '' || $emitSectionIndexMarker || $sectionStyleOverrides !== '') {
                    $sectionWrapOpen = '<div';
                    if ($previousSurfaceClass !== '') {
                        $sectionWrapOpen .= ' class="'.e($previousSurfaceClass).'"';
                    }
                    if ($emitSectionIndexMarker) {
                        $sectionWrapOpen .= ' data-section-index="'.e((string) $storedIdx).'" data-section-id="'.e((string) ($section['id'] ?? '')).'"';
                    }
                    if ($sectionStyleOverrides !== '') {
                        $sectionWrapOpen .= ' style="'.e($sectionStyleOverrides).'"';
                    }
                    $sectionWrapOpen .= '>';
                }
            @endphp
            @if ($type === 'contact_form' && (! $contactFormEnabled || $absorbContactForm))
                @continue
            @endif
            @if ($type === 'lead_form' && ! $leadFormAllowedHere)
                @continue
            @endif
            @if ($type === '__anchor')
                {{-- Anchor target for single-page nav. scroll-margin-top offsets
                     the sticky header so the anchor's page-group lands below it
                     instead of hiding under the nav bar. --}}
                <div id="{{ $section['slug'] ?? '' }}" style="scroll-margin-top: 8rem;"></div>
            @elseif (view()->exists("site.sections.{$type}"))
                {!! $sectionWrapOpen !!}@include("site.sections.{$type}", [
                    'section'         => $section,
                    'sectionIndex'    => $storedIdx,
                    'pageId'          => $page->id,
                    'mode'            => $mode,
                    'useDraftAssets'  => $useDraftAssets,
                    'emitMarkers'     => $emitMarkers && $storedIdx !== null,
                    'emitFormMarkers' => $emitFormMarkers && $storedIdx !== null,
                    'schema'          => $schema,
                    'theme'           => $theme,
                    'profile'         => $profile,
                    'site'            => $site,
                    'heroImageUrl'    => $heroImages[$sectionPageType] ?? null,
                    'heroState'       => $heroStates[$sectionPageType] ?? null,
                    'homeHeroImageUrl' => $heroImages['home'] ?? null,
                    'introImageUrl'   => $introImages[$sectionPageType] ?? null,
                    'bandImageUrl'    => $bandImages[$sectionPageType] ?? null,
                    'bandImage2Url'   => $bandImages2[$sectionPageType] ?? null,
                    'bandImage3Url'   => $bandImages3[$sectionPageType] ?? null,
                    'pageType'        => $sectionPageType,
                    'pagesBySlug'     => $pagesBySlug ?? [],
                    'itemsById'       => $itemsById ?? collect(),
                    'pairsById'       => $pairsById ?? collect(),
                    'serviceGalleryItems' => $serviceGalleryItems ?? collect(),
                    'mediaById'       => $mediaById ?? collect(),
                    'projectVocab'    => $projectVocab ?? null,
                    'page'            => $page,
                    'pinnedPages'     => $pinnedPages ?? collect(),
                    'contactFormSection' => ($type === 'details' && $absorbContactForm) ? $contactFormSection : null,
                    'contactFormSectionIndex' => ($type === 'details' && $absorbContactForm) ? $contactFormSectionIndex : null,
                    'effectiveOverlay' => in_array($type, ['hero', 'projects_hero', 'project_detail_hero'], true) && $effectiveOverlay,
                    'enquireAnchor' => ($enquireAnchorIndex ?? $homeHeroFormIndex) === $idx,
                    'heroFormTarget' => $homeHeroFormIndex !== null,
                    'surfaceDark' => $surfaceDark,
                ])@if($sectionWrapOpen !== '')</div>@endif@php if ($emitPreviousSurfaces) { $emittedSurface = $section['__surface'] ?? null; $lastEmittedSurface = (is_string($emittedSurface) && preg_match('/^[a-z0-9-]+$/', $emittedSurface) === 1) ? $emittedSurface : 'default'; } @endphp
            @endif
        @endforeach
    </main>@if ($overlayHeaderH)</div>@endif


    @include('site.partials.footer', [
        'composition' => $composition,
        'site'        => $site,
        'profile'     => $profile,
        'theme'       => $theme,
        'navItems'    => $navItems ?? [],
        'pinnedPages' => $pinnedPages ?? collect(),
    ])

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof lucide !== 'undefined') {
                // Pass the archetype-derived stroke-width to every Lucide icon
                // replacement. Emergency-trade + premium archetypes render
                // thicker strokes to match heavy display typography; airy
                // archetypes get thinner strokes. Default 2 matches Lucide's
                // own default. Deferred lucide.min.js runs before
                // DOMContentLoaded, so the global is present here.
                lucide.createIcons({ attrs: { 'stroke-width': '{{ $iconStrokeWidth }}' } });
            }
        });
    </script>
</body>
</html>
