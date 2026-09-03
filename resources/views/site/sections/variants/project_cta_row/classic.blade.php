@php
    $rawCtaUrl = $section['cta_url'] ?? null;
    $resolvedCtaUrl = ($rawCtaUrl === null || $rawCtaUrl === '#contact')
        ? ($pagesBySlug['contact'] ?? '#contact')
        : $rawCtaUrl;
@endphp
<section class="py-12 md:py-16" style="background-color: var(--color-surface); border-top: 1px solid var(--color-border);" data-project-cta-row data-svc-variant="{{ $svcVariant }}">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6">
            <div class="max-w-xl">
                @if (! empty($section['title']))
                    <h2 class="text-2xl md:text-3xl font-extrabold mb-2" style="color: var(--color-text); font-family: var(--font-display);" {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                @endif
                @if (! empty($section['body']))
                    <p class="text-base leading-relaxed" style="color: var(--color-text-muted);" {!! $editor('body', 'plain') !!}>{{ $section['body'] }}</p>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('body', 'plain') !!}></span>
                @endif
            </div>
            @if (! empty($section['cta_label']))
                <a href="{{ $resolvedCtaUrl }}"
                   class="inline-flex items-center gap-2 font-semibold shrink-0"
                   style="color: var(--brand-accent-text);">
                    <span{!! $editor('cta_label', 'plain') !!}>{{ $section['cta_label'] }}</span>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                </a>
                @if ($emitMarkers)
                    <button type="button" class="hidden"{!! $editor('cta_url', 'url') !!}></button>
                @endif
            @elseif ($emitMarkers)
                <span class="hidden"{!! $editor('cta_label', 'plain') !!}></span>
                <span class="hidden"{!! $editor('cta_url', 'url') !!}></span>
            @endif
        </div>
    </div>
</section>
