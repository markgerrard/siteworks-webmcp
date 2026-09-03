@php
    $aboutTitle = trim((string) ($section['title'] ?? ''));
    $aboutBody = trim((string) ($section['body'] ?? ''));
    $aboutEyebrow = trim((string) ($section['eyebrow'] ?? 'About this project'));
    $aboutMeta = array_filter([
        'Project type' => trim((string) ($section['project_type'] ?? '')),
        'Location' => trim((string) ($section['location'] ?? '')),
    ], fn ($v) => $v !== '');
@endphp
{{-- Editorial-personality About: ledger grammar — accent-ruled eyebrow,
     meta as hairline ledger rows, prose always two-column on md+. --}}
@if ($aboutBody !== '' || $emitMarkers)
<div class="py-16 lg:py-20" style="background-color: var(--color-surface);" data-project-about data-svc-variant="{{ $svcVariant }}">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        <div class="pt-4 mb-5" style="border-top: 2px solid var(--brand-accent);">
            <span class="text-xs font-bold tracking-[0.18em] uppercase" style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>{{ $aboutEyebrow }}</span>
        </div>
        @if ($aboutTitle !== '')
            <h2 class="text-3xl md:text-4xl font-extrabold leading-[1.05] max-w-4xl text-pretty"
                style="color: var(--color-text); font-family: var(--font-display);"
                {!! $editor('title', 'plain') !!}>{{ $aboutTitle }}</h2>
        @elseif ($emitMarkers)
            <span class="hidden"{!! $editor('title', 'plain') !!}></span>
        @endif
        @if ($aboutMeta !== [])
            <dl class="mt-8">
                @foreach ($aboutMeta as $metaLabel => $metaValue)
                    <div class="flex items-baseline justify-between gap-6 py-3" style="border-top: 1px solid var(--color-border);">
                        <dt class="text-xs font-bold uppercase tracking-[0.18em]" style="color: var(--color-text-muted);">{{ $metaLabel }}</dt>
                        <dd class="text-base font-semibold text-right" style="color: var(--color-text);">{{ $metaValue }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
        <div class="{{ $aboutMeta !== [] ? 'mt-3' : 'mt-8' }} mb-7 h-px" style="background-color: color-mix(in oklab, var(--color-text) 25%, transparent);"></div>
        @if ($aboutBody !== '')
            <div class="text-lg leading-relaxed max-w-none md:columns-2 md:gap-14"
                 style="color: var(--color-text-muted);"
                 {!! $editor('body', 'plain') !!}>{{ $aboutBody }}</div>
        @elseif ($emitMarkers)
            <span class="hidden"{!! $editor('body', 'plain') !!}></span>
        @endif
    </div>
</div>
@endif
