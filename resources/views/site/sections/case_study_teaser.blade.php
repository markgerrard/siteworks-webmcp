@php
    $editor = function ($field, $type) use ($pageId, $sectionIndex, $emitMarkers, $section) {
        if (! $emitMarkers) {
            return '';
        }
        $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";
        $sectionType = $section['type'] ?? '';
        return ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="'.e($sectionType).'" data-editable-field="'.e($field).'"';
    };

    // Premium-specialist flavour: a single featured case study with a
    // prominent pull-quote. Content-driven — reads from $section only
    // (no profiler-sourced fallback). Renders nothing if the admin / AI
    // hasn't supplied at minimum a title + body.
    $title = $section['title'] ?? null;
    $body = $section['body'] ?? null;
    $client = $section['client'] ?? null;
    $stat = $section['stat'] ?? null;
    $statLabel = $section['stat_label'] ?? null;
    $imageUrl = $section['image_url'] ?? null;

    $hasContent = is_string($title) && trim($title) !== ''
        && is_string($body) && trim($body) !== '';
    $eyebrow = $section['eyebrow'] ?? 'Featured project';
@endphp

@if ($hasContent)
    <div class="site-section-spacing" style="background-color: var(--color-surface-alt);">
        <div class="site-shell-container px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-10">
                <p class="text-xs font-bold tracking-widest uppercase mb-2" style="color: var(--brand-accent-text-on-alt);" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</p>
            </div>
            <div class="grid gap-8 md:gap-12 {{ $imageUrl ? 'md:grid-cols-2' : 'md:grid-cols-1' }} md:items-center max-w-5xl mx-auto">
                @if (is_string($imageUrl) && preg_match('#^https?://#i', $imageUrl))
                    <div class="overflow-hidden aspect-[4/3]"
                         style="border: 1px solid var(--color-border); border-radius: var(--radius-card);">
                        <img src="{{ $imageUrl }}" alt="{{ $title }}"
                             class="w-full h-full object-cover" loading="lazy" />
                    </div>
                @endif
                <div class="{{ $imageUrl ? '' : 'text-center max-w-3xl mx-auto' }}">
                    <h3 class="text-2xl md:text-3xl font-extrabold mb-4 leading-tight" style="color: var(--color-text-on-alt);">
                        {{ $title }}
                    </h3>
                    @if (is_string($client) && trim($client) !== '')
                        <p class="text-sm font-semibold mb-4" style="color: var(--brand-accent-text-on-alt);">
                            — {{ $client }}
                        </p>
                    @endif
                    <p class="text-base md:text-lg leading-relaxed mb-5" style="color: var(--color-text-on-alt);">
                        {{ $body }}
                    </p>
                    @if ($stat !== null)
                        <div class="inline-flex items-baseline gap-2 p-4"
                             style="background-color: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-card);">
                            <span class="text-3xl md:text-4xl font-extrabold leading-none" style="color: var(--brand-primary-text);">
                                {{ $stat }}
                            </span>
                            @if (is_string($statLabel) && trim($statLabel) !== '')
                                <span class="text-sm" style="color: var(--color-text-muted);">
                                    {{ $statLabel }}
                                </span>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endif
