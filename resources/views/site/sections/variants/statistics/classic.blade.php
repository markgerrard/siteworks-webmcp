@php
    $countUp = (bool) ($section['__options']['stat_count_up'] ?? false);
    $statCount = count($items);
@endphp

<div class="site-section-spacing" style="background-color: var(--color-surface-alt);" data-stat-section>
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        @if (!empty($section['title']) || !empty($section['intro']) || (!empty($eyebrow) && empty($section['__suppress_eyebrow'])))
            <div class="text-center mb-12 sm:mb-16">
                @if (empty($section['__suppress_eyebrow']) && !empty($eyebrow))
                    <span class="text-sm font-bold tracking-widest uppercase mb-4 block"
                          style="color: var(--brand-accent-text);"
                          {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                @endif
                @if (!empty($section['title']))
@if (! empty($section['__options']['split_heading_reveal']))
                    @include('site.sections._split_heading', [
                        'section' => $section,
                        'sectionIndex' => $sectionIndex ?? ($section['__stored_index'] ?? 0),
                        'splitHeadingReveal' => true,
                        'class' => 'text-4xl md:text-5xl font-extrabold leading-tight text-balance',
                        'style' => 'color: var(--color-text-on-alt);',
                        'attrs' => $editor('title', 'plain'),
                    ])
@else
                    <h2 class="text-4xl md:text-5xl font-extrabold leading-tight text-balance"
                        style="color: var(--color-text-on-alt);"
                        {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
@endif
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                @endif
                @if (!empty($section['intro']))
                    <p class="mt-4 text-base sm:text-lg max-w-2xl mx-auto leading-relaxed"
                       style="color: var(--color-text-muted-on-alt);"
                       {!! $editor('intro', 'plain') !!}>{{ $section['intro'] }}</p>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('intro', 'plain') !!}></span>
                @endif
            </div>
        @else
            @if ($emitMarkers)
                <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                <span class="hidden"{!! $editor('intro', 'plain') !!}></span>
            @endif
        @endif

        @if (!empty($items))
            <div class="grid grid-cols-1 sm:grid-cols-2 {{ $statCount === 3 ? 'lg:grid-cols-3' : 'lg:grid-cols-4' }} gap-8 lg:gap-10">
                @foreach ($items as $i => $item)
                    @php
                        $val = (string) ($item['value'] ?? '');
                        $hasPrefix = !empty($item['prefix']);
                        $hasSuffix = !empty($item['suffix']);
                        $hasLabel = !empty($item['label']);
                        $hasDescription = !empty($item['description']);
                    @endphp
                    <div class="flex flex-col items-center text-center p-6 sm:p-8 rounded-lg shadow-sm"
                         style="background-color: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-card);">
                        <div class="text-4xl sm:text-5xl lg:text-6xl font-extrabold tracking-tight tabular-nums flex items-baseline justify-center gap-0.5"
                             style="color: var(--brand-primary-text);">
                            @if ($hasPrefix)
                                <span class="stat-prefix" {!! $editor("items.{$i}.prefix", 'plain') !!}>{{ $item['prefix'] }}</span>
                            @elseif ($emitMarkers)
                                <span class="hidden"{!! $editor("items.{$i}.prefix", 'plain') !!}></span>
                            @endif
                            <span data-stat-target="{{ $val }}"
                                  data-stat-final="{{ $val }}"
                                  {!! $editor("items.{$i}.value", 'plain') !!}>{{ $val }}</span>
                            @if ($hasSuffix)
                                <span class="stat-suffix" {!! $editor("items.{$i}.suffix", 'plain') !!}>{{ $item['suffix'] }}</span>
                            @elseif ($emitMarkers)
                                <span class="hidden"{!! $editor("items.{$i}.suffix", 'plain') !!}></span>
                            @endif
                        </div>
                        @if ($hasLabel)
                            <div class="text-base sm:text-lg font-bold mt-3 leading-snug"
                                 style="color: var(--color-text);"
                                 {!! $editor("items.{$i}.label", 'plain') !!}>{{ $item['label'] }}</div>
                        @elseif ($emitMarkers)
                            <span class="hidden"{!! $editor("items.{$i}.label", 'plain') !!}></span>
                        @endif
                        @if ($hasDescription)
                            <p class="text-sm mt-2 leading-relaxed"
                               style="color: var(--color-text-muted);"
                               {!! $editor("items.{$i}.description", 'plain') !!}>{{ $item['description'] }}</p>
                        @elseif ($emitMarkers)
                            <span class="hidden"{!! $editor("items.{$i}.description", 'plain') !!}></span>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
@if ($countUp && ! $emitMarkers)<script>
(function () {
    if (typeof window === 'undefined') return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    if (typeof IntersectionObserver === 'undefined') return;

    function animateValue(el) {
        var targetStr = el.getAttribute('data-stat-target') || '';
        var clean = targetStr.replace(/,/g, '').trim();
        var target = parseFloat(clean);
        if (isNaN(target)) return;

        var decimals = clean.indexOf('.') !== -1 ? (clean.split('.')[1] || '').length : 0;
        var hasCommas = targetStr.indexOf(',') !== -1 || target >= 1000;
        var finalText = el.getAttribute('data-stat-final') || targetStr;
        var duration = 1600;
        var startTime = null;

        function step(timestamp) {
            if (!startTime) startTime = timestamp;
            var progress = Math.min((timestamp - startTime) / duration, 1);
            var ease = 1 - Math.pow(1 - progress, 3);
            var current = target * ease;

            if (progress < 1) {
                var formatted = decimals > 0 ? current.toFixed(decimals) : Math.floor(current).toString();
                if (hasCommas) {
                    var parts = formatted.split('.');
                    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                    formatted = parts.join('.');
                }
                el.textContent = formatted;
                window.requestAnimationFrame(step);
            } else {
                el.textContent = finalText;
            }
        }

        window.requestAnimationFrame(step);
    }

    function init() {
        var sections = document.querySelectorAll('[data-stat-section]:not([data-stat-counted])');
        if (!sections.length) return;

        var observer = new IntersectionObserver(function (entries, obs) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    var section = entry.target;
                    obs.unobserve(section);
                    if (section.getAttribute('data-stat-counted') === 'true') return;
                    section.setAttribute('data-stat-counted', 'true');
                    var statEls = section.querySelectorAll('[data-stat-target]');
                    statEls.forEach(function (el) {
                        animateValue(el);
                    });
                }
            });
        }, { threshold: 0.15 });

        sections.forEach(function (section) {
            observer.observe(section);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
@endif
