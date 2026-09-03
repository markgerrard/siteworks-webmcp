@props([
    'sidebar' => false,
])

{{-- SiteWorks lockup: hex mark + "SiteWorks" wordmark.
     Hex mark is an isolated PNG (light + dark variants for white vs dark
     surfaces). Wordmark is plain HTML in Montserrat ExtraBold so it scales
     crisply, picks up the design-system display font, and stays editable
     without touching image assets. The two-element layout matches the
     design system's brand-logo treatment without any parent-company
     branding (intentional — this is the SiteWorks product surface). --}}
<a {{ $attributes->merge(['class' => 'flex items-center gap-2']) }}>
    <img src="/images/sw-mark.png"
         alt="{{ config('app.name') }}"
         class="h-7 w-auto block dark:hidden">
    <img src="/images/sw-mark-dark.png"
         alt="{{ config('app.name') }}"
         class="h-7 w-auto hidden dark:block">
    <span @class([
        'font-display font-extrabold tracking-tight text-zinc-900 dark:text-white',
        'text-base leading-none',
        'truncate' => $sidebar,
    ])>SiteWorks</span>
</a>
