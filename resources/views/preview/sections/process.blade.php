<div class="py-16 lg:py-20 bg-gray-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        @if (!empty($data['heading']))
            <div class="text-center mb-14">
                <span class="text-sm font-bold tracking-widest uppercase mb-3 block" style="color: var(--brand-accent);">Our Process</span>
                <h2 class="text-3xl md:text-4xl font-extrabold text-gray-900">
                    {{ $data['heading'] }}
                </h2>
            </div>
        @endif
        @if (!empty($data['items']))
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
                @foreach ($data['items'] as $item)
                    <div class="relative text-center">
                        <div class="w-16 h-16 rounded-full mx-auto mb-5 flex items-center justify-center text-white text-xl font-bold"
                             style="background-color: var(--brand-primary);">
                            @if (!empty($item['icon']))
                                <i data-lucide="{{ $item['icon'] }}" class="w-7 h-7"></i>
                            @else
                                {{ $item['step'] ?? '' }}
                            @endif
                        </div>
                        <h3 class="text-lg font-bold text-gray-900 mb-2 line-clamp-1">{{ $item['title'] ?? '' }}</h3>
                        <p class="text-gray-500 text-sm leading-relaxed">{{ $item['body'] ?? '' }}</p>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
