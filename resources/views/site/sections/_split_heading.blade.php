@php
    $headingText = (isset($section) && is_array($section))
        ? ($section['title'] ?? ($section['heading'] ?? ($title ?? ($heading ?? ''))))
        : ($title ?? ($heading ?? ''));
    $tag = $tag ?? 'h2';

    $isSplit = false;
    if (isset($splitHeadingReveal)) {
        $isSplit = (bool) $splitHeadingReveal;
    } elseif (isset($splitReveal)) {
        $isSplit = (bool) $splitReveal;
    } elseif (isset($split)) {
        $isSplit = (bool) $split;
    } elseif (isset($section['__options']['split_heading_reveal'])) {
        $isSplit = (bool) $section['__options']['split_heading_reveal'];
    } elseif (isset($section['options']['split_heading_reveal'])) {
        $isSplit = (bool) $section['options']['split_heading_reveal'];
    } elseif (isset($section['split_heading_reveal'])) {
        $isSplit = (bool) $section['split_heading_reveal'];
    } elseif (isset($options['split_heading_reveal'])) {
        $isSplit = (bool) $options['split_heading_reveal'];
    }

    $classVal = $class ?? $headingClass ?? null;
    $classAttr = ! empty($classVal) ? ' class="'.e(trim($classVal)).'"' : '';

    $styleVal = $style ?? $headingStyle ?? null;
    $styleAttr = ! empty($styleVal) ? ' style="'.e(trim($styleVal)).'"' : '';

    $rawAttrs = $attrs ?? $attributes ?? '';
    $attrsFormatted = is_string($rawAttrs) && trim($rawAttrs) !== '' ? ' '.ltrim($rawAttrs) : '';
@endphp
@if (! $isSplit)
<{{ $tag }}{!! $classAttr !!}{!! $styleAttr !!}{!! $attrsFormatted !!}>{{ $headingText }}</{{ $tag }}>
@else
@php
    $words = preg_split('/\s+/', trim((string) $headingText));
    if ($words === false || $words === [''] || $words === []) {
        $words = [];
    }
    $splitIndex = $sectionIndex ?? ($section['__stored_index'] ?? ($section['index'] ?? ($index ?? 0)));
    $splitId = 'sh-'.$splitIndex;
@endphp
@if ($words === [])
<{{ $tag }}{!! $classAttr !!}{!! $styleAttr !!}{!! $attrsFormatted !!}></{{ $tag }}>
@else
<{{ $tag }}{!! $classAttr !!}{!! $styleAttr !!}{!! $attrsFormatted !!} data-split-heading data-split-heading-id="{{ $splitId }}">
@foreach ($words as $i => $word)<span class="split-word inline-block">{{ $word }}</span>{{ $loop->last ? '' : ' ' }}@endforeach
</{{ $tag }}>
<script>
(function () {
    if (typeof window === 'undefined') return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    if (!('IntersectionObserver' in window)) return;

    var self = document.currentScript;
    var heading = self ? self.previousElementSibling : null;
    if (!heading || !heading.hasAttribute('data-split-heading')) return;
    if (!heading || heading.hasAttribute('data-split-heading-init')) return;
    heading.setAttribute('data-split-heading-init', 'true');

    var words = heading.querySelectorAll('.split-word');
    if (!words.length) return;

    words.forEach(function (word, index) {
        word.style.opacity = '0';
        word.style.transform = 'translateY(0.75rem)';
        word.style.transition = 'opacity 0.6s cubic-bezier(0.16, 1, 0.3, 1), transform 0.6s cubic-bezier(0.16, 1, 0.3, 1)';
        word.style.transitionDelay = (index * 40) + 'ms';
        word.style.willChange = 'opacity, transform';
    });

    function reveal() {
        words.forEach(function (word) {
            word.style.opacity = '1';
            word.style.transform = 'translateY(0)';
        });
    }

    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                reveal();
                observer.disconnect();
            }
        });
    }, {
        threshold: 0.15,
        rootMargin: '0px 0px -50px 0px'
    });

    observer.observe(heading);

    setTimeout(function () {
        reveal();
        observer.disconnect();
    }, 2500);
})();
</script>
@endif
@endif
