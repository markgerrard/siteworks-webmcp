{{-- Editorial ink ledger: numbered-rows sibling. Headline stack + ghost
     CTA left; 01–0N ledger right. Consumes surfaces value `contrast` only
     (numbered-rows swap set); unstamped it renders the same layout on
     base tokens. No intro slot — every item is a ledger row. --}}
@php
    $items = array_values($items ?? []);
    $pagesBySlug = $pagesBySlug ?? [];
    $contactHref = $pagesBySlug['contact'] ?? '#contact';
    $eyebrow = $section['eyebrow'] ?? 'Why Choose Us';
    $title = (string) ($section['title'] ?? '');
    $titleMatchesEyebrow = $title !== '' && strcasecmp(trim($title), $eyebrow) === 0;
    $hideEyebrow = $titleMatchesEyebrow || ! empty($section['__suppress_eyebrow']);
    $isContrast = ($section['__surface'] ?? null) === 'contrast';
    $wrapperBg = $isContrast ? 'var(--color-surface-contrast)' : 'var(--color-surface)';
    $textOnWrapper = $isContrast ? 'var(--color-text-on-contrast)' : 'var(--color-text)';
    $mutedOnWrapper = $isContrast ? 'var(--color-text-muted-on-contrast)' : 'var(--color-text-muted)';
    $accentOnWrapper = $isContrast ? 'var(--brand-accent-text-on-contrast)' : 'var(--brand-accent-text)';
    $hairlineBase = $isContrast ? 'var(--color-text-on-contrast)' : 'var(--color-text)';
    $hairline = "color-mix(in oklab, {$hairlineBase} 16%, transparent)";
    $titleSentences = $title === '' ? [] : (preg_split('/(?<=[.!?])\s+/', $title) ?: [$title]);
    $titleLast = $titleSentences === [] ? '' : (string) array_pop($titleSentences);
    $titleLead = implode(' ', $titleSentences);
    $titleHtml = ($titleLead !== '' ? e($titleLead).' ' : '').'<em style="font-style: italic;">'.e($titleLast).'</em>';
@endphp
@if ($items !== [])
    <div data-svc-variant="ink-ledger"
         @class(['site-section-spacing' => $isContrast, 'pt-10 lg:pt-12' => ! $isContrast])
         style="background-color: {{ $wrapperBg }};{{ $isContrast ? '' : ' padding-bottom: var(--section-spacing);' }}">
        <div class="site-shell-container px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-[1fr_1.2fr] gap-12 lg:gap-16">
                <div>
                    @if (! $hideEyebrow)
                        <span class="text-sm font-bold tracking-widest uppercase mb-3 block" style="color: {{ $accentOnWrapper }};" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                    @elseif ($emitMarkers)
                        <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                    @endif
                    @if ($title !== '')
                        <h2 class="text-3xl md:text-4xl font-extrabold text-pretty" style="color: {{ $textOnWrapper }}; font-family: var(--font-display);"
                            {!! $editor('title', 'plain') !!}>{!! $titleHtml !!}</h2>
                    @elseif ($emitMarkers)
                        <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                    @endif
                    <a href="{{ $contactHref }}" class="inline-flex px-5 py-2.5 border rounded-none text-sm font-semibold tracking-wide uppercase mt-8"
                       style="color: {{ $accentOnWrapper }}; border-color: {{ $accentOnWrapper }};">Talk to us</a>
                </div>
                <div>
                    @foreach ($items as $i => $item)
                        <div class="grid grid-cols-[3rem_1fr] gap-x-4 items-baseline py-5"
                             style="border-bottom: 1px solid {{ $hairline }};">
                            <span class="text-xs font-semibold tracking-wide" style="color: {{ $accentOnWrapper }}; font-family: var(--font-display);">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>
                            <div>
                                <h3 class="text-base md:text-lg font-bold" style="color: {{ $textOnWrapper }};"
                                    {!! $editor("items.{$i}.title", 'plain') !!}>@if ($emitMarkers)<button type="button" class="hidden"{!! $editor("items.{$i}.icon", 'image') !!}></button>@endif{{ $item['title'] ?? '' }}</h3>
                                @if (! empty($item['body'] ?? null))
                                    <p class="mt-1 text-sm md:text-base leading-relaxed" style="color: {{ $mutedOnWrapper }};"
                                       {!! $editor("items.{$i}.body", 'plain') !!}>{{ $item['body'] }}</p>
                                @elseif ($emitMarkers)
                                    <span class="hidden"{!! $editor("items.{$i}.body", 'plain') !!}></span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
@endif
