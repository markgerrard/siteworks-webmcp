{{-- Showcase story, v3 (design review): the service split's
     panel+image composition, brought to the about page for cross-kind
     consistency — the earlier banner-with-overlap-card stacked a second
     full-bleed photo directly under the photo hero and orphaned the
     prose. Image defaults LEFT, matching the service split exactly (ruling:
     consistency over rhythm-flipping); values takes the opposite side. Variant token name is kept to
     avoid recipe churn.

     Contracts: ONE rich-marked body container holding every paragraph
     (overlay-editor truncation guard); no image -> the review spans full
     width; options: image_alignment (left|right, default right),
     image_radius (sharp|soft, default sharp). --}}
@php
    $imgLeft = ($section['__options']['image_alignment'] ?? 'left') === 'left';
    $imgRadius = (($section['__options']['image_radius'] ?? null) === 'soft') ? 'var(--radius-card)' : '0';
@endphp
<div data-svc-variant="banner-overlap" class="grid grid-cols-1 {{ !empty($introImg) ? 'lg:grid-cols-2' : '' }}" style="background-color: var(--brand-primary);">
    @if (!empty($introImg))
        <div @class(['relative min-h-[320px] lg:min-h-full', 'lg:order-last' => ! $imgLeft])>
            <img src="{{ $introImg }}" alt="{{ $section['title'] ?? 'About us' }}"
                 class="absolute inset-0 w-full h-full object-cover" loading="lazy"
                 style="border-radius: {{ $imgRadius }};">
        </div>
    @endif
    <div class="flex flex-col justify-center px-6 py-16 sm:px-10 lg:px-16 lg:py-24 {{ empty($introImg) ? 'lg:col-span-2' : '' }}">
        @if (empty($section['__suppress_eyebrow']))
            <span class="text-sm font-bold tracking-widest uppercase mb-4 block" style="color: var(--brand-accent);" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
        @elseif ($emitMarkers)
            <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
        @endif
        @if (!empty($section['title']))
            <h2 class="text-3xl md:text-4xl font-extrabold leading-tight max-w-xl text-pretty" style="color: var(--color-text-on-primary, #ffffff);"
                {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
        @elseif ($emitMarkers)
            <span class="hidden"{!! $editor('title', 'plain') !!}></span>
        @endif
        <div class="w-14 h-1 mt-6 mb-7" style="background-color: var(--brand-accent);"></div>
        @if (!empty($section['body']))
            <div class="space-y-4 text-base lg:text-lg leading-relaxed max-w-xl prose prose-invert max-w-none [&>p]:mb-0 [&>p:first-child]:text-lg [&>p:first-child]:lg:text-xl [&>p:first-child]:font-medium"
                 style="color: color-mix(in oklab, var(--color-text-on-primary, #ffffff) 82%, transparent);"
                 {!! $editor('body', 'rich', is_array($section['body']) ? $section['body'] : null) !!}>{!! $richHtml($section['body']) !!}</div>
        @elseif ($emitMarkers)
            <span class="hidden"{!! $editor('body', 'rich') !!}></span>
        @endif
    </div>
</div>
