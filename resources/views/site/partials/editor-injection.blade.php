@php
    // Vite::asset() resolves via request scheme, which surfaces as http when
    // the app is behind a TLS-terminating proxy (Cloudflare tunnel) that
    // doesn't forward X-Forwarded-Proto cleanly — causing mixed-content
    // blocks on HTTPS pages. Edit mode is always served over HTTPS, so
    // unconditionally upgrade the asset scheme here.
    $secureAsset = fn (string $path) => preg_replace('#^http://#', 'https://', Vite::asset($path));
@endphp
<link rel="stylesheet" href="{{ $secureAsset('resources/css/site-editor.css') }}">
<script>window.SITE_EDITOR_CONFIG = {!! $config !!};</script>
<script type="module" src="{{ $secureAsset('resources/js/site-editor/index.js') }}"></script>
<script>
    if (window.innerWidth < 1024) {
        document.body.insertAdjacentHTML('afterbegin',
            '<div style="background:#fef3c7;color:#92400e;padding:1rem;text-align:center;font-family:system-ui;">Editing requires a desktop browser.</div>');
    }
</script>
