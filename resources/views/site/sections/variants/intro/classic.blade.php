<div class="py-20 lg:py-24 bg-white">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-12 items-start">
            {{-- Left: heading, accent, and supporting image --}}
            <div class="lg:col-span-2">
                @if (empty($section['__suppress_eyebrow']))
                    <span class="text-sm font-bold tracking-widest uppercase mb-3 block" style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                @endif
                @if (!empty($section['title']))
                    <h2 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-6 leading-tight text-pretty"
                        {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
                @else
                    @if ($emitMarkers)
                        <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                    @endif
                @endif
                <div class="w-16 h-1 rounded-full" style="background-color: var(--brand-accent);"></div>
                @if (!empty($introImg))
                    <div class="mt-8 overflow-hidden"
                         style="aspect-ratio: 4 / 3; border-radius: var(--radius-card); box-shadow: 0 10px 30px -12px rgba(0,0,0,0.25);">
                        <img src="{{ $introImg }}"
                             alt="{{ $section['title'] ?? 'Service detail' }}"
                             class="w-full h-full object-cover"
                             loading="lazy">
                    </div>
                @endif
            </div>
            {{-- Right: body text --}}
            <div class="lg:col-span-3">
                @if (!empty($section['body']))
                    <div class="space-y-5 text-gray-600 text-lg leading-relaxed prose prose-lg max-w-none"
                         {!! $editor('body', 'rich', is_array($section['body']) ? $section['body'] : null) !!}>{!! $richHtml($section['body']) !!}</div>
                @else
                    @if ($emitMarkers)
                        <span class="hidden"{!! $editor('body', 'rich') !!}></span>
                    @endif
                @endif
            </div>
        </div>
    </div>
</div>
