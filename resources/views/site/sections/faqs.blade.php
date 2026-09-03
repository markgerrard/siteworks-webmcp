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
    $richHtml = fn ($val) => app(\App\Services\Site\RichTextRenderer::class)->renderValue($val);
    $eyebrow = $section['eyebrow'] ?? 'FAQ';
@endphp

<div class="site-section-spacing" style="background-color: var(--color-surface);">
    <div class="site-shell-container mx-auto px-4 sm:px-6 lg:px-8" style="max-width: min(var(--container-width), 52rem);">
        @if (!empty($section['title']))
            <div class="text-center mb-12">
                <span class="text-sm font-bold tracking-widest uppercase mb-3 block" style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                <h2 class="text-3xl md:text-4xl font-extrabold leading-tight text-balance"
                    style="color: var(--color-text);"
                    {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
            </div>
        @else
            @if ($emitMarkers)
                <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                <span class="hidden"{!! $editor('title', 'plain') !!}></span>
            @endif
        @endif

        @if (!empty($section['items']))
            @php $faqBase = "faq-{$pageId}-{$sectionIndex}"; @endphp
            <div class="space-y-4" x-data="{ open: null }" role="list">
                @foreach ($section['items'] as $idx => $item)
                    @php
                        $btnId = "{$faqBase}-btn-{$idx}";
                        $panelId = "{$faqBase}-panel-{$idx}";
                    @endphp
                    <div class="rounded-lg shadow-sm overflow-hidden"
                         role="listitem"
                         style="background-color: var(--color-surface-alt); border: 1px solid var(--color-border);">
                        <button type="button"
                                id="{{ $btnId }}"
                                aria-controls="{{ $panelId }}"
                                x-bind:aria-expanded="open === {{ $idx }} ? 'true' : 'false'"
                                x-on:click="open = open === {{ $idx }} ? null : {{ $idx }}"
                                class="w-full flex items-center justify-between px-6 py-5 text-left cursor-pointer">
                            <span class="text-base font-semibold"
                                  style="color: var(--color-text-on-alt);"
                                  {!! $editor("items.{$idx}.question", 'plain') !!}>{{ $item['question'] ?? '' }}</span>
                            <svg class="w-5 h-5 flex-shrink-0 transition-transform"
                                 style="color: var(--color-text-muted-on-alt);"
                                 x-bind:class="open === {{ $idx }} ? 'rotate-180' : ''"
                                 aria-hidden="true"
                                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <div id="{{ $panelId }}"
                             role="region"
                             aria-labelledby="{{ $btnId }}"
                             x-show="open === {{ $idx }}" x-cloak class="px-6 pb-5">
                            <div class="leading-relaxed prose prose-sm max-w-none"
                                 style="color: var(--color-text-muted-on-alt);"
                                 {!! $editor("items.{$idx}.answer", 'rich', is_array($item['answer'] ?? null) ? $item['answer'] : null) !!}>{!! $richHtml($item['answer'] ?? '') !!}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
