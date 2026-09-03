@php
    $opts = $section['__options'] ?? [];
    $surface = $opts['form_surface'] ?? 'flat-cream';
    $onDark = $surface === 'card-on-dark';
    $bandStyle = $onDark
        ? 'background-color: var(--color-band, #0f172a);'
        : 'background-color: color-mix(in oklab, var(--color-band, #0f172a) 6%, #fbf8f2);';
    $textStyle = $onDark ? 'color: var(--color-text-on-band, #ffffff);' : 'color: var(--color-text, #111827);';
@endphp
<div{!! $enquireId !!} data-lead-form-variant="centered" class="site-section-spacing relative overflow-hidden{{ $enquireClass }}" style="{{ $bandStyle }}">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8 relative">
        <div class="mx-auto max-w-[640px] text-center" style="{{ $textStyle }}">
            <span class="text-sm font-bold tracking-widest uppercase mb-4 block opacity-80"{!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
            @if (!empty($section['title']))<h2 class="text-3xl md:text-4xl font-extrabold mb-4 leading-tight text-balance"{!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>@elseif ($emitMarkers)<span class="hidden"{!! $editor('title', 'plain') !!}></span>@endif
            @if (!empty($section['intro']))<div class="text-lg leading-relaxed mb-8 opacity-90"{!! $editor('intro', 'rich', is_array($section['intro']) ? $section['intro'] : null) !!}>{!! $richHtml($section['intro']) !!}</div>@elseif ($emitMarkers)<span class="hidden"{!! $editor('intro', 'rich') !!}></span>@endif
            <div class="text-left">
                @include('site.partials.lead-form-core', [
                    'fields' => $extraFields, 'messageFieldMigrated' => $messageFieldMigrated, 'formMarker' => $formMarker, 'allowedTypes' => $allowedTypes,
                    'formId' => "lf-{$pageId}-{$sectionIndex}", 'site' => $site ?? null,
                    'pageId' => $pageId, 'sectionIndex' => $sectionIndex, 'pageType' => $pageType ?? '',
                    'emitMarkers' => $emitMarkers, 'editor' => $editor,
                    'submitLabel' => $submitLabel,
                    'cardClass' => $onDark ? 'bg-white p-7 md:p-9' : 'bg-white p-7 md:p-9 border border-black/5',
                    'cardStyle' => 'border-radius: var(--radius-card); box-shadow: 0 20px 40px -16px rgba(0,0,0,0.25);',
                    'chrome' => ['input_style' => $opts['form_input_style'] ?? 'boxed', 'radio_style' => $opts['form_radio_style'] ?? null, 'submit_style' => $opts['form_submit_style'] ?? null, 'surface' => null],
                    'layout' => 'stacked',
                ])
            </div>
            @include('site.partials.lead-form-trust', ['style' => $opts['form_trust_style'] ?? 'chips-under-button', 'benefits' => $benefits, 'editor' => $editor, 'onDark' => $onDark && ($surfaceDark['band'] ?? true)])
        </div>
    </div>
</div>
