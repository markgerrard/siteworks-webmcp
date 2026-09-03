{{-- 3-col responsive tile grid. Mobile shows an always-visible scrim
     with category label; desktop reveals the full overlay on hover.
     Placeholder tile renders when image_id is null — surface-alt block
     with centred category chip, reads as intentional. --}}
<section class="py-16 lg:py-20" style="background-color: var(--color-surface);">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        @if ($heading !== '')@if (($section['__options']['gallery_heading'] ?? null) === 'ruled')
            <div class="flex items-baseline justify-between gap-6 pt-4 mb-10" style="border-top: 2px solid var(--brand-accent);">
                @if (empty($section['__suppress_eyebrow']))
                    <span class="text-xs font-bold tracking-[0.18em] uppercase" style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>{{ $section['eyebrow'] ?? ($vocab?->galleryEyebrow($items) ?? 'Our Work') }}</span>
                @endif
            </div>
@else
            @if (empty($section['__suppress_eyebrow']))
                <div class="mb-3">
                    <span class="text-xs font-bold tracking-[0.18em] uppercase" style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>{{ $section['eyebrow'] ?? ($vocab?->galleryEyebrow($items) ?? 'Our Work') }}</span>
                </div>
            @endif
@endif
            <h2 class="text-3xl md:text-4xl font-extrabold tracking-tight mb-12 md:mb-16"
                style="color: var(--color-text);
                       font-family: var(--font-display);
                       letter-spacing: var(--heading-letter-spacing);"
                {!! $editor('title', 'plain') !!}>
                {{ $heading }}
            </h2>
        @endif

        @if ($items->isEmpty())
            <p class="text-sm" style="color: var(--color-text-muted);">
                No projects to display yet.
            </p>
        @else
            @php
                // Sites with fewer than 9 tiles render in 2-col "featured projects"
                // layout — bigger tiles, more white space, reads as a curated
                // selection rather than a sparse 3-col grid with empty slots.
                // Threshold matches the prompt's gallery cardinality of 6–9
                // (the count is picked adaptively based on profile signal).
                $gridClasses = $items->count() >= 9
                    ? 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 md:gap-8'
                    : 'grid grid-cols-1 sm:grid-cols-2 gap-6 md:gap-10';
            @endphp
            <div class="{{ $gridClasses }}">
                @foreach ($items as $item)@php($href = $detailHrefFor($item))@php($tag = $href ? 'a' : 'article')@php($tileClass = 'group relative overflow-hidden aspect-[4/5]'.($href ? ' block' : ''))
                    <{{ $tag }}@if ($href) href="{{ $href }}"@endif class="{{ $tileClass }}"
                             style="background-color: var(--color-surface-alt);
                                    border-radius: var(--radius-card);">

                        @if ($vocab?->shouldShowExampleBadge($item))
                            <span class="absolute top-2 right-2 z-20 text-[10px] font-bold uppercase tracking-wider px-2 py-1 rounded"
                                  style="background-color: rgba(255,255,255,0.92);
                                         color: var(--color-text-muted);">
                                Example
                            </span>
                        @endif

                        @if ($item->image?->url)
                            {{-- ?v=<media_id> busts the browser cache when a
                                 regen swaps the file at the deterministic
                                 sites/{site}/project_items/{id}.jpg path.
                                 Without this, after a re-roll of an existing
                                 item id the browser keeps serving the old
                                 image until cache expires. --}}
                            <img class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110"
                                 src="{{ $item->image->url }}?v={{ $item->image->id }}"
                                 alt="{{ $item->image->alt_text ?? $item->title }}"
                                 loading="lazy">
                        @else
                            {{-- Intentional placeholder — not a "broken image" state --}}
                            <div class="w-full h-full flex items-end p-6"
                                 style="background-color: var(--color-surface-alt);">
                                <span class="text-xs font-bold uppercase tracking-widest"
                                      style="color: var(--color-text-muted-on-alt);">
                                    {{ $item->category }}
                                </span>
                            </div>
                        @endif

                        {{-- Mobile scrim (always visible on touch devices, hidden on md+) --}}
                        <div class="absolute inset-x-0 bottom-0 p-4 md:hidden"
                             style="background: linear-gradient(to top, rgba(0,0,0,0.7), transparent);">
                            <p class="text-xs font-bold uppercase tracking-widest text-white mb-1">
                                {{ $item->category }}
                            </p>
                            <p class="text-sm font-semibold text-white leading-tight">
                                {{ $item->title }}
                            </p>
                        </div>

                        {{-- Desktop hover overlay — uniform dark wash, white type,
                             brand-accent eyebrow. Same lesson as projects_hero:
                             trying to derive the wash from theme tokens breaks
                             under common palette combinations. --color-primary
                             at 88% goes black-hole on dark-primary brands and
                             saturated haze on bright primaries; --color-surface
                             at 88% white-washes on light-surface brands,
                             leaving white title invisible against a white
                             background. The canonical hero treatment used
                             across the rest of the site is "plain black scrim +
                             hardcoded white text" for exactly this reason — visual
                             consistency across palettes beats per-image optimisation.
                             Apply the same here. The eyebrow keeps `--color-accent`
                             (the raw accent hex, not the on-alt variant) so brand
                             identity still lives in the type — that READS reliably
                             against the dark wash because we already engineered
                             accent to be saturated. --}}
                        <div class="hidden md:flex absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-500 flex-col items-center justify-center text-center p-6 bg-black/65">
                            <span class="text-xs md:text-sm font-bold uppercase tracking-widest mb-2"
                                  style="color: var(--color-accent);">
                                {{ $item->category }}
                            </span>
                            <h3 class="text-lg md:text-xl font-black leading-tight mb-2 text-white"
                                style="font-family: var(--font-display);">
                                {{ $item->title }}
                            </h3>
                            <p class="text-sm leading-snug text-white/80">
                                {{ $item->description }}
                            </p>
                        </div>
                    </{{ $tag }}>
                @endforeach
            </div>
        @endif
    </div>
</section>
