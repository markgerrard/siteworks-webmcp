<figure class="flex h-full flex-col gap-4 rounded-xl border p-5" style="background-color: var(--color-surface); border-color: var(--color-border);">
    <blockquote class="text-base leading-relaxed" style="color: var(--color-text);">“{{ $review['body'] }}”</blockquote>
    <figcaption class="mt-auto flex flex-wrap items-baseline gap-x-2 gap-y-1 text-sm" style="color: var(--color-text-muted);">
        <span class="font-semibold" style="color: var(--color-text);">{{ $review['author'] }}</span>
        <time datetime="{{ $review['created_at'] }}">{{ \Illuminate\Support\Carbon::parse($review['created_at'])->diffForHumans() }}</time>
        @if (($review['source'] ?? null) === 'product' && ! empty($review['product_name']) && ! empty($review['product_url']))
            <a href="{{ $review['product_url'] }}" class="underline underline-offset-2">{{ $review['product_name'] }}</a>
        @endif
    </figcaption>
</figure>
