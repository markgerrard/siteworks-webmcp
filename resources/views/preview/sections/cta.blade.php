<div class="py-20 lg:py-24 relative overflow-hidden" style="background-color: var(--brand-primary);">
    {!! \App\Support\Textures\TextureLayer::html($siteTexture ?? null, $section ?? $data ?? null, true, $project ?? $site ?? null) !!}
    <div class="absolute inset-0 bg-gradient-to-r from-black/30 to-black/10"></div>
    <div class="relative max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
        <h2 class="text-3xl md:text-4xl lg:text-5xl font-extrabold text-white mb-4 leading-tight text-pretty">
            {!! preg_replace('/\s+(\S+)$/', '&nbsp;$1', e($data['heading'] ?? '')) !!}
        </h2>
        @if (!empty($data['subheading']))
            <p class="text-lg md:text-xl text-white/80 mb-10 max-w-2xl mx-auto leading-relaxed">
                {{ $data['subheading'] }}
            </p>
        @endif
        @php
            $ctaContactHref = ($layout ?? 'one_page') === 'multi_page'
                ? ($pageUrl ?? fn ($p) => route('preview.page', [$previewSlug ?? '', $p]))('contact')
                : '#contact';
        @endphp
        @if (!empty($data['cta_label']))
            <a href="{{ $ctaContactHref }}"
               class="inline-flex items-center gap-2 font-bold px-10 py-4 rounded-md shadow-xl text-lg transition-all hover:shadow-2xl hover:scale-105 text-white"
               style="background-color: var(--brand-accent);">
                {{ $data['cta_label'] }}
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
            </a>
        @endif
    </div>
</div>
