{{-- Editorial values ledger: NUMBERED rows matching the service
     features/numbered chrome exactly.
     Optional plain intro under the title; optional portrait side image
     (options.side_image, default off) using the page hero scalar. --}}
{{-- Top padding tighter than section spacing: sits on the editorial
     story's same-background bottom edge (see variants/story/editorial).
     Combined seam ≈ one section-spacing, not two. --}}
<div data-svc-variant="ledger" class="pt-10 lg:pt-12" style="background-color: var(--color-surface); padding-bottom: var(--section-spacing);">
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
        @php
            $sideImage = ($section['__options']['side_image'] ?? false) === true;
            $portrait = $sideImage ? ($bandImg ?? null) : null;
            $imgRadius = (($section['__options']['image_radius'] ?? null) === 'soft') ? 'var(--radius-card)' : '0';
        @endphp
        @if (! empty($items))
            <div class="mt-10 grid grid-cols-1 {{ $portrait ? 'lg:grid-cols-5 gap-10 lg:gap-14 items-start' : '' }}">
                <div @class([($portrait ? 'lg:col-span-3' : '')])
                     style="border-top: 1px solid color-mix(in oklab, var(--color-text) 18%, transparent);">
                    @foreach ($items as $i => $item)
                        <div class="grid grid-cols-[3.5rem_1fr] md:grid-cols-[5rem_minmax(0,1fr)_minmax(0,1.4fr)] gap-x-6 md:gap-x-8 gap-y-1 items-baseline py-7"
                             style="border-bottom: 1px solid color-mix(in oklab, var(--color-text) 18%, transparent);">
                            <span class="text-2xl md:text-3xl font-light tabular-nums" style="color: var(--brand-accent-text);">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>
                            <h3 class="text-lg md:text-xl font-bold" style="color: var(--color-text);"
                                {!! $editor("items.{$i}.title", 'plain') !!}>{{ $item['title'] ?? '' }}</h3>
                            <p class="col-start-2 md:col-start-3 md:row-start-1 text-base leading-relaxed" style="color: var(--color-text-muted);"
                               {!! $editor("items.{$i}.body", 'plain') !!}>{{ $item['body'] ?? '' }}</p>
                        </div>
                    @endforeach
                </div>
                @if ($portrait)
                    <figure class="lg:col-span-2" style="border: 1px solid color-mix(in oklab, var(--color-text) 18%, transparent); border-radius: {{ $imgRadius }};">
                        <div class="overflow-hidden" style="aspect-ratio: 3 / 4; border-radius: {{ $imgRadius }};">
                            <img src="{{ $portrait }}" alt="" aria-hidden="true" class="w-full h-full object-cover" loading="lazy">
                        </div>
                    </figure>
                @endif
            </div>
        @endif
    </div>
</div>
