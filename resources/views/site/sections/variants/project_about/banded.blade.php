@php
    $aboutTitle = trim((string) ($section['title'] ?? ''));
    $aboutBody = trim((string) ($section['body'] ?? ''));
    $aboutEyebrow = trim((string) ($section['eyebrow'] ?? 'About this project'));
    $aboutMeta = array_filter([
        'Project type' => trim((string) ($section['project_type'] ?? '')),
        'Location' => trim((string) ($section['location'] ?? '')),
    ], fn ($v) => $v !== '');
@endphp
{{-- Banded-personality About: full-width surface-alt band, split-band
     5/7 — heading + checklist meta left, prose right. --}}
@if ($aboutBody !== '' || $emitMarkers)
<div class="py-16 lg:py-20" style="background-color: var(--color-surface-alt);" data-project-about data-svc-variant="{{ $svcVariant }}">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-16">
            <div class="lg:col-span-5">
                <span class="text-sm font-bold tracking-widest uppercase mb-5 block" style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>{{ $aboutEyebrow }}</span>
                @if ($aboutTitle !== '')
                    <h2 class="text-3xl md:text-4xl font-extrabold leading-[1.05] text-pretty"
                        style="color: var(--color-text); font-family: var(--font-display);"
                        {!! $editor('title', 'plain') !!}>{{ $aboutTitle }}</h2>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                @endif
                @if ($aboutMeta !== [])
                    <ul class="mt-8 space-y-3">
                        @foreach ($aboutMeta as $metaLabel => $metaValue)
                            <li class="flex items-baseline gap-3">
                                <span class="inline-block h-2.5 w-2.5 shrink-0 translate-y-px" style="background-color: var(--brand-accent);"></span>
                                <span class="text-xs font-bold uppercase tracking-[0.18em]" style="color: var(--color-text-muted);">{{ $metaLabel }}</span>
                                <span class="text-base font-semibold" style="color: var(--color-text);">{{ $metaValue }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div class="lg:col-span-7">
                <div class="mb-7 h-px" style="background-color: color-mix(in oklab, var(--color-text) 25%, transparent);"></div>
                @if ($aboutBody !== '')
                    <div class="text-lg leading-relaxed"
                         style="color: var(--color-text-muted);"
                         {!! $editor('body', 'plain') !!}>{{ $aboutBody }}</div>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('body', 'plain') !!}></span>
                @endif
            </div>
        </div>
    </div>
</div>
@endif
