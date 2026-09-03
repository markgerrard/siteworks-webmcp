@if ($items !== [])
    <div class="site-section-spacing relative overflow-hidden"
         style="background-color: var(--color-surface);
                background-image:
                    radial-gradient(ellipse 55% 50% at 12% 15%, color-mix(in oklab, var(--color-primary, transparent) 18%, transparent) 0%, transparent 60%),
                    radial-gradient(ellipse 50% 45% at 88% 85%, color-mix(in oklab, var(--color-accent, transparent) 15%, transparent) 0%, transparent 55%);">
        <div class="site-shell-container px-4 sm:px-6 lg:px-8 relative">
            <div class="text-center mb-12">
                @if (!empty($section['title']))
                    @if (empty($section['__suppress_eyebrow']))
                        <span class="text-sm font-bold tracking-widest uppercase mb-3 block" style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                    @elseif ($emitMarkers)
                        <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                    @endif
                    <h2 class="text-3xl md:text-4xl font-extrabold" style="color: var(--color-text);"
                        {!! $editor('title', 'plain') !!}>{!! app(App\Services\Site\AccentWordRenderer::class)->wrap($section['title'] ?? '', $section['accent_word'] ?? null, isset($site) && \App\Support\ChromeKnobs::accentStyle($site) === 'italic' ? 'italic' : null, $section['accent_ranges'] ?? null) !!}</h2>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                @endif
                @if (!empty($section['intro']))
                    <p class="mt-3 text-base md:text-lg max-w-2xl mx-auto" style="color: var(--color-text-muted);"
                       {!! $editor('intro', 'plain') !!}>{{ $section['intro'] }}</p>
                @endif
            </div>
            <div class="grid gap-10 lg:gap-12 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($items as $i => $item)
                    {{-- Features tile keeps its icon-left / content-right
                         layout (checklist semantic for "what's included"
                         scope), but shares the DNA of the home services
                         tile: card bg, top-stripe, radius, shadow, hover
                         lift, and icon-box treatment all drawn from the
                         same design tokens. --}}
                    <div class="flex items-start gap-5 p-8 shadow-md border-t-4 transition-all duration-300 hover:shadow-xl hover:-translate-y-1"
                         style="background-color: var(--color-surface-alt); border: 1px solid var(--color-border); border-top-width: 4px; border-top-color: var(--brand-primary); border-radius: var(--radius-card);">
                        <div class="flex-shrink-0 w-12 h-12 flex items-center justify-center"
                             style="background-color: var(--brand-primary); color: #ffffff; border-radius: var(--radius-button);">
                            @php $iconHtml = $renderIcon($item['icon'] ?? null, 'w-6 h-6'); @endphp
                            @if (!empty($iconHtml))
                                {!! $iconHtml !!}
                            @else
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                            @endif
                        </div>
                        <div class="flex-1 min-w-0">
                            <h3 class="text-xl font-bold mb-3 leading-snug" style="color: var(--color-text-on-alt);"
                                {!! $editor("items.{$i}.title", 'plain') !!}>{{ $item['title'] ?? '' }}</h3>
                            @if (!empty($item['body'] ?? null))
                                <p class="text-base leading-relaxed" style="color: var(--color-text-muted-on-alt);"
                                   {!! $editor("items.{$i}.body", 'plain') !!}>{{ $item['body'] }}</p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif
