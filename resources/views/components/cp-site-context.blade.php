@props([
    'site',
])

@php
    $statusColor = match ($site->status->value) {
        'draft' => 'zinc',
        'review' => 'amber',
        'published' => 'green',
        'failed' => 'red',
        default => 'blue',
    };

    // Staff open the agents CP for this site; client users don't have
    // access to that route, so they get routed into their own portal
    // page for the site instead.
    $nameHref = auth()->user()?->isStaff()
        ? route('sites.show', $site)
        : route('client.portal.site', $site);
@endphp

{{-- Identity only: the View/Edit actions live in the topbar's right cluster
     (x-cp-site-actions) so the site name gets the full left column. --}}
<div
    data-cp-site-context
    class="flex min-w-0 items-center gap-2.5"
>
    <a
        href="{{ $nameHref }}"
        wire:navigate
        data-cp-site-name
        class="min-w-0 truncate text-sm font-medium text-zinc-100 hover:text-white hover:underline"
        title="{{ $site->business_name }}"
    >
        {{ $site->business_name }}
    </a>

    {{-- The pipeline status (Review/Draft/Published) is an internal staff
         concept — clients only ever see their site name in the header. --}}
    @if (auth()->user()?->isStaff())
        <flux:badge size="sm" :color="$statusColor" data-cp-site-status class="shrink-0">
            {{ ucfirst($site->status->value) }}
        </flux:badge>
    @endif
</div>
