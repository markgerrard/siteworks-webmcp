<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

<link rel="preconnect" href="https://fonts.bunny.net">
{{-- SiteWorks brand fonts: Inter for body/UI, Montserrat for display/wordmark.
     Loaded from bunny.net (already preconnected) so the editor-preview CSP
     font-src directive (fonts.bunny.net) covers it without changes. --}}
<link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|montserrat:600,700,800" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance(['nonce' => \Illuminate\Support\Facades\Vite::cspNonce()])
