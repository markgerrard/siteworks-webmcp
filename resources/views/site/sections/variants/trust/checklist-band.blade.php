{{-- Showcase checklist band on trust: shipped features/checklist chrome
     (elevated surface-alt card, brand check circles) applied to trust
     signals. Optional image pane mirrors features/checklist: the page's
     band/intro slot image splits the card lg:grid-cols-2 when present,
     and the card stays full-width when the slot is empty.
     Dispatcher passes the full item list; this variant does not clamp. --}}
@php
    $items = array_values($items ?? []);
    // Same slot unwrap as features.blade.php: PageRenderer passes slot
    // images as ['url' => …, 'watermark_url' => …].
    $profile = $profile ?? [];
    $slotImageUrl = function ($image) use ($profile) {
        if (is_array($image)) {
            $watermarkOn = (bool) ($profile['watermark_enabled'] ?? true);

            return ($watermarkOn && ! empty($image['watermark_url']))
                ? $image['watermark_url']
                : ($image['url'] ?? null);
        }

        return $image;
    };
    $bandImg = $slotImageUrl($bandImageUrl ?? null) ?? $slotImageUrl($introImageUrl ?? null);
    $eyebrow = $section['eyebrow'] ?? 'Why Choose Us';
    $title = (string) ($section['title'] ?? '');
    $titleMatchesEyebrow = $title !== '' && strcasecmp(trim($title), $eyebrow) === 0;
    $hideEyebrow = $titleMatchesEyebrow || ! empty($section['__suppress_eyebrow']);
    // When __surface is contrast the wrapper is a different background
    // from its neighbours, so full site-section-spacing applies (the
    // background change absorbs the seam). Absent = kit surface wrapper.
    $isContrast = ($section['__surface'] ?? null) === 'contrast';
    $wrapperBg = $isContrast ? 'var(--color-surface-contrast)' : 'var(--color-surface)';
    $textOnWrapper = $isContrast ? 'var(--color-text-on-contrast)' : 'var(--color-text)';
    $accentOnWrapper = $isContrast ? 'var(--brand-accent-text-on-contrast)' : 'var(--brand-accent-text)';
@endphp
@if ($items !== [])
    <div data-svc-variant="checklist-band" class="site-section-spacing" style="background-color: {{ $wrapperBg }};">
        <div class="site-shell-container px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl mb-6">
                @if (! $hideEyebrow && $title !== '')
                    <span class="text-sm font-bold tracking-widest uppercase mb-3 block" style="color: {{ $accentOnWrapper }};" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                @endif
                @if ($title !== '')
                    <h2 class="text-3xl md:text-4xl font-extrabold text-pretty" style="color: {{ $textOnWrapper }};"
                        {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                @endif
            </div>
            <div class="grid grid-cols-1 {{ $bandImg ? 'lg:grid-cols-2' : '' }} shadow-xl" style="background-color: var(--color-surface-alt);">
                <div class="px-7 py-10 lg:px-12 lg:py-12 flex flex-col justify-center">
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
                        <img src="{{ $bandImg }}" alt="" aria-hidden="true" class="absolute inset-0 w-full h-full object-cover" loading="lazy">
                    </div>
                @endif
            </div>
        </div>
    </div>
@endif
