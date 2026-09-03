<div class="py-20 lg:py-24 bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-12 items-start">
            {{-- Left: heading and accent --}}
            <div class="lg:col-span-2">
                <span class="text-sm font-bold tracking-widest uppercase mb-3 block" style="color: var(--brand-accent);">{{ $data['eyebrow'] ?? 'About This Service' }}</span>
                @if (!empty($data['heading']))
                    <h2 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-6 leading-tight text-pretty">
                        {!! preg_replace('/\s+(\S+)$/', '&nbsp;$1', e($data['heading'])) !!}
                    </h2>
                @endif
                <div class="w-16 h-1 rounded-full" style="background-color: var(--brand-accent);"></div>
            </div>
            {{-- Right: body text split into paragraphs --}}
            <div class="lg:col-span-3">
                @if (!empty($data['body']))
                    <div class="space-y-5 text-gray-600 text-lg leading-relaxed">
                        @foreach (preg_split('/\\\\n\\\\n|\n\n/', $data['body']) as $paragraph)
                            @if (trim($paragraph) !== '')
                                <p>{{ trim($paragraph) }}</p>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
