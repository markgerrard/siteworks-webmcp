@php
    $svcVariant = 'classic';
    if (is_string($section['variant'] ?? null) && preg_match('/^[a-z0-9-]+$/', $section['variant'])) {
        $svcVariant = $section['variant'];
    }
    $svcVariantView = "site.sections.variants.portfolio_strip.{$svcVariant}";
    if (! view()->exists($svcVariantView)) {
        $svcVariantView = 'site.sections.variants.portfolio_strip.classic';
    }
@endphp
@include($svcVariantView)
