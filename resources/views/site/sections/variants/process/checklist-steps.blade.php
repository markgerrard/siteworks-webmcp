{{-- Showcase checklist-steps on process: shipped features/checklist chrome
     (elevated surface-alt card, brand check circles) applied to process
     steps. Circles hold CHECKS not numbers; step copy stays as text.
     Text/item only in v1 — no image pane, no image_radius.
     Dispatcher passes the full item list; this variant does not clamp. --}}
@php
    $items = array_values($section['items'] ?? []);
    $title = (string) ($section['title'] ?? '');
    $hideEyebrow = $title === '' || ! empty($section['__suppress_eyebrow']);
    // When __surface is contrast the wrapper is a different background
    // from its neighbours, so full site-section-spacing applies (the
    // background change absorbs the seam). Absent = kit surface wrapper.
    $isContrast = ($section['__surface'] ?? null) === 'contrast';
    $wrapperBg = $isContrast ? 'var(--color-surface-contrast)' : 'var(--color-surface)';
    $textOnWrapper = $isContrast ? 'var(--color-text-on-contrast)' : 'var(--color-text)';
    $accentOnWrapper = $isContrast ? 'var(--brand-accent-text-on-contrast)' : 'var(--brand-accent-text)';
@endphp
@if ($items !== [])
    <div data-svc-variant="checklist-steps" class="site-section-spacing" style="background-color: {{ $wrapperBg }};">
        <div class="site-shell-container px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl mb-6">
                @if (! $hideEyebrow)
                    <span class="text-sm font-bold tracking-widest uppercase mb-3 block" style="color: {{ $accentOnWrapper }};" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                @endif
                @if ($title !== '')
                    <h2 class="text-3xl md:text-4xl font-extrabold text-pretty" style="color: {{ $textOnWrapper }};"
                        {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                @endif
            </div>
            <div class="grid grid-cols-1 shadow-xl" style="background-color: var(--color-surface-alt);">
                <div class="px-7 py-10 lg:px-12 lg:py-12 flex flex-col justify-center">
                    @foreach ($items as $i => $item)
                        <div class="grid grid-cols-[2rem_1fr] gap-4 py-4 {{ $i > 0 ? 'border-t' : '' }}" style="border-color: color-mix(in oklab, var(--color-text-on-alt) 12%, transparent);">
                            <span class="w-6 h-6 rounded-full flex items-center justify-center mt-0.5" style="background-color: var(--brand-primary); color: #ffffff;">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            </span>
                            <div>
                                @if (($item['step'] ?? '') !== '' && ($item['step'] ?? null) !== null)
                                    <span class="text-sm font-bold tracking-widest uppercase mb-1 block" style="color: var(--brand-accent-text);"
                                          {!! $editor("items.{$i}.step", 'plain') !!}>{{ $item['step'] }}</span>
                                @elseif ($emitMarkers)
                                    <span class="hidden"{!! $editor("items.{$i}.step", 'plain') !!}></span>
                                @endif
                                <h3 class="text-base md:text-lg font-bold" style="color: var(--color-text-on-alt);"
                                    {!! $editor("items.{$i}.title", 'plain') !!}>{{ $item['title'] ?? '' }}</h3>
                                @if (!empty($item['body'] ?? null))
                                    <div class="mt-1 text-sm md:text-base leading-relaxed prose prose-base max-w-none" style="color: var(--color-text-muted-on-alt);"
                                         {!! $editor("items.{$i}.body", 'rich', is_array($item['body'] ?? null) ? $item['body'] : null) !!}>{!! $richHtml($item['body'] ?? '') !!}</div>
                                @elseif ($emitMarkers)
                                    <span class="hidden"{!! $editor("items.{$i}.body", 'rich') !!}></span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
@endif
