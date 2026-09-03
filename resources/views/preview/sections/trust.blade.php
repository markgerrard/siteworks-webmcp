<div class="py-16 lg:py-20" style="background-color: var(--brand-primary);">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        @if (!empty($data['heading']))
            <div class="text-center mb-12">
                <span class="text-sm font-bold tracking-widest uppercase mb-3 block" style="color: var(--brand-accent);">Why Choose Us</span>
                <h2 class="text-3xl md:text-4xl font-extrabold text-white">
                    {{ $data['heading'] }}
                </h2>
            </div>
        @endif
        @if (!empty($data['items']))
            <div style="display: flex; flex-wrap: wrap; justify-content: center; gap: 1.5rem;">
                @foreach (array_slice($data['items'] ?? [], 0, 3) as $item)
                    <div class="flex items-start gap-4 bg-white/10 backdrop-blur-sm p-6 rounded-lg border border-white/10" style="flex: 0 1 calc(33.333% - 1rem); min-width: 280px;">
                        <div class="flex-shrink-0 w-11 h-11 rounded-md flex items-center justify-center shadow-sm"
                             style="background-color: var(--brand-accent);">
                            @if (!empty($item['icon']))
                                <i data-lucide="{{ $item['icon'] }}" class="w-6 h-6 text-white"></i>
                            @else
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path>
                                </svg>
                            @endif
                        </div>
                        <div>
                            <h3 class="font-bold text-white text-lg">{{ $item['title'] ?? '' }}</h3>
                            <p class="text-white/90 mt-1 text-sm leading-relaxed">{{ $item['body'] ?? '' }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
