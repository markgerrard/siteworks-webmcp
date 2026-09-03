{{-- Showcase checklist band: stacked header, check-marked items, optional
     intro-image pane. Same item fields and markers as cards; icons unused. --}}
@if ($items !== [])
    <div data-svc-variant="checklist" class="site-section-spacing" style="background-color: var(--color-surface);">
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
                @if (!empty($section['intro']))
                    <p class="mt-3 text-base md:text-lg" style="color: var(--color-text-muted);"
                       {!! $editor('intro', 'plain') !!}>{{ $section['intro'] }}</p>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('intro', 'plain') !!}></span>
                @endif
            </div>
            @php
                $bandImg = $bandImg ?? $featuresImg ?? null;
                $primary = $section['__options']['image_alignment'] ?? 'left';
                $secondary = $section['__options']['image_alignment_secondary'] ?? ($primary === 'left' ? 'right' : 'left');
                $bandImgLeft = $secondary === 'left';
                $bandRadius = (($section['__options']['image_radius'] ?? null) === 'soft') ? 'var(--radius-card)' : '0';
            @endphp
            <div class="grid grid-cols-1 {{ $bandImg ? 'lg:grid-cols-2' : '' }} shadow-xl" style="background-color: var(--color-surface-alt);">
                <div @class(['px-7 py-10 lg:px-12 lg:py-12 flex flex-col justify-center', 'lg:order-last' => $bandImg && $bandImgLeft])>
                    @foreach ($items as $i => $item)
                        <div class="grid grid-cols-[2rem_1fr] gap-4 py-4 {{ $i > 0 ? 'border-t' : '' }}" style="border-color: color-mix(in oklab, var(--color-text-on-alt) 12%, transparent);">
                            <span class="w-6 h-6 rounded-full flex items-center justify-center mt-0.5" style="background-color: var(--brand-primary); color: #ffffff;">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            </span>
                            <div>
                                <h3 class="text-base md:text-lg font-bold" style="color: var(--color-text-on-alt);"
                                    {!! $editor("items.{$i}.title", 'plain') !!}>{{ $item['title'] ?? '' }}</h3>
                                @if (!empty($item['body'] ?? null))
                                    <p class="mt-1 text-sm md:text-base leading-relaxed" style="color: var(--color-text-muted-on-alt);"
                                       {!! $editor("items.{$i}.body", 'plain') !!}>{{ $item['body'] }}</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
                @if ($bandImg)
                    <div data-svc-media class="relative min-h-[320px]">
                        <img src="{{ $bandImg }}" alt="" aria-hidden="true" class="absolute inset-0 w-full h-full object-cover" loading="lazy" style="border-radius: {{ $bandRadius }};">
                    </div>
                @endif
            </div>
        </div>
    </div>
@endif
