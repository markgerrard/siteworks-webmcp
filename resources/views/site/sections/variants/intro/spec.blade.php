{{-- Precision spec intro: utilitarian, no imagery. Accent top rule, meta
     row, heading + bordered prose column. Title renders verbatim. --}}
<div data-svc-variant="spec" class="pt-16 lg:pt-20 pb-8 lg:pb-10" style="background-color: var(--color-surface);">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        <div class="flex items-baseline justify-between gap-6 pt-4 mb-10" style="border-top: 2px solid var(--brand-accent);">
            @if (empty($section['__suppress_eyebrow']))
                <span class="text-xs font-bold tracking-[0.18em] uppercase" style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
            @elseif ($emitMarkers)
                <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
            @endif
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-10 lg:gap-14 items-start">
            <div class="lg:col-span-2">
                @if (!empty($section['title']))
                    <h2 class="text-3xl md:text-4xl font-extrabold leading-tight text-pretty" style="color: var(--color-text);"
                        {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                @endif
                {{-- Optional supporting image, left column under the title
                     Precision chrome: sharp corners,
                     hairline frame. Absent image = the original no-image
                     composition, unchanged. --}}
                @if (!empty($introImg))
                    <figure class="mt-8 overflow-hidden" style="border: 1px solid color-mix(in oklab, var(--color-text-muted) 30%, transparent); border-radius: {{ (($section['__options']['image_radius'] ?? null) === 'soft') ? 'var(--radius-card)' : '0' }};">
                        <div style="aspect-ratio: 4 / 3;">
                            <img src="{{ $introImg }}" alt="{{ $section['title'] ?? 'Service detail' }}"
                                 class="w-full h-full object-cover" loading="lazy">
                        </div>
                    </figure>
                @endif
            </div>
            <div class="lg:col-span-3 lg:pl-10 lg:border-l" style="border-left-color: color-mix(in oklab, var(--color-text-muted) 30%, transparent);">
                @if (!empty($section['body']))
                    <div class="space-y-4 text-base leading-relaxed prose max-w-none [&>p]:mb-0"
                         style="color: var(--color-text-muted);"
                         {!! $editor('body', 'rich', is_array($section['body']) ? $section['body'] : null) !!}>{!! $richHtml($section['body']) !!}</div>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('body', 'rich') !!}></span>
                @endif
            </div>
        </div>
    </div>
</div>
