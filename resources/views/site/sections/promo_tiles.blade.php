@php
    $editor = function ($field, $type, $valueDoc = null) use ($pageId, $sectionIndex, $emitMarkers, $section) {
        if (! $emitMarkers) {
            return '';
        }
        $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";
        $sectionType = $section['type'] ?? '';
        $attrs = ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="'.e($sectionType).'" data-editable-field="'.e($field).'"';
        if ($type === 'rich' && $valueDoc !== null) {
            $attrs .= ' data-editable-doc="'.e(json_encode($valueDoc)).'"';
        }

        return $attrs;
    };

    $picked = [];
    $rawTiles = is_array($section['tiles'] ?? null) ? array_values($section['tiles']) : [];
    foreach ($rawTiles as $i => $tile) {
        if (count($picked) >= 3) {
            break;
        }
        if (! is_array($tile)) {
            continue;
        }
        if (trim((string) ($tile['heading'] ?? '')) === '') {
            continue;
        }
        $picked[] = ['i' => $i, 'tile' => $tile];
    }
    $n = count($picked);
    $gridClass = $n === 3 ? 'grid gap-4 md:grid-cols-3' : 'grid gap-4 md:grid-cols-2';
@endphp
@if ($n >= 2)
    <div class="site-section-spacing relative overflow-hidden"
         style="background-color: var(--color-surface);">{!! \App\Support\Textures\TextureLayer::html($siteTexture ?? null, $section ?? null, false, $site ?? null) !!}
        <div class="site-shell-container px-4 sm:px-6 lg:px-8 relative">
            <div class="text-center mb-12">
                @if (!empty($section['title']))
                    @if (empty($section['__suppress_eyebrow']))
                        <span class="text-sm font-bold tracking-widest uppercase mb-3 block" style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>{{ $section['eyebrow'] ?? '' }}</span>
                    @elseif ($emitMarkers)
                        <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                    @endif
                    <h2 class="text-3xl md:text-4xl font-extrabold" style="color: var(--color-text);"
                        {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                    <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                @endif
            </div>
            <div class="{{ $gridClass }}">
                @foreach ($picked as $row)
                    @php
                        $i = $row['i'];
                        $tile = $row['tile'];
                        $tone = $tile['tone'] ?? 'soft';
                        if (! in_array($tone, ['primary', 'accent', 'soft'], true)) {
                            $tone = 'soft';
                        }
                        $panelStyle = match ($tone) {
                            'primary' => 'background: var(--brand-primary); color: var(--color-text-on-primary); border-radius: var(--radius-card); padding: 2rem; display: flex; flex-direction: column; gap: .75rem; min-height: 16rem;',
                            'accent' => 'background: var(--brand-accent); color: var(--color-text-on-accent); border-radius: var(--radius-card); padding: 2rem; display: flex; flex-direction: column; gap: .75rem; min-height: 16rem;',
                            default => 'background: var(--color-surface-alt); color: var(--color-text-on-alt); border-radius: var(--radius-card); padding: 2rem; display: flex; flex-direction: column; gap: .75rem; min-height: 16rem;',
                        };
                        $textStyle = $tone === 'soft'
                            ? 'font-size: 1rem; color: var(--color-text-muted-on-alt);'
                            : 'font-size: 1rem;';
                        $ctaStyle = match ($tone) {
                            'primary' => 'background: var(--color-surface); color: var(--brand-primary); border-radius: var(--radius-button); margin-top: auto;',
                            'accent' => 'background: var(--color-surface); color: var(--color-text); border-radius: var(--radius-button); margin-top: auto;',
                            default => 'background: var(--brand-primary); color: var(--color-text-on-primary); border-radius: var(--radius-button); margin-top: auto;',
                        };
                        $ctaLabel = trim((string) ($tile['cta_label'] ?? ''));
                        $ctaUrl = \App\Services\Site\SectionSchema::isSafeLink($tile['cta_url'] ?? null)
                            ? trim((string) $tile['cta_url'])
                            : null;
                    @endphp
                    <div style="{{ $panelStyle }}">
                        <h3 style="font-family: var(--font-display); font-size: 1.5rem; font-weight: 600;"
                            {!! $editor("tiles.{$i}.heading", 'plain') !!}>{{ $tile['heading'] }}</h3>
                        @if (!empty($tile['text']))
                            <p style="{{ $textStyle }}"
                               {!! $editor("tiles.{$i}.text", 'plain') !!}>{{ $tile['text'] }}</p>
                        @elseif ($emitMarkers)
                            <span class="hidden"{!! $editor("tiles.{$i}.text", 'plain') !!}></span>
                        @endif
                        @if ($ctaLabel !== '' && $ctaUrl !== null)
                            <a href="{{ $ctaUrl }}"
                               class="inline-flex px-5 py-2.5 font-bold text-sm"
                               style="{{ $ctaStyle }}">
                                <span{!! $editor("tiles.{$i}.cta_label", 'plain') !!}>{{ $ctaLabel }}</span>
                            </a>
                        @elseif ($emitMarkers)
                            <span class="hidden"{!! $editor("tiles.{$i}.cta_label", 'plain') !!}></span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif
