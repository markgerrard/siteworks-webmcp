@php
    $productName = $product['product_detail']['name'] ?? $product['slug'];
    $pageTitle = 'Write a review — '.$productName;
@endphp
<x-shop.layout :site="$site" :title="$pageTitle">
    <div class="px-4 sm:px-6 lg:px-8 py-6 max-w-xl">
        <h1 class="text-2xl font-bold mb-4">Write a review</h1>
        <p class="mb-6" style="color: var(--color-text-muted);">{{ $productName }}</p>

        <form method="POST" action="{{ route('shop.product.reviews.store', $product['slug']) }}" class="space-y-4">
            @csrf
            <input type="text" name="{{ $honeypotField }}" tabindex="-1" autocomplete="off"
                   style="position: absolute; left: -9999px;" aria-hidden="true">

            <label class="block">
                <span class="text-sm font-medium">Name</span>
                <input
                    type="text"
                    name="author_name"
                    required
                    maxlength="60"
                    value="{{ old('author_name') }}"
                    class="mt-1 w-full p-2"
                    style="min-height: 44px; border: 1px solid var(--color-border); border-radius: var(--radius-button); background-color: var(--color-surface); color: var(--color-text);"
                >
                @error('author_name')<p class="mt-1 text-sm">{{ $message }}</p>@enderror
            </label>

            <fieldset>
                <legend class="text-sm font-medium">Rating</legend>
                <div class="mt-2 flex gap-3">
                    @for ($rating = 1; $rating <= 5; $rating++)
                        <label class="inline-flex items-center gap-1">
                            <input type="radio" name="rating" value="{{ $rating }}" @checked((int) old('rating', 5) === $rating) required>
                            <span>{{ $rating }}</span>
                        </label>
                    @endfor
                </div>
                @error('rating')<p class="mt-1 text-sm">{{ $message }}</p>@enderror
            </fieldset>

            <label class="block">
                <span class="text-sm font-medium">Title</span>
                <input
                    type="text"
                    name="title"
                    required
                    maxlength="80"
                    value="{{ old('title') }}"
                    class="mt-1 w-full p-2"
                    style="min-height: 44px; border: 1px solid var(--color-border); border-radius: var(--radius-button); background-color: var(--color-surface); color: var(--color-text);"
                >
                @error('title')<p class="mt-1 text-sm">{{ $message }}</p>@enderror
            </label>

            <label class="block">
                <span class="text-sm font-medium">Review</span>
                <textarea
                    name="body"
                    required
                    maxlength="2000"
                    rows="6"
                    class="mt-1 w-full p-2"
                    style="border: 1px solid var(--color-border); border-radius: var(--radius-button); background-color: var(--color-surface); color: var(--color-text);"
                >{{ old('body') }}</textarea>
                @error('body')<p class="mt-1 text-sm">{{ $message }}</p>@enderror
            </label>

            <button
                type="submit"
                class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                style="display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 0.75rem 1.5rem; background-color: var(--color-primary); color: var(--color-text-on-primary); border-radius: var(--radius-button); outline-color: var(--color-accent);"
            >Submit review</button>
        </form>
    </div>
</x-shop.layout>
