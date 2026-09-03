{{-- Precision values markers: stacked header, then either a 5-col grid
     (page hero 2 LEFT, + marker rows 3 RIGHT, one column) or, with no
     image, full-width md:columns-2 density matching features/markers.
     No clamp, no ordinals. Same fields as classic. --}}
{{-- Top padding tighter than section spacing: sits on the precision
     story/document same-background bottom edge (see variants/story/document).
     Combined seam ≈ one section-spacing, not two. --}}
@php
    // Dispatcher already resolves the ladder (band ?? hero) and unwraps
    // the watermark shape — no per-variant re-unwrap drift.
    $valuesImg = $bandImg ?? null;
@endphp
<div data-svc-variant="markers" class="pt-10 lg:pt-12" style="background-color: var(--color-surface); padding-bottom: var(--section-spacing);">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        <div class="max-w-3xl mb-6">
            @if (empty($section['__suppress_eyebrow']) && !empty($section['title']))
                <span class="text-sm font-bold tracking-widest uppercase mb-3 block" style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
            @elseif ($emitMarkers)
                <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
            @endif
            @if (!empty($section['title']))
                <h2 class="text-3xl md:text-4xl font-extrabold text-pretty" style="color: var(--color-text);"
                    {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
            @elseif ($emitMarkers)
                <span class="hidden"{!! $editor('title', 'plain') !!}></span>
            @endif
        </div>
        @if (! empty($items))
            @if (! empty($valuesImg))
                @php
                    // Renderer stamps the OPPOSITE of the recipe's primary
                    // image_alignment here, preserving alternation.
                    $primary = $section['__options']['image_alignment'] ?? 'right'; // precision story/document defaults image-right
                    $secondary = $section['__options']['image_alignment_secondary'] ?? ($primary === 'left' ? 'right' : 'left');
                    $imgLeft = $secondary === 'left';
                    $imgRadius = ($section['__options']['image_radius'] ?? 'sharp') === 'soft' ? 'var(--radius-card)' : '0';
                    $divider = 'border-color: color-mix(in oklab, var(--color-text-muted) 30%, transparent);';
                @endphp
                <div class="mt-10 grid grid-cols-1 lg:grid-cols-5 gap-10 lg:gap-14 items-start">
                    <figure @class(['lg:col-span-2', 'lg:order-last' => ! $imgLeft]) style="border: 1px solid color-mix(in oklab, var(--color-text) 18%, transparent); border-radius: {{ $imgRadius }};">
                        <div class="overflow-hidden" style="aspect-ratio: 4 / 3; border-radius: {{ $imgRadius }};">
                            <img src="{{ $valuesImg }}" alt="{{ $section['title'] ?? 'Our values' }}"
                                 class="w-full h-full object-cover" loading="lazy">
                        </div>
                    </figure>
                    <div @class(['lg:col-span-3', 'lg:border-l lg:pl-14' => $imgLeft, 'lg:order-first lg:border-r lg:pr-14' => ! $imgLeft]) style="{{ $divider }}">
                        @foreach ($items as $i => $item)
                            <div class="flex gap-3 py-5" style="border-bottom: 1px solid color-mix(in oklab, var(--color-text) 18%, transparent);">
                                <span class="text-lg font-bold leading-6" style="color: var(--brand-accent-text);">+</span>
                                <div class="min-w-0 flex-1">
                                    <h3 class="text-base md:text-lg font-bold" style="color: var(--color-text);"
                                        {!! $editor("items.{$i}.title", 'plain') !!}>{{ $item['title'] ?? '' }}</h3>
                                    <p class="mt-1 text-sm md:text-base leading-relaxed" style="color: var(--color-text-muted);"
                                       {!! $editor("items.{$i}.body", 'plain') !!}>{{ $item['body'] ?? '' }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
                <div class="md:columns-2 md:gap-x-16 mt-10">
                    @foreach ($items as $i => $item)
                        <div class="break-inside-avoid flex gap-3 py-5" style="border-bottom: 1px solid color-mix(in oklab, var(--color-text) 18%, transparent);">
                            <span class="text-lg font-bold leading-6" style="color: var(--brand-accent-text);">+</span>
                            <div class="min-w-0 flex-1">
                                <h3 class="text-base md:text-lg font-bold" style="color: var(--color-text);"
                                    {!! $editor("items.{$i}.title", 'plain') !!}>{{ $item['title'] ?? '' }}</h3>
                                <p class="mt-1 text-sm md:text-base leading-relaxed" style="color: var(--color-text-muted);"
                                   {!! $editor("items.{$i}.body", 'plain') !!}>{{ $item['body'] ?? '' }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        @endif
    </div>
</div>
