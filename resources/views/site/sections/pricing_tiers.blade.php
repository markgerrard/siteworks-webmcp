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

    $eyebrow  = $section['eyebrow'] ?? null;
    $title    = $section['title'] ?? null;
    $subtitle = $section['subtitle'] ?? null;
    $tiers    = is_array($section['tiers'] ?? null) ? array_values($section['tiers']) : [];

    // Footnote sits below the grid — useful for "all plans include…" or VAT lines.
    $footnote = $section['footnote'] ?? null;
@endphp

@if (!empty($title) || !empty($tiers))
    <div class="site-section-spacing{{ \App\Support\Textures\TextureLayer::html($siteTexture ?? null, $section ?? null, false, $site ?? null) !== '' ? ' relative overflow-hidden' : '' }}" style="background-color: var(--color-surface);">{!! \App\Support\Textures\TextureLayer::html($siteTexture ?? null, $section ?? null, false, $site ?? null) !!}
        <div class="site-shell-container px-4 sm:px-6 lg:px-8">

            {{-- Section header --}}
            <div class="text-center mb-12 md:mb-16">
                @if (!empty($eyebrow))
                    <span class="text-sm font-semibold uppercase tracking-wider mb-3 block"
                          style="color: var(--brand-accent);"
                          {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                @endif

                @if (!empty($title))
                    <h2 class="text-3xl md:text-4xl font-extrabold leading-tight text-balance"
                        style="color: var(--color-primary);"
                        {!! $editor('title', 'plain') !!}>{{ $title }}</h2>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                @endif

                @if (!empty($subtitle))
                    <p class="mt-4 text-lg max-w-2xl mx-auto leading-relaxed"
                       style="color: var(--color-text-muted);"
                       {!! $editor('subtitle', 'plain') !!}>{{ $subtitle }}</p>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('subtitle', 'plain') !!}></span>
                @endif
            </div>

            {{-- Tier grid --}}
            @if (!empty($tiers))
                @php
                    $cols = min(count($tiers), 3);
                    $gridCols = match ($cols) {
                        1 => 'md:grid-cols-1 max-w-md mx-auto',
                        2 => 'md:grid-cols-2 max-w-3xl mx-auto',
                        default => 'md:grid-cols-3 max-w-6xl mx-auto',
                    };
                @endphp
                <div class="grid grid-cols-1 {{ $gridCols }} gap-6 lg:gap-8 items-stretch">
                    @foreach ($tiers as $i => $tier)
                        @php
                            $name        = $tier['name'] ?? '';
                            $price       = $tier['price'] ?? '';
                            $period      = $tier['period'] ?? '';
                            $description = $tier['description'] ?? '';
                            $features    = is_array($tier['features'] ?? null) ? array_values($tier['features']) : [];
                            $featured    = ! empty($tier['featured']);
                            $ctaLabel    = $tier['cta_label'] ?? null;
                            $ctaUrl      = $tier['cta_url'] ?? '#';
                            $badgeLabel  = $tier['badge'] ?? ($featured ? 'Most popular' : null);
                        @endphp

                        <div class="relative flex flex-col rounded-2xl p-8 transition-shadow duration-300 hover:shadow-lg @if ($featured) shadow-xl md:scale-[1.03] z-10 @endif"
                             @if ($featured)
                                 style="background-color: var(--color-surface); border: 2px solid var(--brand-accent);"
                             @else
                                 style="background-color: var(--color-surface); border: 1px solid var(--color-border);"
                             @endif>

                            {{-- Featured badge --}}
                            @if ($featured && !empty($badgeLabel))
                                <span class="absolute -top-3 left-1/2 -translate-x-1/2 px-3 py-1 rounded-full text-xs font-semibold uppercase tracking-wider whitespace-nowrap"
                                      style="background-color: var(--brand-accent); color: #ffffff;"
                                      {!! $editor("tiers.{$i}.badge", 'plain') !!}>{{ $badgeLabel }}</span>
                            @endif

                            {{-- Tier name --}}
                            <h3 class="text-lg font-semibold uppercase tracking-wide mb-2"
                                style="color: var(--brand-accent);"
                                {!! $editor("tiers.{$i}.name", 'plain') !!}>{{ $name }}</h3>

                            {{-- Price --}}
                            <div class="mb-4 flex items-baseline gap-2 flex-wrap">
                                @if (!empty($price))
                                    <span class="text-4xl md:text-5xl font-extrabold leading-none"
                                          style="color: var(--color-primary);"
                                          {!! $editor("tiers.{$i}.price", 'plain') !!}>{{ $price }}</span>
                                @endif
                                @if (!empty($period))
                                    <span class="text-sm font-medium"
                                          style="color: var(--color-text-muted);"
                                          {!! $editor("tiers.{$i}.period", 'plain') !!}>{{ $period }}</span>
                                @endif
                            </div>

                            {{-- Description --}}
                            @if (!empty($description))
                                <p class="text-base leading-relaxed mb-6"
                                   style="color: var(--color-text-muted);"
                                   {!! $editor("tiers.{$i}.description", 'plain') !!}>{{ $description }}</p>
                            @endif

                            {{-- Feature checklist --}}
                            @if (!empty($features))
                                <ul class="space-y-3 mb-8 flex-1">
                                    @foreach ($features as $f => $feature)
                                        <li class="flex items-start gap-3 text-base leading-snug"
                                            style="color: var(--color-text);">
                                            <span class="flex-shrink-0 mt-0.5" style="color: var(--brand-accent);">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                            </span>
                                            <span {!! $editor("tiers.{$i}.features.{$f}", 'plain') !!}>{{ $feature }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            {{-- CTA --}}
                            @if (!empty($ctaLabel))
                                <a href="{{ $ctaUrl }}"
                                   class="mt-auto inline-flex items-center justify-center gap-2 font-semibold px-6 py-3 rounded-lg transition-all hover:scale-[1.02]"
                                   @if ($featured)
                                       style="background-color: var(--brand-accent); color: #ffffff;"
                                   @else
                                       style="background-color: var(--color-surface-alt); color: var(--brand-accent); border: 1px solid var(--color-border);"
                                   @endif>
                                    <span{!! $editor("tiers.{$i}.cta_label", 'plain') !!}>{{ $ctaLabel }}</span>
                                </a>
                                @if ($emitMarkers)
                                    <button type="button" class="hidden"{!! $editor("tiers.{$i}.cta_url", 'url') !!}></button>
                                @endif
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Optional footnote (e.g., "All plans include hosting" or VAT note) --}}
            @if (!empty($footnote))
                <p class="mt-10 text-center text-sm max-w-2xl mx-auto leading-relaxed"
                   style="color: var(--color-text-muted);"
                   {!! $editor('footnote', 'plain') !!}>{{ $footnote }}</p>
            @elseif ($emitMarkers)
                <span class="hidden"{!! $editor('footnote', 'plain') !!}></span>
            @endif

        </div>
    </div>
@endif
