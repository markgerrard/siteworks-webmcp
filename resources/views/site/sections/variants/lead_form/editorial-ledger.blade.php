@php
    $opts = $section['__options'] ?? [];
    $contact = $profile['contact'] ?? [];
    $geo = $profile['geo'] ?? [];
    $hasLedger = ($contact['phones'][0] ?? null) || ($contact['mobile'] ?? null) || ($contact['emails'][0] ?? null) || ($contact['address'] ?? null) || ($geo['service_area'] ?? ($geo['primary_area'] ?? null)) || \App\Support\OpeningHours::rows($profile['opening_hours'] ?? null) !== [];
    $hairline = 'border-color: color-mix(in oklab, var(--color-text-muted) 30%, transparent);';
@endphp
<div{!! $enquireId !!} data-lead-form-variant="editorial-ledger" class="site-section-spacing relative{{ $enquireClass }}" style="background-color: color-mix(in oklab, var(--color-band, #0f172a) 6%, #fbf8f2); color: var(--color-text, #111827);">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 lg:gap-20 items-start">
            <div class="lg:col-span-5">
                <span class="inline-flex items-center gap-3 text-xs font-semibold uppercase tracking-[0.18em] opacity-70 mb-6"><span class="inline-block w-8 border-t" style="border-color: currentColor;" aria-hidden="true"></span><span{!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span></span>
                @if (!empty($section['title']))<h2 class="text-4xl md:text-5xl font-medium leading-[1.05] text-balance mb-6" style="font-family: var(--font-display, inherit);"{!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>@elseif ($emitMarkers)<span class="hidden"{!! $editor('title', 'plain') !!}></span>@endif
                @if (!empty($section['intro']))<div class="text-lg leading-relaxed opacity-90 mb-10"{!! $editor('intro', 'rich', is_array($section['intro']) ? $section['intro'] : null) !!}>{!! $richHtml($section['intro']) !!}</div>@elseif ($emitMarkers)<span class="hidden"{!! $editor('intro', 'rich') !!}></span>@endif
                @if ($hasLedger)
                @include('site.partials.lead-form-ledger', ['style' => 'stacked', 'profile' => $profile, 'hairline' => $hairline])
                @endif
                @if ($emitMarkers)@foreach ($benefits as $i => $b)<span class="hidden"{!! $editor("benefits.{$i}", 'plain') !!}></span>@endforeach @endif
            </div>
            <div class="lg:col-span-7">
                @include('site.partials.lead-form-core', [
                    'fields' => $extraFields, 'messageFieldMigrated' => $messageFieldMigrated, 'formMarker' => $formMarker, 'allowedTypes' => $allowedTypes,
                    'formId' => "lf-{$pageId}-{$sectionIndex}", 'site' => $site ?? null,
                    'pageId' => $pageId, 'sectionIndex' => $sectionIndex, 'pageType' => $pageType ?? '',
                    'emitMarkers' => $emitMarkers, 'editor' => $editor,
                    'submitLabel' => $submitLabel, 'cardClass' => '', 'cardStyle' => '',
                    'chrome' => ['input_style' => 'underline', 'radio_style' => $opts['form_radio_style'] ?? null, 'submit_style' => $opts['form_submit_style'] ?? 'auto', 'surface' => null],
                    'layout' => 'stacked',
                ])
            </div>
        </div>
    </div>
</div>
