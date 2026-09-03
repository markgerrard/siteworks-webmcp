@php
    $navRowImageUrl = $navRowPattern === 'image' ? \App\Support\ChromeKnobs::navRowImageUrl($site) : null;
    $navRowImageOpacity = $navRowImageUrl !== null ? \App\Support\ChromeKnobs::navRowImageOpacity($site) : 0.12;
    $navRowImageFit = $navRowImageUrl !== null ? \App\Support\ChromeKnobs::navRowImageFit($site) : 'cover';
    $navRowImagePosY = $navRowImageUrl !== null ? \App\Support\ChromeKnobs::navRowImagePositionY($site) : 50;
    $navRowImageSizeCss = $navRowImageFit === 'tile'
        ? 'background-repeat: repeat; background-size: auto;'
        : 'background-size: cover;';
@endphp
@if ($navRowPattern === 'image' && $navRowImageUrl !== null)
        <div aria-hidden="true" data-nav-row-image class="absolute inset-0 -z-10 pointer-events-none" style="background-image: url('{{ $navRowImageUrl }}'); {{ $navRowImageSizeCss }} background-position: center {{ $navRowImagePosY }}%; opacity: {{ $navRowImageOpacity }};"></div>
@elseif ($navRowPattern !== 'none')
        <svg aria-hidden="true" class="absolute inset-0 -z-10 w-full h-full pointer-events-none" style="color: var(--color-primary); opacity: 0.07;">
            <defs>
                @if ($navRowPattern === 'swirl')
                <pattern id="nav-row-pattern" width="120" height="120" patternUnits="userSpaceOnUse">
                    <path d="M0 60c20-30 40-30 60 0s40 30 60 0" fill="none" stroke="currentColor" stroke-width="2"/>
                    <path d="M-60 120c20-30 40-30 60 0s40 30 60 0 40-30 60 0" fill="none" stroke="currentColor" stroke-width="2"/>
                    <path d="M-60 0c20-30 40-30 60 0s40 30 60 0 40-30 60 0" fill="none" stroke="currentColor" stroke-width="2"/>
                </pattern>
                @else
                <pattern id="nav-row-pattern" width="24" height="24" patternUnits="userSpaceOnUse">
                    <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
                </pattern>
                @endif
            </defs>
            <rect width="100%" height="100%" fill="url(#nav-row-pattern)"/>
        </svg>
@endif
