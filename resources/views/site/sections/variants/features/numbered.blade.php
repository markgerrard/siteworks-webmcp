{{-- Editorial numbered rows: full-width items, serif-scale index, hairline
     rules. Same item fields and markers as cards; icons unused by design. --}}
@if ($items !== [])
    {{-- Top padding tighter than section spacing: sits on the editorial
         intro's same-background bottom edge (see editorial.blade.php). --}}
    <div data-svc-variant="numbered" class="pt-10 lg:pt-12" style="background-color: var(--color-surface); padding-bottom: var(--section-spacing);">
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
            <div class="mt-10" style="border-top: 1px solid color-mix(in oklab, var(--color-text) 18%, transparent);">
                @foreach ($items as $i => $item)
                    <div class="grid grid-cols-[3.5rem_1fr] md:grid-cols-[5rem_minmax(0,1fr)_minmax(0,1.4fr)] gap-x-6 md:gap-x-8 gap-y-1 items-baseline py-7"
                         style="border-bottom: 1px solid color-mix(in oklab, var(--color-text) 18%, transparent);">
                        <span class="text-2xl md:text-3xl font-light tabular-nums" style="color: var(--brand-accent-text);">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>
                        <h3 class="text-lg md:text-xl font-bold" style="color: var(--color-text);"
                            {!! $editor("items.{$i}.title", 'plain') !!}>{{ $item['title'] ?? '' }}</h3>
                        @if (!empty($item['body'] ?? null))
                            <p class="col-start-2 md:col-start-3 md:row-start-1 text-base leading-relaxed" style="color: var(--color-text-muted);"
                               {!! $editor("items.{$i}.body", 'plain') !!}>{{ $item['body'] }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif
