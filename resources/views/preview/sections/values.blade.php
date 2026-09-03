<div class="py-20 lg:py-24 bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        @if (!empty($data['heading']))
            <div class="text-center mb-14">
                <span class="text-sm font-bold tracking-widest uppercase mb-3 block" style="color: var(--brand-accent);">Our Values</span>
                <h2 class="text-3xl md:text-4xl font-extrabold text-gray-900">
                    {{ $data['heading'] }}
                </h2>
            </div>
        @endif
        @if (!empty($data['items']))
            @php
                // Keep the grid tidy: values look orphaned when the final
                // row has a single card. Clamp to either 3 (one row) or 5
                // (two rows: 3 + 2 centered) so we never end up with 1 or
                // 4 items, which are the two ugly cases for a 3-col layout.
                $items = array_values($data['items']);
                $count = count($items);
                if ($count === 4) {
                    $items = array_slice($items, 0, 3);
                } elseif ($count === 1 || $count === 2) {
                    // Leave as-is — centred flex-wrap handles 1/2 nicely.
                } elseif ($count > 5) {
                    $items = array_slice($items, 0, 5);
                }
            @endphp
            <div style="display: flex; flex-wrap: wrap; justify-content: center; gap: 2rem;">
                @foreach ($items as $index => $item)
                    <div class="text-center p-8" style="flex: 0 1 calc(33.333% - 1.34rem); min-width: 260px;">
                        <div class="w-14 h-14 rounded-full mx-auto mb-5 flex items-center justify-center font-extrabold text-xl text-white"
                             style="background-color: var(--brand-primary);">
                            @if (!empty($item['icon']))
                                <i data-lucide="{{ $item['icon'] }}" class="w-7 h-7"></i>
                            @else
                                {{ $index + 1 }}
                            @endif
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 mb-3">{{ $item['title'] ?? '' }}</h3>
                        <p class="text-gray-500 leading-relaxed">{{ $item['body'] ?? '' }}</p>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
