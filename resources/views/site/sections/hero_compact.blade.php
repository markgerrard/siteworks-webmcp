@php
    $editor = function ($field, $type, $valueDoc = null) use ($pageId, $sectionIndex, $emitMarkers, $section) {
        if (! $emitMarkers) {
            return '';
        }
        $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";
        $sectionType = $section['type'] ?? '';
        $attrs = ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="'.e($sectionType).'" data-editable-field="'.e($field).'"';
        if ($type === 'rich' && $valueDoc !== null) {
            $attrs .= ' data-editable-doc="'.e(json_encode($valueDoc)).'"';
        }

        return $attrs;
    };

    $heroHeight = '30vh';
    $heroMinHeight = '220px';
    $eyebrow = $section['eyebrow'] ?? null;
    $title = $section['title'] ?? '';
    $subtitle = $section['subtitle'] ?? '';
    $trustBullets = array_values(array_filter($section['trust_bullets'] ?? [], fn ($bullet) => is_string($bullet) && $bullet !== ''));

    $imageUrl = is_array($heroImageUrl ?? null)
        ? (($profile['watermark_enabled'] ?? true) && ! empty($heroImageUrl['watermark_url'])
            ? $heroImageUrl['watermark_url']
            : ($heroImageUrl['url'] ?? null))
        : $heroImageUrl;

    $hasImage = ! empty($imageUrl);
@endphp

<div class="relative overflow-hidden w-full"
     style="background: linear-gradient(135deg, var(--color-primary), var(--color-surface-alt));
            height: {{ $heroHeight }}; min-height: {{ $heroMinHeight }};">
    <div class="relative site-shell-container h-full px-4 sm:px-6 lg:px-8 grid {{ $hasImage ? 'grid-cols-1 md:grid-cols-[1fr_auto]' : 'grid-cols-1' }} gap-6 items-center">
        <div class="flex flex-col justify-center">
            @if ($eyebrow)
                <p class="text-sm font-semibold uppercase tracking-widest mb-3"
                   style="font-family: var(--font-body); color: var(--color-text-muted);"
                   {!! $editor('eyebrow', 'plain') !!}>
                    {{ $eyebrow }}
                </p>
            @elseif ($emitMarkers)
                <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
            @endif

            <h1 class="text-3xl md:text-4xl lg:text-5xl font-extrabold leading-tight text-pretty mb-3"
                style="color: var(--color-text);"
                {!! $editor('title', 'plain') !!}>
                {!! app(App\Services\Site\AccentWordRenderer::class)->wrap($title, $section['accent_word'] ?? null, isset($site) && \App\Support\ChromeKnobs::accentStyle($site) === 'italic' ? 'italic' : null, $section['accent_ranges'] ?? null) !!}
            </h1>

            @if ($subtitle)
                <p class="text-base md:text-lg max-w-2xl"
                   style="font-family: var(--font-body); color: var(--color-text-muted);"
                   {!! $editor('subtitle', 'plain') !!}>
                    {{ $subtitle }}
                </p>
            @elseif ($emitMarkers)
                <span class="hidden"{!! $editor('subtitle', 'plain') !!}></span>
            @endif

            @if (! empty($trustBullets))
                <ul class="mt-4 flex flex-wrap gap-x-6 gap-y-2 text-sm"
                    style="color: var(--color-text-muted);">
                    @foreach ($trustBullets as $index => $bullet)
                        <li class="flex items-center gap-1.5" {!! $editor("trust_bullets.{$index}", 'plain') !!}>
                            <svg class="w-4 h-4 flex-shrink-0" style="color: var(--color-accent-text);" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                            </svg>
                            {{ $bullet }}
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if ($hasImage)
            <div class="hidden md:block w-48 lg:w-56 h-full self-stretch relative overflow-hidden"
                 style="border-radius: var(--radius-card);">
                <img src="{{ $imageUrl }}" alt="{{ $title ?: $eyebrow ?: ($profile['name'] ?? 'Hero image') }}" class="w-full h-full object-cover object-center" loading="lazy" />
            </div>
        @endif
    </div>
</div>
