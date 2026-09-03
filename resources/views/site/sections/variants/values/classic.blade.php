@php
    // Clamp items count: 4 is ugly in a 3-col layout, >5 is too many
    $items = array_values($items ?? []);
    $count = count($items);
    if ($count === 4) {
        $items = array_slice($items, 0, 3);
    } elseif ($count > 5) {
        $items = array_slice($items, 0, 5);
    }
@endphp

<div class="py-20 lg:py-24 bg-white">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        @if (!empty($section['title']))
            <div class="text-center mb-16">
                @if (empty($section['__suppress_eyebrow']))
                    <span class="text-sm font-bold tracking-widest uppercase mb-4 block" style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                @endif
                <h2 class="text-4xl md:text-5xl font-extrabold text-gray-900 leading-tight text-balance"
                    {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
            </div>
        @else
            @if ($emitMarkers)
                <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                <span class="hidden"{!! $editor('title', 'plain') !!}></span>
            @endif
        @endif

        @if (!empty($items))
            <div style="display: flex; flex-wrap: wrap; justify-content: center; gap: 2rem;">
                @foreach ($items as $i => $item)
                    <div class="text-center p-9" style="flex: 0 1 calc(33.333% - 1.34rem); min-width: 260px;">
                        <div class="w-16 h-16 rounded-full mx-auto mb-6 flex items-center justify-center font-extrabold text-2xl shadow-sm"
                             style="background-color: var(--brand-primary); color: var(--color-text-on-primary, #ffffff);">
                            {{ $i + 1 }}
                        </div>
                        <h3 class="text-xl md:text-2xl font-bold text-gray-900 mb-3 leading-snug"
                            {!! $editor("items.{$i}.title", 'plain') !!}>{{ $item['title'] ?? '' }}</h3>
                        <p class="text-gray-600 text-base leading-relaxed"
                           {!! $editor("items.{$i}.body", 'plain') !!}>{{ $item['body'] ?? '' }}</p>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
