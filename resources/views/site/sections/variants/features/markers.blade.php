{{-- Seam note: spec intro above shares this background; top padding is
     deliberately lighter than section spacing (same rule as numbered). --}}
{{-- Precision markers listing: two-column dense + markers, clusters of 3
     with stronger dividers. Same item fields and markers as cards; icons unused. --}}
@if ($items !== [])
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
                @if (!empty($section['intro']))
                    <p class="mt-3 text-base md:text-lg" style="color: var(--color-text-muted);"
                       {!! $editor('intro', 'plain') !!}>{{ $section['intro'] }}</p>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('intro', 'plain') !!}></span>
                @endif
            </div>
            <div class="md:columns-2 md:gap-x-16 mt-8">
                @foreach ($items as $i => $item)
                    <div class="break-inside-avoid grid grid-cols-[1.6rem_1fr] gap-3 py-5"
                         style="border-bottom: 1px solid color-mix(in oklab, var(--color-text) {{ ($i + 1) % 3 === 0 ? '28' : '12' }}%, transparent);">
                        <span class="text-lg font-bold leading-6" style="color: var(--brand-accent-text);">+</span>
                        <div>
                            <h3 class="text-base md:text-lg font-bold" style="color: var(--color-text);"
                                {!! $editor("items.{$i}.title", 'plain') !!}>{{ $item['title'] ?? '' }}</h3>
                            @if (!empty($item['body'] ?? null))
                                <p class="mt-1 text-sm md:text-base leading-relaxed" style="color: var(--color-text-muted);"
                                   {!! $editor("items.{$i}.body", 'plain') !!}>{{ $item['body'] }}</p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif
