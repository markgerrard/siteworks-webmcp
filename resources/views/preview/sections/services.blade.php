<div class="py-20 lg:py-24 bg-gray-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        @if (!empty($data['heading']))
            <div class="text-center mb-14">
                <span class="text-sm font-bold tracking-widest uppercase mb-3 block" style="color: var(--brand-accent);">What We Do</span>
                <h2 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-4">
                    {{ $data['heading'] }}
                </h2>
                @if (!empty($data['intro']))
                    <p class="text-lg text-gray-500 max-w-2xl mx-auto">{{ $data['intro'] }}</p>
                @endif
            </div>
        @endif
        @if (!empty($data['items']))
            <div style="display: flex; flex-wrap: wrap; justify-content: center; gap: 2rem;">
                @foreach ($data['items'] as $index => $item)
                    @php
                        // Match any reasonable slug variant so the home cards link correctly
                        // regardless of whether the service page was generated under a
                        // local-scoped slug (with location) or a national one (without).
                        $title = $item['title'] ?? '';
                        $location = $project->location ?? '';
                        $candidates = array_values(array_unique(array_filter([
                            \Illuminate\Support\Str::slug($title.'-'.$location),
                            \Illuminate\Support\Str::slug($title),
                        ])));
                        $serviceSlug = null;
                        foreach ($candidates as $c) {
                            if (in_array($c, $pageKeys ?? [], true)) { $serviceSlug = $c; break; }
                        }
                        $hasServicePage = $serviceSlug !== null;
                        $serviceHref = $hasServicePage
                            ? (($layout ?? 'one_page') === 'multi_page'
                                ? ($pageUrl ?? fn ($p) => route('preview.page', [$previewSlug ?? '', $p]))($serviceSlug)
                                : '#' . $serviceSlug)
                            : null;
                    @endphp
                    <div class="bg-white p-8 rounded-lg shadow-md border-t-4 transition-all duration-300 hover:shadow-xl hover:-translate-y-1"
                         style="border-top-color: var(--brand-primary); flex: 0 1 calc(33.333% - 1.34rem); min-width: 280px;">
                        <div class="w-12 h-12 rounded-md mb-5 flex items-center justify-center text-white font-bold text-lg"
                             style="background-color: var(--brand-primary);">
                            @if (!empty($item['icon']))
                                <i data-lucide="{{ $item['icon'] }}" class="w-6 h-6"></i>
                            @else
                                {{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }}
                            @endif
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 mb-3">{{ $item['title'] ?? '' }}</h3>
                        <p class="text-gray-600 leading-relaxed mb-4">{{ $item['body'] ?? '' }}</p>
                        @if ($serviceHref)
                            <a href="{{ $serviceHref }}"
                               class="inline-flex items-center gap-1 text-sm font-semibold transition-colors hover:brightness-110"
                               style="color: var(--brand-accent);">
                                Read more
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                            </a>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
