{{-- Iframe-side editor JS. Loaded via Vite's editor-preview build target.
     This injection runs on the editor-preview origin only;
     parent-side toolbar/image-picker are NOT included. --}}
<div data-editor-iframe-bridge style="display:none">
    <script>window.__siteworks_editor_iframe_config__ = {!! $config !!};</script>
</div>
@php
    $editorPreviewBuildDir = 'build-editor-preview';
    if (config('surfaces.current') === 'all' || ! is_file(public_path($editorPreviewBuildDir.'/manifest.json'))) {
        $editorPreviewBuildDir = 'build';
    }
@endphp
@vite(['resources/js/site-editor/iframe-entry.js'], $editorPreviewBuildDir)
