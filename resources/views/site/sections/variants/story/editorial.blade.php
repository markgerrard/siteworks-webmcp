{{-- Editorial story: magazine longform. Full-width display heading,
     two-column prose only when the rendered plain-text body exceeds
     ~500 chars (display-only), drop-cap on the first paragraph, story
     image recropped to a wide banner. Same fields, same markers as classic. --}}
{{-- Bottom padding is deliberately tighter than the top: the ledger
     values section that follows in the editorial preset shares this
     background, so full section spacing on both sides read as a void.
     Combined seam ≈ one section-spacing, not two. --}}
@php
    $renderedBody = ! empty($section['body']) ? $richHtml($section['body']) : '';
    $twoCol = mb_strlen(trim(strip_tags($renderedBody))) > 500;
@endphp
<div class="pt-20 lg:pt-24 pb-10 lg:pb-12" style="background-color: var(--color-surface);" data-svc-variant="editorial">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        @if (empty($section['__suppress_eyebrow']))
            <span class="text-sm font-bold tracking-widest uppercase mb-5 block" style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
        @elseif ($emitMarkers)
            <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
        @endif
        @if (!empty($section['title']))
            <h2 class="text-4xl md:text-5xl lg:text-6xl font-extrabold leading-[1.05] max-w-4xl text-pretty" style="color: var(--color-text);"
                {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
        @elseif ($emitMarkers)
            <span class="hidden"{!! $editor('title', 'plain') !!}></span>
        @endif
        <div class="mt-10 mb-9 h-px" style="background-color: color-mix(in oklab, var(--color-text) 25%, transparent);"></div>
        @if (!empty($section['body']))
            <div @class([
                     'prose prose-lg max-w-none text-lg leading-relaxed [&>p]:mb-5',
                     'md:columns-2 md:gap-14' => $twoCol,
                     '[&>p:first-child]:first-letter:float-left [&>p:first-child]:first-letter:mr-3 [&>p:first-child]:first-letter:text-6xl [&>p:first-child]:first-letter:font-extrabold [&>p:first-child]:first-letter:leading-none [&>p:first-child]:first-letter:text-[var(--color-text)]' => ($section['__options']['drop_cap'] ?? true),
                 ])
                 style="color: var(--color-text-muted);"
                 {!! $editor('body', 'rich', is_array($section['body']) ? $section['body'] : null) !!}>{!! $renderedBody !!}</div>
        @elseif ($emitMarkers)
            <span class="hidden"{!! $editor('body', 'rich') !!}></span>
        @endif
        @php
            $bandCount = $section['__options']['band_image_count'] ?? null;
        @endphp
        @if ($bandCount !== null)
            {{-- Picked band images (band / band_2 / band_3 slots — never
                 reused from hero/intro; picker UI lands with D6/D7).
                 Height presets keep visual weight consistent per count. --}}
            @php
                $bandHeight = $section['__options']['band_image_height'] ?? 'standard';
                $unwrapBand = function ($image) use ($profile) {
                    if (is_array($image)) {
                        $watermarkOn = (bool) (($profile['watermark_enabled'] ?? true));

                        return ($watermarkOn && ! empty($image['watermark_url'])) ? $image['watermark_url'] : ($image['url'] ?? null);
                    }

                    return $image;
                };
                $pickedBand = [];
                foreach (array_slice([$bandImageUrl ?? null, $bandImage2Url ?? null, $bandImage3Url ?? null], 0, max(1, min(3, (int) $bandCount))) as $bandRaw) {
                    $bandUrlResolved = $unwrapBand($bandRaw);
                    if (is_string($bandUrlResolved) && $bandUrlResolved !== '' && ! in_array($bandUrlResolved, $pickedBand, true)) {
                        $pickedBand[] = $bandUrlResolved;
                    }
                }
                $bandTileAspect = [
                    1 => ['short' => '3.5 / 1', 'standard' => '21 / 8', 'tall' => '2 / 1'],
                    2 => ['short' => '2 / 1', 'standard' => '16 / 10', 'tall' => '4 / 3'],
                    3 => ['short' => '16 / 10', 'standard' => '4 / 3', 'tall' => '1 / 1'],
                ][max(1, min(3, (int) $bandCount))][$bandHeight] ?? '21 / 8';
                $bandTileRadius = (($section['__options']['image_radius'] ?? null) === 'soft') ? 'var(--radius-card)' : '0';
            @endphp
            @if ($pickedBand !== [])
                <figure class="mt-14" data-band-images="{{ count($pickedBand) }}">
                    <div class="grid gap-4 md:gap-6 grid-cols-1 {{ count($pickedBand) === 2 ? 'md:grid-cols-2' : (count($pickedBand) === 3 ? 'md:grid-cols-3' : '') }}">
                        @foreach ($pickedBand as $bandTile)
                            <div class="overflow-hidden" style="aspect-ratio: {{ $bandTileAspect }}; border-radius: {{ $bandTileRadius }};">
                                <img src="{{ $bandTile }}" alt="" aria-hidden="true" class="w-full h-full object-cover" loading="lazy">
                            </div>
                        @endforeach
                    </div>
                </figure>
            @endif
        @elseif (!empty($introImg))
            <figure class="mt-14">
                <div class="overflow-hidden" style="aspect-ratio: 21 / 8; border-radius: {{ (($section['__options']['image_radius'] ?? null) === 'soft') ? 'var(--radius-card)' : '0' }};">
                    <img src="{{ $introImg }}" alt="{{ $section['title'] ?? 'About us' }}"
                         class="w-full h-full object-cover" loading="lazy">
                </div>
            </figure>
        @endif
    </div>
</div>
