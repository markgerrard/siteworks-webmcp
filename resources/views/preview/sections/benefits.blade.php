<div class="py-16 lg:py-20 bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        @if (!empty($data['heading']))
            <div class="text-center mb-14">
                <span class="text-sm font-bold tracking-widest uppercase mb-3 block" style="color: var(--brand-accent);">Why Choose Us</span>
                <h2 class="text-3xl md:text-4xl font-extrabold text-gray-900">
                    {{ $data['heading'] }}
                </h2>
            </div>
        @endif
        @if (!empty($data['items']))
            <div style="display: flex; flex-wrap: wrap; justify-content: center; gap: 2rem;">
                @foreach ($data['items'] as $item)
                    <div class="bg-gray-50 p-8 rounded-lg text-center" style="flex: 0 1 calc(33.333% - 1.34rem); min-width: 280px;">
                        <div class="w-14 h-14 rounded-full mx-auto mb-5 flex items-center justify-center text-white"
                             style="background-color: var(--brand-accent);">
                            @if (!empty($item['icon']))
                                <i data-lucide="{{ $item['icon'] }}" class="w-7 h-7"></i>
                            @else
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            @endif
                        </div>
                        <h3 class="text-lg font-bold text-gray-900 mb-2">{{ $item['title'] ?? '' }}</h3>
                        <p class="text-gray-500 text-sm leading-relaxed">{{ $item['body'] ?? '' }}</p>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
