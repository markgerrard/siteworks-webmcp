@php
    /**
     * Featured-projects dark band — Showcase home-layout variant.
     * Sources real ProjectItem rows via $section['item_ids'] (hydrated into
     * $itemsById by PageRenderer's batch-load), unlike the light strip which
     * reads the never-emitted profile['portfolio_images']. Renders nothing
     * when no referenced item has an image.
     */
    $bandItems = collect($section['item_ids'] ?? [])
        ->map(fn ($id) => ($itemsById ?? collect())->get((int) $id))
        ->filter(fn ($item) => $item && $item->image?->url)
        ->take(3)
        ->values();

    $eyebrow = $section['eyebrow'] ?? 'Our work';
    $title = $section['title'] ?? 'Featured projects';
    $projectsHref = $pagesBySlug['projects'] ?? null;
@endphp

@if ($bandItems->isNotEmpty())
    <div class="site-section-spacing" data-portfolio-variant="dark-band" style="background-color: var(--color-band);">
        <div class="site-shell-container px-6">
            <div class="text-center mb-10">
                @if (empty($section['__suppress_eyebrow']))
                    <p class="text-sm font-semibold uppercase tracking-widest mb-2" style="color: var(--brand-accent);">{{ $eyebrow }}</p>
                @endif
                <h2 class="text-3xl md:text-4xl font-bold" style="color: var(--color-text-on-band); font-family: var(--font-display); letter-spacing: var(--heading-letter-spacing);">{{ $title }}</h2>
            </div>
            <div class="grid gap-6 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($bandItems as $item)
                    <div class="group overflow-hidden" style="border-radius: var(--radius-card);">
                        <div class="aspect-[4/3]">
                            {{-- ?v=<media_id> busts browser cache when regen
                                 swaps the file at the deterministic path
                                 (same pattern as project_gallery.blade.php). --}}
                            <img src="{{ $item->image->url }}?v={{ $item->image->id }}" alt="{{ $item->title }}" loading="lazy" class="w-full h-full object-cover transition duration-700 ease-out group-hover:scale-105">
                        </div>
                        <div class="py-4">
                            <h3 class="font-semibold" style="color: var(--color-text-on-band);">{{ $item->title }}</h3>
                            @if (!empty($item->category))
                                <p class="text-sm mt-1" style="color: var(--color-text-on-band); opacity: 0.7;">{{ $item->category }}</p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
            @if ($projectsHref)
                <div class="text-center mt-10">
                    <a href="{{ $projectsHref }}"
                       class="inline-block px-8 py-3 font-semibold"
                       style="background-color: var(--brand-accent); color: var(--color-text-on-accent, #ffffff); border-radius: var(--radius-button);">
                        View portfolio
                    </a>
                </div>
            @endif
        </div>
    </div>
@endif
