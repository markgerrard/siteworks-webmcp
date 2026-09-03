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
    $items    = is_array($section['items'] ?? null) ? array_values($section['items']) : [];
@endphp

@if (!empty($title) || !empty($items))
    <div class="site-section-spacing" style="background-color: var(--color-surface);">
        <div class="site-shell-container px-4 sm:px-6 lg:px-8">

            {{-- Section header --}}
            <div class="text-center mb-12">
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

            {{-- Showcase grid --}}
            @if (!empty($items))
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 lg:gap-8">
                    @foreach ($items as $i => $item)
                        @php
                            $name           = $item['name'] ?? '';
                            $tagline        = $item['tagline'] ?? '';
                            $screenshotUrl  = $item['screenshot_url'] ?? null;
                            $url            = $item['url'] ?? null;
                            $screenshotAlt  = $item['screenshot_alt'] ?? "Live SiteWorks site — {$name}";
                            $cardTag        = $url ? 'a' : 'div';
                        @endphp

                        <{{ $cardTag }} @if ($url) href="{{ $url }}" target="_blank" rel="noopener" @endif
                            class="group flex flex-col rounded-2xl overflow-hidden transition-shadow duration-300 hover:shadow-xl"
                            style="background-color: var(--color-surface); border: 1px solid var(--color-border);">

                            {{-- Screenshot, 16:9 cover crop --}}
                            <div class="relative w-full overflow-hidden" style="aspect-ratio: 16 / 10; background-color: var(--color-surface-alt);">
                                @if (!empty($screenshotUrl))
                                    <img src="{{ $screenshotUrl }}"
                                         alt="{{ $screenshotAlt }}"
                                         loading="lazy"
                                         class="absolute inset-0 w-full h-full object-cover object-top transition-transform duration-500 group-hover:scale-[1.02]">
                                @else
                                    {{-- Placeholder while screenshot is being captured --}}
                                    <div class="absolute inset-0 flex items-center justify-center"
                                         style="background: linear-gradient(135deg, #e4e7eb 0%, #cbd0d8 100%);">
                                        <p class="text-sm font-medium" style="color: var(--color-text-muted);">{{ $name ?: 'Site preview' }}</p>
                                    </div>
                                @endif
                            </div>

                            {{-- Card footer --}}
                            <div class="p-5 flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    @if ($name)
                                        <p class="text-base font-semibold leading-snug truncate"
                                           style="color: var(--color-primary);"
                                           {!! $editor("items.{$i}.name", 'plain') !!}>{{ $name }}</p>
                                    @endif
                                    @if ($tagline)
                                        <p class="text-sm leading-snug truncate"
                                           style="color: var(--color-text-muted);"
                                           {!! $editor("items.{$i}.tagline", 'plain') !!}>{{ $tagline }}</p>
                                    @endif
                                </div>
                                @if ($url)
                                    <span class="text-sm font-semibold whitespace-nowrap transition-transform group-hover:translate-x-0.5"
                                          style="color: var(--brand-accent);">
                                        View live →
                                    </span>
                                @endif
                            </div>
                        </{{ $cardTag }}>
                    @endforeach
                </div>
            @endif

            {{-- Optional "coda" card — one larger centred entry below the grid.
                 Useful for a featured-above-the-rest item (the dogfood "and so is
                 this one" moment). Renders only when a screenshot_url is set. --}}
            @php
                $featured = is_array($section['featured_below'] ?? null) ? $section['featured_below'] : null;
                $fb_screenshot = $featured['screenshot_url'] ?? null;
            @endphp
            @if ($featured && !empty($fb_screenshot))
                @php
                    $fb_eyebrow = $featured['eyebrow'] ?? null;
                    $fb_title   = $featured['title'] ?? null;
                    $fb_body    = $featured['body'] ?? null;
                    $fb_url     = $featured['url'] ?? null;
                    $fb_link_label = $featured['link_label'] ?? 'View live →';
                    $fb_alt     = $featured['screenshot_alt'] ?? '';
                @endphp
                <div class="mt-16 md:mt-24">
                    <div class="mx-auto max-w-3xl rounded-2xl p-6 md:p-10"
                         style="background-color: var(--color-surface-alt); border: 1px solid var(--color-border);">
                        <div class="text-center mb-6">
                            @if ($fb_eyebrow)
                                <span class="text-sm font-semibold uppercase tracking-wider mb-2 block"
                                      style="color: var(--brand-accent);"
                                      {!! $editor('featured_below.eyebrow', 'plain') !!}>{{ $fb_eyebrow }}</span>
                            @endif
                            @if ($fb_title)
                                <h3 class="text-2xl md:text-3xl font-bold leading-tight text-balance"
                                    style="color: var(--color-primary);"
                                    {!! $editor('featured_below.title', 'plain') !!}>{{ $fb_title }}</h3>
                            @endif
                            @if ($fb_body)
                                <p class="mt-3 text-base md:text-lg leading-relaxed max-w-xl mx-auto"
                                   style="color: var(--color-text-muted);"
                                   {!! $editor('featured_below.body', 'plain') !!}>{{ $fb_body }}</p>
                            @endif
                        </div>

                        @php $fbTag = $fb_url ? 'a' : 'div'; @endphp
                        <{{ $fbTag }} @if ($fb_url) href="{{ $fb_url }}" target="_blank" rel="noopener" @endif
                            class="group block overflow-hidden rounded-xl shadow-xl transition-shadow hover:shadow-2xl"
                            style="background-color: var(--color-surface); border: 1px solid var(--color-border);">
                            <div class="relative w-full overflow-hidden" style="aspect-ratio: 16 / 10;">
                                <img src="{{ $fb_screenshot }}"
                                     alt="{{ $fb_alt }}"
                                     loading="lazy"
                                     class="absolute inset-0 w-full h-full object-cover object-top transition-transform duration-500 group-hover:scale-[1.01]">
                            </div>
                            @if ($fb_url)
                                <div class="p-4 text-center">
                                    <span class="text-sm font-semibold transition-transform group-hover:translate-x-0.5"
                                          style="color: var(--brand-accent);">
                                        {{ $fb_link_label }}
                                    </span>
                                </div>
                            @endif
                        </{{ $fbTag }}>
                    </div>
                </div>
            @endif

        </div>
    </div>
@endif
