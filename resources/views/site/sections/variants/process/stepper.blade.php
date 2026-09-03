{{-- Editorial ghost-numeral stepper: the one section where numbering
     carries meaning. Horizontal 4-up under a continuous hairline track;
     large low-opacity accent numerals, no circles. Pale surface — the
     whitespace reset between the brand band and the dark reviews. --}}
@php
    $items = array_values($section['items'] ?? []);
    $eyebrow = $section['eyebrow'] ?? 'How It Works';
@endphp
@if ($items !== [])
    <div data-svc-variant="stepper" class="site-section-spacing" style="background-color: var(--color-surface);">
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
            <div class="mt-10 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-x-6 gap-y-10 pt-10" style="border-top: 1px solid color-mix(in oklab, var(--color-text) 16%, transparent);">
                @foreach ($items as $i => $item)
                    <div class="grid grid-cols-[3.5rem_1fr] lg:grid-cols-1 gap-x-4">
                        <span class="font-light tabular-nums leading-none lg:mb-4" style="font-size: clamp(3rem, 4vw, 3.75rem); color: color-mix(in oklab, var(--brand-accent-text) 40%, transparent);">{{ str_pad((string) ($item['step'] ?? $i + 1), 2, '0', STR_PAD_LEFT) }}</span>
                        <div>
                            <h3 class="text-lg md:text-xl font-bold" style="color: var(--color-text);"
                                {!! $editor("items.{$i}.title", 'plain') !!}>@if ($emitMarkers)<span class="hidden"{!! $editor("items.{$i}.step", 'plain') !!}></span>@endif{{ $item['title'] ?? '' }}</h3>
                            <div class="mt-2 text-sm md:text-base leading-relaxed prose prose-sm" style="color: var(--color-text-muted);"
                                 {!! $editor("items.{$i}.body", 'rich', is_array($item['body'] ?? null) ? $item['body'] : null) !!}>{!! $richHtml($item['body'] ?? '') !!}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif
