@php
    $opts = $section['__options'] ?? [];
    $contact = $profile['contact'] ?? [];
    $geo = $profile['geo'] ?? [];
    $phone = $contact['phones'][0] ?? null;
    $mobile = $contact['mobile'] ?? null;
    $email = $contact['emails'][0] ?? null;
    $area = $geo['service_area'] ?? ($geo['primary_area'] ?? null);
    $hours = \App\Support\OpeningHours::rows($profile['opening_hours'] ?? null);
    $hasLedger = $phone || $mobile || $email || $area || $hours !== [];
    $hairline = 'border-color: color-mix(in oklab, var(--color-text-muted) 30%, transparent);';
@endphp
<div{!! $enquireId !!} data-lead-form-variant="phone-ledger" class="site-section-spacing relative overflow-hidden{{ $enquireClass }}" style="background-color: var(--color-band, #0f172a);">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8 relative">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-16 items-start" style="color: var(--color-text-on-band, #ffffff);">
            <div class="{{ ! $hasLedger && $benefits === [] ? 'lg:col-span-12' : 'lg:col-span-6' }}">
                <span class="text-sm font-bold tracking-widest uppercase mb-4 block opacity-80"{!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                @if (!empty($section['title']))<h2 class="text-3xl md:text-4xl font-extrabold mb-4 leading-tight text-balance"{!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>@elseif ($emitMarkers)<span class="hidden"{!! $editor('title', 'plain') !!}></span>@endif
                @if (!empty($section['intro']))<div class="text-lg leading-relaxed mb-6 opacity-90"{!! $editor('intro', 'rich', is_array($section['intro']) ? $section['intro'] : null) !!}>{!! $richHtml($section['intro']) !!}</div>@elseif ($emitMarkers)<span class="hidden"{!! $editor('intro', 'rich') !!}></span>@endif
                @include('site.partials.lead-form-core', [
                    'fields' => $extraFields, 'messageFieldMigrated' => $messageFieldMigrated, 'formMarker' => $formMarker, 'allowedTypes' => $allowedTypes,
                    'formId' => "lf-{$pageId}-{$sectionIndex}", 'site' => $site ?? null,
                    'pageId' => $pageId, 'sectionIndex' => $sectionIndex, 'pageType' => $pageType ?? '',
                    'emitMarkers' => $emitMarkers, 'editor' => $editor,
                    'submitLabel' => $submitLabel,
                    'cardClass' => 'bg-white p-7 md:p-9 border-t-4',
                    'cardStyle' => 'border-top-color: var(--brand-accent); border-radius: var(--radius-card); box-shadow: 0 25px 50px -12px rgba(0,0,0,0.4);',
                    'chrome' => ['input_style' => $opts['form_input_style'] ?? null, 'radio_style' => $opts['form_radio_style'] ?? null, 'submit_style' => $opts['form_submit_style'] ?? null, 'surface' => null],
                    'layout' => 'stacked',
                ])
            </div>
@if ($hasLedger || $benefits !== [])
            <div class="lg:col-span-6 lg:pl-10">
                @include('site.partials.lead-form-ledger', ['style' => 'inline', 'profile' => $profile, 'hairline' => $hairline])
@if ($benefits !== [])
                <div class="{{ $hasLedger ? 'mt-8' : '' }}">
                    @include('site.partials.lead-form-trust', ['style' => $opts['form_trust_style'] ?? 'tick-list', 'benefits' => $benefits, 'editor' => $editor, 'onDark' => $surfaceDark['band'] ?? true])
                </div>
@endif
            </div>
@endif
        </div>
    </div>
</div>
