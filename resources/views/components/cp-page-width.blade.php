@props(['width' => 'full'])

@if ($width === 'page')
    <div {{ $attributes->class('w-full lg:max-w-[62.5rem] lg:mx-auto') }} data-cp-width="page">{{ $slot }}</div>
@else{{ $slot }}@endif
