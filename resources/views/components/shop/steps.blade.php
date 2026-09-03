@props(['current' => 'cart'])

@php
    $sequence = [
        'cart' => ['label' => 'Cart', 'href' => url('/shop/cart')],
        'details' => ['label' => 'Details', 'href' => url('/shop/checkout')],
        'payment' => ['label' => 'Payment', 'href' => null],
    ];
    $keys = array_keys($sequence);
    $currentIndex = array_search($current, $keys, true);
    if ($currentIndex === false) {
        $currentIndex = 0;
    }
@endphp
<nav {{ $attributes->merge(['aria-label' => 'Checkout steps']) }}>
    <ol class="flex flex-wrap items-center gap-x-3 gap-y-1 list-none m-0 p-0">
        @foreach ($sequence as $key => $step)
            @php
                $index = (int) array_search($key, $keys, true);
                $number = $index + 1;
                $isCurrent = $index === $currentIndex;
                $isPast = $index < $currentIndex;
            @endphp
            <li @if ($isCurrent) aria-current="step" @endif>
                @if ($isPast && filled($step['href']))
                    <a href="{{ $step['href'] }}" style="color: var(--color-text-muted)">{{ $number }} {{ $step['label'] }}</a>
                @else
                    <span style="color: {{ $isCurrent ? 'var(--color-text)' : 'var(--color-text-muted)' }}">{{ $number }} {{ $step['label'] }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
