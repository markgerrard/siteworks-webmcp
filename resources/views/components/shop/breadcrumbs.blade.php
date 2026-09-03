@props(['trail'])

@php
    $crumbs = array_values($trail);
@endphp
<nav {{ $attributes->merge(['aria-label' => 'Breadcrumb', 'class' => 'text-sm']) }}>
    <ol class="flex flex-wrap items-center gap-x-2 gap-y-1 list-none m-0 p-0">
        @foreach ($crumbs as $index => $crumb)
            @php
                $isLast = $index === array_key_last($crumbs);
                $label = $crumb['label'] ?? '';
                $href = $crumb['href'] ?? null;
            @endphp
            <li
                @if ($isLast) aria-current="page" @endif
                class="flex items-center after:ms-2 after:content-['›'] after:[color:var(--color-text-muted)] last:after:content-none"
            >
                @if ($isLast || blank($href))
                    <span class="break-words" style="color: var(--color-text)">{{ $label }}</span>
                @else
                    <a href="{{ $href }}" class="break-words" style="color: var(--color-text-muted)">{{ $label }}</a>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
