@php $headerPhone = $profile['contact']['phones'][0] ?? null; @endphp
@if ($rightAction === 'none')
@elseif ($rightAction === 'phone_cta' && ($headerPhone || $hasCta))
    <div class="flex items-center gap-6">
        @if ($headerPhone)
            <a href="tel:{{ $headerPhone }}" class="font-medium text-sm {{ $navLinkClass }}{{ $navCaseClass }}">{{ $headerPhone }}</a>
        @endif
        @if ($hasCta)
            <a href="{{ $ctaUrl }}"{!! $ctaEnquireClick !!}
               class="inline-flex items-center gap-2 px-5 py-2.5 rounded-md font-bold text-sm shadow-md transition-all hover:shadow-lg hover:brightness-110{{ $navCaseClass }}"
               style="background-color: var(--brand-accent); color: var(--color-text-on-accent, #ffffff);">{{ $ctaLabel }}</a>
        @endif
    </div>
@elseif ($hasCta)
    <a href="{{ $ctaUrl }}"{!! $ctaEnquireClick !!}
       class="inline-flex items-center gap-2 px-5 py-2.5 rounded-md font-bold text-sm shadow-md transition-all hover:shadow-lg hover:brightness-110{{ $navCaseClass }}"
       style="background-color: var(--brand-accent); color: var(--color-text-on-accent, #ffffff);">{{ $ctaLabel }}</a>
@elseif ($headerPhone)
    <a href="tel:{{ $headerPhone }}"
       class="inline-flex items-center gap-2 px-5 py-2.5 rounded-md font-bold text-sm shadow-md transition-all hover:shadow-lg hover:brightness-110"
       style="background-color: var(--brand-accent); color: var(--color-text-on-accent, #ffffff);">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
        {{ $headerPhone }}
    </a>
@endif
