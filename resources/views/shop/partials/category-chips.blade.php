@php
    $categories = $categories ?? [];
    $current = $current ?? null;
@endphp
<nav aria-label="Browse by category" class="flex flex-wrap gap-2">
    @foreach ($categories as $cat)
        <a href="{{ \App\Support\Shop\ShopUrls::collection($cat['path'] ?? $cat['slug']) }}"
           @if ($current === $cat['slug']) aria-current="true" @endif
           class="inline-flex items-center rounded-full px-3 py-1.5 text-sm font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
           style="{{ $current === $cat['slug']
               ? 'background-color: var(--color-primary); color: var(--color-text-on-primary); outline-color: var(--color-accent);'
               : 'border: 1px solid var(--color-border); color: var(--color-text); background-color: var(--color-surface); outline-color: var(--color-accent);' }}">{{ $cat['name'] }}</a>
    @endforeach
</nav>
