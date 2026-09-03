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

    // The icon sits next to a visible <h3> title so it is decorative for
    // screen readers — empty alt + aria-hidden avoids duplicate announcements.
    $renderIcon = function ($icon, $classes = 'w-5 h-5') {
        if (empty($icon)) {
            return '';
        }
        if (is_int($icon) || (is_string($icon) && ctype_digit((string) $icon))) {
            return '';
        }
        if (is_string($icon) && preg_match('/^https?:\/\//i', $icon)) {
            return '<img src="'.e($icon).'" alt="" aria-hidden="true" class="'.$classes.' object-contain">';
        }
        if (is_string($icon) && preg_match('/^[a-z][a-z0-9-]*$/i', $icon)) {
            return '<i data-lucide="'.e($icon).'" aria-hidden="true" class="'.$classes.'"></i>';
        }

        // Emoji or other printable string — render as plain text span
        return '<span aria-hidden="true" class="'.$classes.' flex items-center justify-center text-2xl leading-none">'.e($icon).'</span>';
    };

    $eyebrow  = $section['eyebrow'] ?? null;
    $title    = $section['title'] ?? null;
    $subtitle = $section['subtitle'] ?? null;
    $items    = is_array($section['items'] ?? null) ? array_values($section['items']) : [];
@endphp

@if (!empty($title) || !empty($items))
    <div class="site-section-spacing" style="background-color: var(--color-surface);">
        <div class="site-shell-container px-4 sm:px-6 lg:px-8">

            {{-- Section header --}}
            <div class="text-center mb-12">
                @if (!empty($eyebrow))
                    <span class="text-sm font-semibold uppercase tracking-wider mb-3 block"
                          style="color: var(--brand-accent);"
                          {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                @endif

                @if (!empty($title))
                    <h2 class="text-3xl md:text-4xl font-extrabold leading-tight text-balance"
                        style="color: var(--color-primary);"
                        {!! $editor('title', 'plain') !!}>{{ $title }}</h2>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                @endif

                @if (!empty($subtitle))
                    <p class="mt-4 text-lg max-w-2xl mx-auto leading-relaxed"
                       style="color: var(--color-text-muted);"
                       {!! $editor('subtitle', 'plain') !!}>{{ $subtitle }}</p>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('subtitle', 'plain') !!}></span>
                @endif
            </div>

            {{-- Capability cards grid --}}
            @if (!empty($items))
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 lg:gap-8">
                    @foreach ($items as $i => $item)
                        @php
                            $iconHtml      = $renderIcon($item['icon'] ?? null, 'w-10 h-10');
                            $screenshotUrl = $item['screenshot_url'] ?? null;
                        @endphp
                        <div class="flex flex-col rounded-2xl p-8 transition-shadow duration-300 hover:shadow-lg"
                             style="background-color: var(--color-surface); border: 1px solid var(--color-border);">

                            {{-- Icon --}}
                            @if (!empty($iconHtml))
                                <div class="mb-5 w-12 h-12 flex items-center justify-center flex-shrink-0"
                                     style="color: var(--brand-accent);">
                                    {!! $iconHtml !!}
                                </div>
                            @endif

                            {{-- Title --}}
                            <h3 class="text-xl font-semibold mb-3 leading-snug"
                                style="color: var(--color-primary);"
                                {!! $editor("items.{$i}.title", 'plain') !!}>{{ $item['title'] ?? '' }}</h3>

                            {{-- Body --}}
                            @if (!empty($item['body']))
                                <p class="text-base leading-relaxed flex-1"
                                   style="color: var(--color-text-muted);"
                                   {!! $editor("items.{$i}.body", 'plain') !!}>{{ $item['body'] }}</p>
                            @endif

                            {{-- Optional per-card screenshot thumbnail --}}
                            @if (!empty($screenshotUrl))
                                <div class="mt-5 overflow-hidden rounded-lg"
                                     style="border: 1px solid var(--color-border);">
                                    <img src="{{ $screenshotUrl }}"
                                         alt=""
                                         aria-hidden="true"
                                         loading="lazy"
                                         class="w-full object-cover"
                                         style="max-height: 8rem;">
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

        </div>
    </div>
@endif
