@props(['site', 'surface' => 'shop-admin', 'set' => 'sandbox'])

@php
    $agentToolsConfig = app(\App\Services\Site\Editor\ShopAgentToolsSeed::class)->js(auth()->user(), $site, $surface, $set);
    // Portal pages live on the customer surface; parent-entry.js ships in
    // build-customer there (see editor-shell.blade.php). Agents CP stays on
    // build-agents. Not a JS change — only which Vite manifest the seed reads.
    $editorBuildDir = $surface === 'portal-shop' ? 'build-customer' : 'build-agents';
    if (config('surfaces.current') === 'all' || ! is_file(public_path($editorBuildDir.'/manifest.json'))) {
        $editorBuildDir = 'build';
    }
@endphp

<script @cspNonce>
    window.__siteworks_editor_shell_config__ = {!! $agentToolsConfig !!};
</script>
@vite(['resources/js/site-editor/parent-entry.js'], $editorBuildDir)
