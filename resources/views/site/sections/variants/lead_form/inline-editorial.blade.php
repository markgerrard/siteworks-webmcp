@php $opts = $section['__options'] ?? []; $primaryDark = $surfaceDark['primary'] ?? true; @endphp
<div{!! $enquireId !!} data-lead-form-variant="inline-editorial" class="site-section-spacing relative{{ $enquireClass }}" style="background-color: var(--color-primary, #0f172a); color: var(--color-text-on-primary, #ffffff);">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        <div class="max-w-3xl">
            <span class="text-sm font-bold tracking-widest uppercase mb-4 block opacity-80"{!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
            @if (!empty($section['title']))<h2 class="text-3xl md:text-5xl font-extrabold mb-5 leading-tight text-balance" style="font-family: var(--font-display, inherit);"{!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>@elseif ($emitMarkers)<span class="hidden"{!! $editor('title', 'plain') !!}></span>@endif
            @if (!empty($section['intro']))<div class="text-lg leading-relaxed mb-10 opacity-90"{!! $editor('intro', 'rich', is_array($section['intro']) ? $section['intro'] : null) !!}>{!! $richHtml($section['intro']) !!}</div>@elseif ($emitMarkers)<span class="hidden"{!! $editor('intro', 'rich') !!}></span>@endif
            @include('site.partials.lead-form-core', [
                'fields' => $extraFields, 'messageFieldMigrated' => $messageFieldMigrated, 'formMarker' => $formMarker, 'allowedTypes' => $allowedTypes,
                'formId' => "lf-{$pageId}-{$sectionIndex}", 'site' => $site ?? null,
                'pageId' => $pageId, 'sectionIndex' => $sectionIndex, 'pageType' => $pageType ?? '',
                'emitMarkers' => $emitMarkers, 'editor' => $editor,
                'submitLabel' => $submitLabel,
                'cardClass' => $primaryDark ? '' : 'bg-white p-7 md:p-9',
                'cardStyle' => $primaryDark ? '' : 'border-radius: var(--radius-card); box-shadow: 0 20px 40px -16px rgba(0,0,0,0.25);',
                'chrome' => ['input_style' => 'underline', 'radio_style' => $opts['form_radio_style'] ?? null, 'submit_style' => 'auto-arrow', 'surface' => $primaryDark ? 'panel-inverted' : null],
                'layout' => 'stacked',
            ])
            <div class="mt-8">
                @include('site.partials.lead-form-trust', ['style' => 'inline-piped', 'benefits' => $benefits, 'editor' => $editor, 'onDark' => $primaryDark])
            </div>
        </div>
    </div>
</div>
