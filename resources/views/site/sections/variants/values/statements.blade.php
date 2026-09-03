{{-- Showcase values, v2 (design review): the services
     checklist-band chrome — elevated surface-alt card, brand check
     circles, image pane — adapted to values fields, replacing the
     accent-bar statement rows for cross-kind consistency. Variant token
     name kept to avoid recipe churn.

     Image: page hero scalar (the story already uses the intro image);
     renderer stamps the OPPOSITE of the primary image_alignment here, so
     with the story image left (services default), this band's image sits
     right. No image -> full-width list. Ordinal ban still binding. --}}
@php
    $items = array_values($section['items'] ?? []);
    $bandImg = $bandImg ?? null;
    $primary = $section['__options']['image_alignment'] ?? 'left';
    $secondary = $section['__options']['image_alignment_secondary'] ?? ($primary === 'left' ? 'right' : 'left');
    $imgLeft = $secondary === 'left';
    $imgRadius = (($section['__options']['image_radius'] ?? null) === 'soft') ? 'var(--radius-card)' : '0';
@endphp
@if ($items !== [])
    {{-- Seam note: the showcase story panel above changes background, so
         this band keeps a light tightened top only (pt-10/12). --}}
    <div data-svc-variant="statements" class="pt-10 lg:pt-12" style="background-color: var(--color-surface); padding-bottom: var(--section-spacing);">
        <div class="site-shell-container px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl mb-6">
                @if (empty($section['__suppress_eyebrow']))
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
            </div>
            <div class="grid grid-cols-1 {{ $bandImg ? 'lg:grid-cols-2' : '' }} shadow-xl" style="background-color: var(--color-surface-alt);">
                <div @class(['px-7 py-10 lg:px-12 lg:py-12 flex flex-col justify-center', 'lg:order-last' => $bandImg && $imgLeft])>
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
                                @elseif ($emitMarkers)
                                    <span class="hidden"{!! $editor("items.{$i}.body", 'plain') !!}></span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
                @if ($bandImg)
                    <div data-svc-media class="relative min-h-[320px]">
                        <img src="{{ $bandImg }}" alt="" aria-hidden="true" class="absolute inset-0 w-full h-full object-cover" loading="lazy"
                             style="border-radius: {{ $imgRadius }};">
                    </div>
                @endif
            </div>
        </div>
    </div>
@endif
