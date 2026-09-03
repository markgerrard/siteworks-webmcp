@php $style = $style ?? 'tick-list'; $onDark = $onDark ?? true; @endphp
@if ($benefits !== [])
@if ($style === 'tick-list')
<ul data-trust-style="tick-list" class="space-y-3 max-w-md">
@foreach ($benefits as $i => $benefit)
    <li class="flex items-center gap-3">
        <span class="flex-shrink-0 w-7 h-7 rounded-full flex items-center justify-center" style="background-color: var(--brand-accent); color: var(--color-text-on-accent, #ffffff);">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
        </span>
        <span class="text-base md:text-lg font-medium"{!! $editor("benefits.{$i}", 'plain') !!}>{{ $benefit }}</span>
    </li>
@endforeach
</ul>
@elseif ($style === 'chips-under-button')
<ul data-trust-style="chips-under-button" class="mt-5 flex flex-wrap gap-2 justify-center">
@foreach ($benefits as $i => $benefit)
    <li class="px-3 py-1 rounded-full text-xs font-semibold tracking-wide {{ $onDark ? 'bg-white/10 text-white/80' : 'bg-black/5 text-gray-700' }}"{!! $editor("benefits.{$i}", 'plain') !!}>{{ $benefit }}</li>
@endforeach
</ul>
@elseif ($style === 'inline-piped')
<p data-trust-style="inline-piped" class="text-xs font-semibold uppercase tracking-[0.18em] {{ $onDark ? 'text-white' : 'text-gray-500' }}">
@foreach ($benefits as $i => $benefit)
    <span{!! $editor("benefits.{$i}", 'plain') !!}>{{ $benefit }}</span>@if (! $loop->last) <span aria-hidden="true" class="mx-2">·</span>@endif
@endforeach
</p>
@elseif ($style === 'pill-badges')
<ul data-trust-style="pill-badges" class="flex flex-wrap gap-2">
@foreach ($benefits as $i => $benefit)
    <li class="px-3 py-1.5 rounded-full border text-sm font-medium {{ $onDark ? 'border-white/30 text-white' : 'border-gray-300 text-gray-800' }}"{!! $editor("benefits.{$i}", 'plain') !!}>{{ $benefit }}</li>
@endforeach
</ul>
@elseif ($style === 'icon-box')
<ul data-trust-style="icon-box" class="space-y-2">
@foreach ($benefits as $i => $benefit)
    <li class="flex items-center gap-3 px-4 py-3 rounded-md border {{ $onDark ? 'border-white/15 bg-white/5 text-white' : 'border-gray-200 bg-white text-gray-800' }}">
        <svg class="w-4 h-4 flex-shrink-0" style="color: var(--brand-accent);" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
        <span class="font-medium"{!! $editor("benefits.{$i}", 'plain') !!}>{{ $benefit }}</span>
    </li>
@endforeach
</ul>
@endif
@endif
