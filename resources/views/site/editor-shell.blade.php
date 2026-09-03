@php
    // Surface-aware bundle directory: agents-host requests resolve via
    // build-agents, customer-host requests via build-customer. Both bundles
    // include parent-entry.js (vite.config.js adds it to the customer
    // surface inputs alongside agents).
    $editorBuildDir = request()->getHost() === config('domains.customer_domain')
        ? 'build-customer'
        : 'build-agents';
    if (config('surfaces.current') === 'all' || ! is_file(public_path($editorBuildDir.'/manifest.json'))) {
        $editorBuildDir = 'build';
    }

    // Surface-aware "back to site" link — clients return to their portal
    // overview, agents/staff return to the agent sites.show page. Both
    // routes are guaranteed to exist on their respective surfaces.
    $backUrl = request()->getHost() === config('domains.customer_domain')
        ? route('client.portal.site', $site)
        : route('sites.show', $site->id);
@endphp
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit · {{ $site->business_name }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/site-editor/parent-entry.js'], $editorBuildDir)
    {{--
        Publish modal chrome. toolbar.js appends #site-editor-publish-modal to this document, but
        site-editor.css (which styles it) is only injected into the preview iframe; loading the
        whole file here would also fix the toolbar to the top and pad the body. So the modal's
        rules live inline, with an explicit text colour because this shell renders in dark mode.
    --}}
    <style @cspNonce>
        #site-editor-publish-modal { position: fixed; inset: 0; background: rgb(0 0 0 / 50%); display: none; align-items: center; justify-content: center; z-index: 10000; }
        #site-editor-publish-modal.open { display: flex; }
        #site-editor-publish-modal > .modal-body { background: white; color: rgb(24 24 27); padding: 2rem; border-radius: 0.5rem; max-width: 600px; width: 100%; }
    </style>
</head>
<body class="bg-zinc-100 dark:bg-zinc-950 text-zinc-900 dark:text-zinc-100 antialiased">
    <div id="editor-shell-root" class="flex h-screen flex-col">
        {{--
            Toolbar bar — matches the style of the main admin header (layouts/app/header.blade.php):
            same height (~3.5rem), same border + bg, same horizontal padding.
            JS-rendered controls mount inside [data-editor-toolbar].
            parent-entry.js → mountToolbar({bridge,config}) populates it.
        --}}
        <header class="flex shrink-0 items-center gap-4 border-b border-zinc-200 bg-zinc-50 px-6 py-3 dark:border-zinc-700 dark:bg-zinc-900">
            <a href="{{ $backUrl }}"
               class="flex items-center gap-2 text-sm text-zinc-500 hover:text-zinc-900 dark:hover:text-white"
               title="Back to site">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
                <span class="hidden sm:inline">{{ request()->getHost() === config('domains.customer_domain') ? __('Site') : __('Sites') }}</span>
            </a>

            <div class="flex h-6 w-px bg-zinc-300 dark:bg-zinc-700" aria-hidden="true"></div>

            <div data-editor-toolbar class="flex flex-1 items-center gap-3 min-w-0">
                {{--
                    parent-entry.js → mountToolbar() populates this region with:
                      - left: site name + draft state badge
                      - right: Close edit / Discard draft / Publish site buttons
                    Button styling lives in toolbar.js until we Flux-ify it.
                --}}
            </div>
        </header>

        {{--
            sandbox="allow-scripts allow-same-origin" — `allow-same-origin`
            lets the iframe keep its real origin (the editor-preview host), not a unique opaque null.
            Required so the iframe can fetch its own JS bundle (ESM modules
            trigger CORS from null origins) and so Alpine/inline scripts
            inside preview HTML can see relative URLs cleanly.
            Cross-origin from the agents-domain parent already isolates
            session cookies — `allow-same-origin` does NOT widen that
            because the iframe's origin is the editor-preview domain, not
            the agents domain. allow-forms / allow-popups / allow-top-navigation
            still NOT granted, so untrusted draft content cannot post forms,
            open popups, or navigate the parent.
        --}}
        <iframe
            id="editor-preview-iframe"
            src="{{ $iframeUrl }}"
            sandbox="allow-scripts allow-same-origin"
            class="flex-1 w-full bg-white"
            data-iframe-origin="{{ $iframeOrigin }}"
            referrerpolicy="no-referrer"
            title="Editor preview ({{ $site->business_name }})"
        ></iframe>
    </div>

    <script @cspNonce>
        window.__siteworks_editor_shell_config__ = {!! $config !!};
    </script>
</body>
</html>
