@if (isset($siteTexture) && ! $siteTexture->isNone())
        .site-texture,
        .hero-pattern {
            pointer-events: none;
            background-color: var(--site-texture-color, #ffffff);
            opacity: var(--site-texture-opacity, 0.05);
            -webkit-mask-image: var(--site-texture-image);
            mask-image: var(--site-texture-image);
            -webkit-mask-repeat: var(--site-texture-repeat, repeat);
            mask-repeat: var(--site-texture-repeat, repeat);
            -webkit-mask-position: center;
            mask-position: center;
            -webkit-mask-size: var(--site-texture-size, 60px);
            mask-size: var(--site-texture-size, 60px);
        }
        .site-texture--image,
        .hero-pattern.site-texture--image {
            background-color: transparent;
            background-image: var(--site-texture-image);
            background-repeat: var(--site-texture-repeat, repeat);
            background-size: var(--site-texture-size, auto);
            background-position: center;
            -webkit-mask-image: none;
            mask-image: none;
        }
@endif
