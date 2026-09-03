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
@endphp
<section class="py-12 bg-gray-50">
    <div class="max-w-4xl mx-auto px-4">
        @if (! empty($section['title']))
            <h2 class="text-3xl font-bold mb-4"{!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
        @endif
        @if (! empty($section['body']))
            <div class="prose max-w-none"{!! $editor('body', 'rich', is_array($section['body']) ? $section['body'] : null) !!}>{!! is_array($section['body']) ? app(\App\Services\Site\RichTextRenderer::class)->render($section['body']) : e($section['body']) !!}</div>
        @endif
    </div>
</section>
