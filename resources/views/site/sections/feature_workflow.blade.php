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

    $eyebrow = $section['eyebrow'] ?? null;
    $title   = $section['title'] ?? null;
    $subtitle = $section['subtitle'] ?? null;
    $steps   = is_array($section['steps'] ?? null) ? array_values($section['steps']) : [];
@endphp

@if (!empty($title) || !empty($steps))
    <div class="site-section-spacing" style="background-color: var(--color-surface-alt);">
        <div class="site-shell-container px-4 sm:px-6 lg:px-8">

            {{-- Section header --}}
            <div class="text-center mb-16">
                @if (!empty($eyebrow))
                    <span class="text-sm font-semibold uppercase tracking-wider mb-3 block"
                          style="color: var(--brand-accent);"
                          {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                @endif

                @if (!empty($title))
                    <h2 class="text-3xl md:text-4xl font-extrabold leading-tight text-balance"
                        style="color: var(--color-text-on-alt);"
                        {!! $editor('title', 'plain') !!}>{{ $title }}</h2>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                @endif

                @if (!empty($subtitle))
                    <p class="mt-4 text-lg max-w-2xl mx-auto leading-relaxed"
                       style="color: var(--color-text-muted-on-alt);"
                       {!! $editor('subtitle', 'plain') !!}>{{ $subtitle }}</p>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('subtitle', 'plain') !!}></span>
                @endif
            </div>

            {{-- Steps --}}
            @if (!empty($steps))
                <div class="space-y-16 md:space-y-24">
                    @foreach ($steps as $i => $step)
                        @php
                            // Resolve layout side: use explicit 'side' field, or auto-alternate from index.
                            $explicitSide    = $step['side'] ?? null;
                            $isImageLeft     = ($explicitSide === 'left') || ($explicitSide === null && $i % 2 === 0);
                            $stepNumber      = $step['number'] ?? ($i + 1);
                            $stepTitle       = $step['title'] ?? '';
                            $stepBody        = $step['body'] ?? '';
                            $stepScreenshot  = $step['screenshot_url'] ?? null;
                        @endphp

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-10 lg:gap-16 items-center">

                            {{-- Screenshot column. On mobile, always sits below the copy
                                 so the step title reads first; on lg+, alternates side. --}}
                            <div class="order-2 {{ $isImageLeft ? 'lg:order-first' : 'lg:order-last' }} flex items-center justify-center">
                                @if (!empty($stepScreenshot))
                                    <div class="w-full max-w-lg">
                                        <img src="{{ $stepScreenshot }}"
                                             alt=""
                                             aria-hidden="true"
                                             loading="lazy"
                                             class="w-full h-auto rounded-xl shadow-xl"
                                             style="border: 1px solid var(--color-border);">
                                    </div>
                                @else
                                    {{-- Placeholder if no screenshot yet --}}
                                    <div class="w-full max-w-lg rounded-xl shadow-xl flex items-center justify-center"
                                         style="background: linear-gradient(135deg, #e4e7eb 0%, #cbd0d8 100%); border: 1px solid var(--color-border); min-height: 240px;">
                                        <p class="text-sm font-medium" style="color: var(--color-text-muted);">Step {{ $stepNumber }}</p>
                                    </div>
                                @endif
                            </div>

                            {{-- Copy column --}}
                            <div class="order-1 {{ $isImageLeft ? 'lg:order-last' : 'lg:order-first' }} relative">
                                {{-- Large decorative step numeral --}}
                                <span class="absolute -top-4 -left-4 text-7xl font-bold leading-none select-none pointer-events-none"
                                      aria-hidden="true"
                                      style="color: var(--brand-accent); opacity: 0.3;">{{ $stepNumber }}</span>

                                <div class="relative pl-8 lg:pl-10">
                                    <h3 class="text-2xl md:text-3xl font-bold mb-4 leading-snug"
                                        style="color: var(--color-text-on-alt);"
                                        {!! $editor("steps.{$i}.title", 'plain') !!}>{{ $stepTitle }}</h3>

                                    @if (!empty($stepBody))
                                        <p class="text-lg leading-relaxed"
                                           style="color: var(--color-text-muted-on-alt);"
                                           {!! $editor("steps.{$i}.body", 'plain') !!}>{{ $stepBody }}</p>
                                    @endif
                                </div>
                            </div>

                        </div>
                    @endforeach
                </div>
            @endif

        </div>
    </div>
@endif
