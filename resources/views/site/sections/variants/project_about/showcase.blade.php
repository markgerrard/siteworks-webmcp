@php
    $aboutTitle = trim((string) ($section['title'] ?? ''));
    $aboutBody = trim((string) ($section['body'] ?? ''));
    $aboutEyebrow = trim((string) ($section['eyebrow'] ?? 'About this project'));
    $aboutMedia = null;
    $aboutImageId = $section['image_id'] ?? null;
    if ($aboutImageId !== null && isset($mediaById)) {
        $aboutMedia = $mediaById->get((int) $aboutImageId);
    }
    $aboutMeta = array_filter([
        'Project type' => trim((string) ($section['project_type'] ?? '')),
        'Location' => trim((string) ($section['location'] ?? '')),
    ], fn ($v) => $v !== '');
@endphp
{{-- Showcase-personality About: image-led card panel — photo LEFT on a
     surface-alt panel, meta as bordered chips. Text-only panel when the
     section has no image. --}}
@if ($aboutBody !== '' || $emitMarkers)
<div class="py-16 lg:py-20" style="background-color: var(--color-surface);" data-project-about data-svc-variant="{{ $svcVariant }}">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        <div class="p-6 sm:p-10 lg:p-12" style="background-color: var(--color-surface-alt);">
            <div class="grid grid-cols-1 {{ $aboutMedia ? 'lg:grid-cols-12 gap-10 lg:gap-14 items-start' : '' }}">
                @if ($aboutMedia)
                    <figure class="lg:col-span-5 overflow-hidden">
                        <img class="w-full aspect-[4/5] object-cover" src="{{ $aboutMedia->url }}?v={{ $aboutMedia->id }}" alt="{{ $aboutMedia->alt_text ?? '' }}" loading="lazy">
                    </figure>
                @endif
                <div class="{{ $aboutMedia ? 'lg:col-span-7' : '' }}">
                    <span class="text-sm font-bold tracking-widest uppercase mb-5 block" style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>{{ $aboutEyebrow }}</span>
                    @if ($aboutTitle !== '')
                        <h2 class="text-3xl md:text-4xl font-extrabold leading-[1.05] text-pretty"
                            style="color: var(--color-text); font-family: var(--font-display);"
                            {!! $editor('title', 'plain') !!}>{{ $aboutTitle }}</h2>
                    @elseif ($emitMarkers)
                        <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                    @endif
                    @if ($aboutMeta !== [])
                        <div class="mt-6 flex flex-wrap gap-2">
                            @foreach ($aboutMeta as $metaLabel => $metaValue)
                                <span class="inline-flex items-baseline gap-2 rounded-full px-4 py-1.5 text-xs" style="border: 1px solid var(--color-border);">
                                    <span class="font-bold uppercase tracking-[0.14em]" style="color: var(--color-text-muted);">{{ $metaLabel }}</span>
                                    <span class="font-semibold" style="color: var(--color-text);">{{ $metaValue }}</span>
                                </span>
                            @endforeach
                        </div>
                    @endif
                    <div class="mt-7 mb-7 h-px" style="background-color: color-mix(in oklab, var(--color-text) 25%, transparent);"></div>
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
</div>
@endif
