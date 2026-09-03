@php $opts = $section['__options'] ?? []; @endphp
<div{!! $enquireId !!} data-lead-form-variant="split-screen" class="relative grid grid-cols-1 lg:grid-cols-2 min-h-[80vh] lg:min-h-screen{{ $enquireClass }}">
    <div class="flex items-center px-6 sm:px-10 lg:px-16 py-16" style="background-color: var(--color-band, #0f172a); color: var(--color-text-on-band, #ffffff);">
        <div class="max-w-xl">
            <span class="text-sm font-bold tracking-widest uppercase mb-4 block opacity-80"{!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
            @if (!empty($section['title']))<h2 class="text-3xl md:text-4xl lg:text-5xl font-extrabold mb-5 leading-tight text-balance"{!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>@elseif ($emitMarkers)<span class="hidden"{!! $editor('title', 'plain') !!}></span>@endif
            @if (!empty($section['intro']))<div class="text-lg md:text-xl leading-relaxed mb-8 opacity-90"{!! $editor('intro', 'rich', is_array($section['intro']) ? $section['intro'] : null) !!}>{!! $richHtml($section['intro']) !!}</div>@elseif ($emitMarkers)<span class="hidden"{!! $editor('intro', 'rich') !!}></span>@endif
            @include('site.partials.lead-form-trust', ['style' => $opts['form_trust_style'] ?? 'pill-badges', 'benefits' => $benefits, 'editor' => $editor, 'onDark' => $surfaceDark['band'] ?? true])
        </div>
    </div>
    <div class="flex items-center bg-white px-6 sm:px-10 lg:px-16 py-16">
        <div class="w-full max-w-xl">
            @include('site.partials.lead-form-core', [
                'fields' => $extraFields, 'messageFieldMigrated' => $messageFieldMigrated, 'formMarker' => $formMarker, 'allowedTypes' => $allowedTypes,
                'formId' => "lf-{$pageId}-{$sectionIndex}", 'site' => $site ?? null,
                'pageId' => $pageId, 'sectionIndex' => $sectionIndex, 'pageType' => $pageType ?? '',
                'emitMarkers' => $emitMarkers, 'editor' => $editor,
                'submitLabel' => $submitLabel, 'cardClass' => '', 'cardStyle' => '',
                'chrome' => ['input_style' => $opts['form_input_style'] ?? 'soft-filled', 'radio_style' => $opts['form_radio_style'] ?? null, 'submit_style' => $opts['form_submit_style'] ?? null, 'surface' => null],
                'layout' => 'stacked',
            ])
        </div>
    </div>
</div>
