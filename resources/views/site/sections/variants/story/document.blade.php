{{-- Precision document story: full-width accent-rule chrome (same 2px
     --brand-accent top rule as intro/spec). Heading is left-aligned
     max-w-4xl. Body is a 5-col grid — prose 3, story image 2 on the
     RIGHT — collapsing to stacked (image first) below lg. No image →
     no grid; prose keeps its measure, left-aligned. --}}
{{-- Bottom padding is deliberately tighter than the top: the markers
     values section that follows in the precision preset shares this
     background, so full section spacing on both sides read as a void.
     Combined seam ≈ one section-spacing, not two. --}}
<div data-svc-variant="document" class="pt-16 lg:pt-20 pb-8 lg:pb-10" style="background-color: var(--color-surface);">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        <div class="flex items-baseline justify-between gap-6 pt-4 mb-10" style="border-top: 2px solid var(--brand-accent);">
            @if (empty($section['__suppress_eyebrow']))
                <span class="text-xs font-bold tracking-[0.18em] uppercase" style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
            @elseif ($emitMarkers)
                <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
            @endif
        </div>

        @if (!empty($section['title']))
            <h2 class="text-3xl md:text-4xl font-extrabold leading-tight max-w-4xl text-pretty" style="color: var(--color-text);"
                {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
        @elseif ($emitMarkers)
            <span class="hidden"{!! $editor('title', 'plain') !!}></span>
        @endif

        @php
            $imgLeft = ($section['__options']['image_alignment'] ?? 'right') === 'left';
            $imgRadius = ($section['__options']['image_radius'] ?? 'sharp') === 'soft' ? 'var(--radius-card)' : '0';
            // Vertical hairline between blurb and image — the same divider
            // the service spec intro runs between its columns.
            $divider = 'border-color: color-mix(in oklab, var(--color-text-muted) 30%, transparent);';
        @endphp
        @if (!empty($introImg))
            <div class="mt-10 grid grid-cols-1 lg:grid-cols-5 gap-10 lg:gap-14 items-start">
                <div @class(['lg:col-span-3', 'lg:order-last lg:border-l lg:pl-14' => $imgLeft, 'lg:border-r lg:pr-14' => ! $imgLeft]) style="{{ $divider }}">
                    @if (!empty($section['body']))
                        <div class="space-y-5 text-base md:text-lg leading-relaxed prose max-w-none [&>p]:mb-0"
                             style="color: var(--color-text-muted);"
                             {!! $editor('body', 'rich', is_array($section['body']) ? $section['body'] : null) !!}>{!! $richHtml($section['body']) !!}</div>
                    @elseif ($emitMarkers)
                        <span class="hidden"{!! $editor('body', 'rich') !!}></span>
                    @endif
                </div>
                <figure @class(['order-first', 'lg:order-none' => $imgLeft, 'lg:order-last' => ! $imgLeft, 'lg:col-span-2']) style="border: 1px solid color-mix(in oklab, var(--color-text) 18%, transparent); border-radius: {{ $imgRadius }};">
                    <div class="overflow-hidden" style="aspect-ratio: 4 / 3; border-radius: {{ $imgRadius }};">
                        <img src="{{ $introImg }}" alt="{{ $section['title'] ?? 'About us' }}"
                             class="w-full h-full object-cover" loading="lazy">
                    </div>
                </figure>
            </div>
        @else
            @if (!empty($section['body']))
                <div class="mt-10 max-w-3xl space-y-5 text-base md:text-lg leading-relaxed prose [&>p]:mb-0"
                     style="color: var(--color-text-muted);"
                     {!! $editor('body', 'rich', is_array($section['body']) ? $section['body'] : null) !!}>{!! $richHtml($section['body']) !!}</div>
            @elseif ($emitMarkers)
                <span class="hidden"{!! $editor('body', 'rich') !!}></span>
            @endif
        @endif
    </div>
</div>
