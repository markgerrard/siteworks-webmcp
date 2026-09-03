@php $opts = $section['__options'] ?? []; $surface = $opts['form_surface'] ?? 'flat-cream'; $onDark = $surface === 'card-on-dark' && ($surfaceDark['band'] ?? true); @endphp
<div{!! $enquireId !!} data-lead-form-variant="inline-band" class="py-10 md:py-12 relative{{ $enquireClass }}" style="{{ $onDark ? 'background-color: var(--color-band, #0f172a); color: var(--color-text-on-band, #ffffff);' : 'background-color: color-mix(in oklab, var(--color-band, #0f172a) 6%, #fbf8f2); color: var(--color-text, #111827);' }}">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 lg:gap-10 items-start">
            <div class="lg:col-span-4">
                <span class="text-xs font-bold tracking-widest uppercase mb-2 block opacity-80"{!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                @if (!empty($section['title']))<h2 class="text-2xl md:text-3xl font-extrabold leading-tight text-balance"{!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>@elseif ($emitMarkers)<span class="hidden"{!! $editor('title', 'plain') !!}></span>@endif
                @if ($emitMarkers)<span class="hidden"{!! $editor('intro', 'rich') !!}></span>@endif
                @if ($emitMarkers)@foreach ($benefits as $i => $b)<span class="hidden"{!! $editor("benefits.{$i}", 'plain') !!}></span>@endforeach @endif
            </div>
            <div class="lg:col-span-8">
                @include('site.partials.lead-form-core', [
                    'fields' => $extraFields, 'messageFieldMigrated' => $messageFieldMigrated, 'formMarker' => $formMarker, 'allowedTypes' => $allowedTypes,
                    'formId' => "lf-{$pageId}-{$sectionIndex}", 'site' => $site ?? null,
                    'pageId' => $pageId, 'sectionIndex' => $sectionIndex, 'pageType' => $pageType ?? '',
                    'emitMarkers' => $emitMarkers, 'editor' => $editor,
                    'submitLabel' => $submitLabel, 'cardClass' => '', 'cardStyle' => '',
                    'chrome' => ['input_style' => $opts['form_input_style'] ?? 'boxed', 'radio_style' => $opts['form_radio_style'] ?? null, 'submit_style' => 'auto-arrow', 'surface' => $onDark ? 'panel-inverted' : null],
                    'layout' => 'row',
                ])
            </div>
        </div>
    </div>
</div>
