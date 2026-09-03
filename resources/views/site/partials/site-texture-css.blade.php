{{-- Emits --site-texture-* vars and the .site-texture / .hero-pattern
     layer rules. Omitted entirely when the resolved texture is none so
     pages stay free of texture CSS, not an invisible layer. --}}
@if (isset($siteTexture) && ! $siteTexture->isNone())
            --site-texture-image: {!! $siteTexture->cssImage() !!};
            --site-texture-opacity: {{ $siteTexture->opacity }};
            --site-texture-size: {{ $siteTexture->sizeCss() }};
            --site-texture-color: #ffffff;
            --site-texture-repeat: {{ $siteTexture->repeatCss() }};
@endif
