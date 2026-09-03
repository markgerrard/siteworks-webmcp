@php
    $groups = [];
    foreach ($items as $item) {
        $norm = mb_strtolower(trim((string) $item->category));
        if ($norm === '') {
            continue;
        }
        $groups[$norm] ??= [
            'label' => trim((string) $item->category),
            'slug' => \Illuminate\Support\Str::slug($norm),
            'count' => 0,
        ];
        $groups[$norm]['count']++;
    }
    $seen = [];
    foreach ($groups as $k => &$g) {
        if (isset($seen[$g['slug']])) {
            $g['slug'] .= '-'.(++$seen[$g['slug']]);
        } else {
            $seen[$g['slug']] = 1;
        }
    }
    unset($g);

    $countLines = [
        'all' => $vocab?->countLine($items->count(), $items) ?? "Showing {$items->count()} projects",
    ];
    foreach ($groups as $g) {
        $countLines[$g['slug']] = $vocab?->countLine($g['count'], $items) ?? "Showing {$g['count']} projects";
    }

    $gridClasses = $items->count() >= 9
        ? 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 md:gap-8'
        : 'grid grid-cols-1 sm:grid-cols-2 gap-6 md:gap-10';
@endphp
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
            <div x-data="{ cat: 'all', lines: {{ \Illuminate\Support\Js::from($countLines) }} }">
                @if (count($groups) > 1)
                    <div role="group" aria-label="Filter {{ $vocab?->allLabel($items) ?? 'projects' }}" x-cloak
                         class="flex flex-wrap gap-x-6 gap-y-2 mb-4"
                         style="font-family: var(--font-display); color: var(--color-text);">
                        <button type="button" data-cat="all" @click="cat = $el.dataset.cat" :aria-pressed="cat === $el.dataset.cat"
                                class="uppercase tracking-[0.18em] border-b-2 pb-1 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                                :class="cat === $el.dataset.cat ? 'border-current' : 'border-transparent'">
                            All {{ $items->count() }}
                        </button>
                        @foreach ($groups as $g)
                            <button type="button" data-cat="{{ $g['slug'] }}" @click="cat = $el.dataset.cat" :aria-pressed="cat === $el.dataset.cat"
                                    class="uppercase tracking-[0.18em] border-b-2 pb-1 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                                    :class="cat === $el.dataset.cat ? 'border-current' : 'border-transparent'">
                                {{ mb_strtoupper($g['label']) }} {{ $g['count'] }}
                            </button>
                        @endforeach
                    </div>
                @endif
                <p aria-live="polite"
                   class="text-sm mb-8"
                   style="color: var(--color-text-muted); font-family: var(--font-display);"
                   x-text="lines[cat]">{{ $countLines['all'] }}</p>
                <div class="{{ $gridClasses }}">
                    @foreach ($items as $item)
                        @php
                            $norm = mb_strtolower(trim((string) $item->category));
                            $itemSlug = $norm === '' ? '' : ($groups[$norm]['slug'] ?? '');
                            $href = $detailHrefFor($item);
                            $tag = $href ? 'a' : 'article';
                        @endphp
                        <{{ $tag }}@if ($href) href="{{ $href }}"@endif data-cat="{{ $itemSlug }}"
                                 :class="{ hidden: cat !== 'all' && cat !== $el.dataset.cat }"@if ($href) class="block"@endif>
                            <div class="relative overflow-hidden aspect-[4/5]"
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
                                    <img class="w-full h-full object-cover"
                                         src="{{ $item->image->url }}?v={{ $item->image->id }}"
                                         alt="{{ $item->image->alt_text ?? $item->title }}"
                                         loading="lazy">
                                @else
                                    <div class="w-full h-full flex items-end p-6"
                                         style="background-color: var(--color-surface-alt);">
                                        <span class="text-xs font-bold uppercase tracking-widest"
                                              style="color: var(--color-text-muted-on-alt);">
                                            {{ $item->category }}
                                        </span>
                                    </div>
                                @endif
                            </div>
                            <div class="flex justify-between text-xs uppercase tracking-[0.18em] mt-3"
                                 style="color: var(--color-text); font-family: var(--font-display);">
                                <span>{{ $item->title }}</span>
                                <span>{{ $item->category }}</span>
                            </div>
                        </{{ $tag }}>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</section>
