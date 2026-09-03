@php
    $editor = function ($field, $type) use ($pageId, $sectionIndex, $emitMarkers, $section) {
        if (! $emitMarkers) {
            return '';
        }
        $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";
        $sectionType = $section['type'] ?? '';

        return ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="'.e($sectionType).'" data-editable-field="'.e($field).'"';
    };

    $products = isset($site)
        ? app(\App\Services\Site\FeaturedProductsPicker::class)->productsFor($site, $section, $mode ?? 'public')
        : [];
    $n = count($products);
    $gridClass = match (true) {
        $n <= 2 => 'grid grid-cols-2 gap-4 sm:gap-6 max-w-full',
        $n === 3 => 'grid grid-cols-2 md:grid-cols-3 gap-4 sm:gap-6 max-w-full',
        default => 'grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 sm:gap-6 max-w-full',
    };
    $eyebrow = $section['eyebrow'] ?? 'Shop';
    // Defence in depth: the schema types cta_url as `link`, but stored data predating
    // that (or written around the schema) must still never reach an href as javascript:.
    $ctaUrl = \App\Services\Site\SectionSchema::isSafeLink($section['cta_url'] ?? null)
        ? trim($section['cta_url'])
        : '/shop';
@endphp
@if ($products !== [])
@include('shop.partials.product-card-styles')
    <div class="site-section-spacing relative overflow-hidden"
         data-featured-products-count="{{ $n }}"
         style="background-color: var(--color-surface);">
        <div class="site-shell-container px-4 sm:px-6 lg:px-8 relative">
            <div class="text-center mb-12">
                @if (!empty($section['title']))
                    @if (empty($section['__suppress_eyebrow']))
                        <span class="text-sm font-bold tracking-widest uppercase mb-3 block" style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                    @elseif ($emitMarkers)
                        <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                    @endif
                    <h2 class="text-3xl md:text-4xl font-extrabold" style="color: var(--color-text);"
                        {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
                @else
                    @if ($emitMarkers)
                        <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                        <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                    @endif
                @endif
                @if (!empty($section['subtitle']))
                    <p class="mt-3 text-lg md:text-xl max-w-2xl mx-auto leading-relaxed" style="color: var(--color-text-muted);"
                       {!! $editor('subtitle', 'plain') !!}>{{ $section['subtitle'] }}</p>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('subtitle', 'plain') !!}></span>
                @endif
            </div>

            <div class="{{ $gridClass }}">
                @foreach ($products as $product)
                    @include('shop.partials.product-card', ['product' => $product, 'site' => $site])
                @endforeach
            </div>

            @if (!empty($section['cta_label']))
                <div class="mt-10 text-center">
                    <a href="{{ $ctaUrl }}"
                       class="inline-flex items-center gap-2 font-bold px-8 py-3.5 shadow-md transition-all hover:shadow-lg hover:brightness-110"
                       style="background-color: var(--brand-accent); color: var(--color-text-on-accent, #ffffff); border-radius: var(--radius-button);">
                        <span{!! $editor('cta_label', 'plain') !!}>{{ $section['cta_label'] }}</span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                    </a>
                    @if ($emitMarkers)
                        <button type="button" class="hidden"{!! $editor('cta_url', 'url') !!}></button>
                    @endif
                </div>
            @elseif ($emitMarkers)
                <span class="hidden"{!! $editor('cta_label', 'plain') !!}></span>
                <span class="hidden"{!! $editor('cta_url', 'url') !!}></span>
            @endif
        </div>
    </div>
@endif
