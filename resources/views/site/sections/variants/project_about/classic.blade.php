@php
    $aboutTitle = trim((string) ($section['title'] ?? ''));
    $aboutBody = trim((string) ($section['body'] ?? ''));
    $aboutEyebrow = trim((string) ($section['eyebrow'] ?? 'About this project'));
    // Mirror the service page's About band grammar exactly (eyebrow,
    // heading, hairline, prose; two columns when the copy earns them).
    $twoCol = mb_strlen($aboutBody) > 420;
@endphp
@if ($aboutBody !== '' || $emitMarkers)
<div class="py-16 lg:py-20" style="background-color: var(--color-surface);" data-project-about data-svc-variant="{{ $svcVariant }}">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        <span class="text-sm font-bold tracking-widest uppercase mb-5 block" style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>{{ $aboutEyebrow }}</span>
        @if ($aboutTitle !== '')
            <h2 class="text-3xl md:text-4xl font-extrabold leading-[1.05] max-w-4xl text-pretty"
                style="color: var(--color-text); font-family: var(--font-display);"
                {!! $editor('title', 'plain') !!}>{{ $aboutTitle }}</h2>
        @elseif ($emitMarkers)
            <span class="hidden"{!! $editor('title', 'plain') !!}></span>
        @endif
        @php
            $aboutMeta = array_filter([
                'Project type' => trim((string) ($section['project_type'] ?? '')),
                'Location' => trim((string) ($section['location'] ?? '')),
            ], fn ($v) => $v !== '');
        @endphp
        @if ($aboutMeta !== [])
            <div class="mt-8 flex flex-wrap gap-x-14 gap-y-4">
                @foreach ($aboutMeta as $metaLabel => $metaValue)
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.18em] mb-1" style="color: var(--color-text-muted);">{{ $metaLabel }}</p>
                        <p class="text-base font-semibold" style="color: var(--color-text);">{{ $metaValue }}</p>
                    </div>
                @endforeach
            </div>
        @endif
        <div class="{{ $aboutMeta !== [] ? 'mt-7' : 'mt-8' }} mb-7 h-px" style="background-color: color-mix(in oklab, var(--color-text) 25%, transparent);"></div>
        @if ($aboutBody !== '')
            <div class="text-lg leading-relaxed max-w-none {{ $twoCol ? 'md:columns-2 md:gap-14' : 'max-w-3xl' }}"
                 style="color: var(--color-text-muted);"
                 {!! $editor('body', 'plain') !!}>{{ $aboutBody }}</div>
        @elseif ($emitMarkers)
            <span class="hidden"{!! $editor('body', 'plain') !!}></span>
        @endif
    </div>
</div>
@endif
