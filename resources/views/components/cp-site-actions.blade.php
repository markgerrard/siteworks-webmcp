@props([
    'site',
])

@php
    $previewHref = null;
    $editHref = null;

    if ($site->latestPreview) {
        // When a public preview host exists (branded preview FQDN / custom
        // domain), route the "View site" link through /_edit/view-live so
        // any stale edit_session cookie gets cleared before landing on the
        // public version. Guarantees the link always shows the LIVE site
        // (last published version), never the admin's in-flight draft.
        //
        // If there's no public host (legacy preview slug), fall back to
        // /preview/{slug} unchanged — no edit cookie exists on that path.
        $publicHost = $site->publicHost();
        $previewHref = $publicHost
            ? 'https://'.$publicHost.'/_edit/view-live'
            : route('preview.show', $site->latestPreview->slug);

        $homePage = config('site.use_versioned_renderer')
            ? $site->generatedPages()->where('page_type', 'home')->whereNull('archived_at')->first()
            : null;
        $hasPublicHost = $site->preview_domain || ($site->custom_domain && $site->custom_domain_status === 'active');
        if ($homePage && $hasPublicHost) {
            $editHref = route('site.editor-shell', ['site' => $site->id, 'page' => $homePage->id]);
        }
    }
@endphp

@if ($previewHref || $editHref)
    <div data-cp-site-actions class="flex items-center gap-1">
        @if ($previewHref)
            <flux:tooltip :content="__('View site')" position="bottom">
                <flux:button
                    variant="ghost"
                    size="sm"
                    icon="arrow-top-right-on-square"
                    :href="$previewHref"
                    target="_blank"
                    :aria-label="__('View site')"
                    data-cp-view-site
                    class="shrink-0 text-zinc-100"
                >
                    <span class="hidden lg:inline">{{ __('View site') }}</span>
                </flux:button>
            </flux:tooltip>
        @endif

        @if ($editHref)
            <flux:tooltip :content="__('Edit site')" position="bottom">
                <flux:button
                    variant="ghost"
                    size="sm"
                    icon="pencil-square"
                    :href="$editHref"
                    target="_blank"
                    :aria-label="__('Edit site')"
                    data-cp-edit-site
                    class="shrink-0 text-zinc-100"
                >
                    <span class="hidden lg:inline">{{ __('Edit site') }}</span>
                </flux:button>
            </flux:tooltip>
        @endif
    </div>
@endif
