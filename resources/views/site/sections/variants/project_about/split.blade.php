@php
    $aboutTitle = trim((string) ($section['title'] ?? ''));
    $aboutBody = trim((string) ($section['body'] ?? ''));
    $aboutEyebrow = trim((string) ($section['eyebrow'] ?? 'About this project'));
    $aboutMedia = null;
    $aboutImageId = $section['image_id'] ?? null;
    if ($aboutImageId !== null && isset($mediaById)) {
        $aboutMedia = $mediaById->get((int) $aboutImageId);
    }
@endphp
{{-- Precision-personality About: prose LEFT, photo RIGHT — the editorial two-column text belongs to the
     editorial personality, not here. Falls back to full-width prose
     when the section has no image. --}}
@if ($aboutBody !== '' || $emitMarkers)
<div class="py-16 lg:py-20" style="background-color: var(--color-surface);" data-project-about data-svc-variant="{{ $svcVariant }}">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 {{ $aboutMedia ? 'lg:grid-cols-2 gap-10 lg:gap-16 items-start' : '' }}">
            <div>
                {{-- Precision page-opening signature: accent top rule above
                     the eyebrow, matching intro/spec + story/document (and
                     the ruled gallery/essay headings). --}}
                <div class="pt-4 mb-5" style="border-top: 2px solid var(--brand-accent);">
                    <span class="text-xs font-bold tracking-[0.18em] uppercase" style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>{{ $aboutEyebrow }}</span>
                </div>
                @if ($aboutTitle !== '')
                    <h2 class="text-3xl md:text-4xl font-extrabold leading-[1.05] text-pretty"
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
                    <div class="text-lg leading-relaxed"
                         style="color: var(--color-text-muted);"
                         {!! $editor('body', 'plain') !!}>{{ $aboutBody }}</div>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('body', 'plain') !!}></span>
                @endif
            </div>
            @if ($aboutMedia)
                <figure class="overflow-hidden lg:sticky lg:top-24" style="background-color: var(--color-surface-alt);">
                    <img class="w-full aspect-[4/3] object-cover" src="{{ $aboutMedia->url }}?v={{ $aboutMedia->id }}" alt="{{ $aboutMedia->alt_text ?? '' }}" loading="lazy">
                </figure>
            @endif
        </div>
    </div>
</div>
@endif
