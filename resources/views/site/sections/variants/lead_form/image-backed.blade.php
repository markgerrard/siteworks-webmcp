@php
    $opts = $section['__options'] ?? [];
    $img = $bandImageUrl ?? null;
    if (is_array($img)) { $img = ((bool) ($profile['watermark_enabled'] ?? true) && ! empty($img['watermark_url'])) ? $img['watermark_url'] : ($img['url'] ?? null); }
    $img = is_string($img) && $img !== '' ? $img : (is_string($bgHero ?? null) && $bgHero !== '' ? $bgHero : null);
@endphp
@if ($img === null)
@include('site.sections.lead_form', ['section' => array_merge($section, ['variant' => null]), 'leadFormNested' => true])
@else
<div{!! $enquireId !!} data-lead-form-variant="image-backed" class="site-section-spacing relative overflow-hidden{{ $enquireClass }}" style="background-image: url('{{ $img }}'); background-size: cover; background-position: center;">
    <div class="absolute inset-0 bg-gradient-to-r from-black/75 via-black/55 to-black/30" aria-hidden="true"></div>
    <div class="site-shell-container px-4 sm:px-6 lg:px-8 relative">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-16 items-center" style="color: #ffffff;">
            <div class="lg:col-span-6">
                <span class="text-sm font-bold tracking-widest uppercase mb-4 block opacity-80"{!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                @if (!empty($section['title']))<h2 class="text-3xl md:text-4xl lg:text-5xl font-extrabold mb-5 leading-tight text-balance"{!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>@elseif ($emitMarkers)<span class="hidden"{!! $editor('title', 'plain') !!}></span>@endif
                @if (!empty($section['intro']))<div class="text-lg md:text-xl leading-relaxed mb-8 opacity-90 max-w-xl"{!! $editor('intro', 'rich', is_array($section['intro']) ? $section['intro'] : null) !!}>{!! $richHtml($section['intro']) !!}</div>@elseif ($emitMarkers)<span class="hidden"{!! $editor('intro', 'rich') !!}></span>@endif
                @include('site.partials.lead-form-trust', ['style' => $opts['form_trust_style'] ?? 'tick-list', 'benefits' => $benefits, 'editor' => $editor, 'onDark' => true])
            </div>
            <div class="lg:col-span-6">
                @include('site.partials.lead-form-core', [
                    'fields' => $extraFields, 'messageFieldMigrated' => $messageFieldMigrated, 'formMarker' => $formMarker, 'allowedTypes' => $allowedTypes,
                    'formId' => "lf-{$pageId}-{$sectionIndex}", 'site' => $site ?? null,
                    'pageId' => $pageId, 'sectionIndex' => $sectionIndex, 'pageType' => $pageType ?? '',
                    'emitMarkers' => $emitMarkers, 'editor' => $editor,
                    'submitLabel' => $submitLabel,
                    'cardClass' => 'bg-white p-7 md:p-9 border-t-4',
                    'cardStyle' => 'border-top-color: var(--brand-accent); border-radius: var(--radius-card); box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);',
                    'chrome' => ['input_style' => 'boxed', 'radio_style' => $opts['form_radio_style'] ?? null, 'submit_style' => $opts['form_submit_style'] ?? null, 'surface' => null],
                    'layout' => 'stacked',
                ])
            </div>
        </div>
    </div>
</div>
@endif
