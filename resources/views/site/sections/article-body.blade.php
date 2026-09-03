@php
    /** @var array $section */
    $body = $section['body'] ?? '';
@endphp

<section class="site-section site-section--article-body py-12 md:py-16">
    <div class="mx-auto max-w-3xl px-4">
        <div class="prose prose-lg max-w-none">
            {!! $body !!}
        </div>
    </div>
</section>
