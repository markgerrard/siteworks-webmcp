<?php

namespace App\Services;

use App\Enums\LogoConceptSource;
use App\Models\Preview;
use App\Models\Site;
use App\Services\Site\RichTextRenderer;
use App\Services\Site\ThemeResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PreviewRenderer
{
    public function __construct(
        protected ThemeResolver $themeResolver,
        protected RichTextRenderer $richText = new RichTextRenderer,
    ) {}

    public function build(Site $site, array $heroImages = []): Preview
    {
        if (config('site.use_versioned_renderer')) {
            Log::warning('PreviewRenderer hit while versioned-renderer flag is on — investigate caller', [
                'site_id' => $site->id ?? null,
                'caller' => array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 0, 5),
            ]);
        }

        $pages = [];
        $navLabels = [];
        foreach ($site->generatedPages as $page) {
            $pages[$page->page_type] = $this->orderSections($page->content_data);
            if ($page->nav_label) {
                $navLabels[$page->page_type] = $page->nav_label;
            }
        }

        $profile = $site->businessProfile?->profile_data ?? [];
        $theme = $this->themeResolver->resolve($site, $profile);

        return $site->previews()->create([
            'slug' => $this->generateSlug($site),
            'theme' => $site->theme,
            'snapshot' => [
                'pages' => $pages,
                'profile' => $profile,
                'theme' => $theme,
                'layout' => $site->preview_layout?->value ?? 'one_page',
                'nav_labels' => $navLabels,
                'hero_images' => array_filter($heroImages),
                'logo_url' => $this->resolveLogoUrl($site),
                'chatbot' => [
                    'system_prompt' => $profile['chatbot_system_prompt'] ?? '',
                    'welcome_pills' => $profile['chatbot_welcome_pills'] ?? [],
                    'enabled' => ($profile['chatbot_enabled'] ?? true) && ! empty($profile['chatbot_system_prompt']),
                ],
                'top_bar_enabled' => $profile['top_bar_enabled'] ?? true,
                'should_show_facebook_disclaimer' => $this->shouldShowFacebookDisclaimer($site),
                'project' => [
                    'business_name' => $site->business_name,
                    'business_type' => $site->business_type,
                    'location' => $site->location,
                ],
            ],
            'published_at' => now(),
        ]);
    }

    /**
     * Pick the logo URL to render in the preview header. Priority:
     *   1. The explicitly selected LogoConcept (if any)
     *   2. The detected logo (scraped from the prospect site)
     *   3. The first generated logo concept
     *   4. null — layout falls back to a text wordmark
     */
    private function resolveLogoUrl(Site $site): ?string
    {
        $selected = $site->logoConcepts()->where('is_selected', true)->first();
        if ($selected) {
            return $selected->url();
        }

        $detected = $site->logoConcepts()->where('source', LogoConceptSource::Detected->value)->first();
        if ($detected) {
            return $detected->url();
        }

        $generated = $site->logoConcepts()->where('source', LogoConceptSource::Generated->value)->orderBy('id')->first();

        return $generated?->url();
    }

    public function getTheme(string $handle): array
    {
        return $this->themeResolver->baseTheme($handle);
    }

    public static function availableThemes(): array
    {
        return ThemeResolver::availableThemes();
    }

    private function generateSlug(Site $site): string
    {
        $base = Str::slug($site->business_name);
        $random = Str::random(12);

        return "{$base}-{$random}";
    }

    private function shouldShowFacebookDisclaimer(Site $site): bool
    {
        $site->loadMissing(['importedMedia' => fn ($query) => $query
            ->where('source', 'facebook')
            ->whereIn('assigned_to', ['hero', 'project', 'face_reference'])]);

        return $site->importedMedia->isNotEmpty();
    }

    private function orderSections(array $content): array
    {
        // Content shape evolved from { hero: {...}, services: {...} } (legacy)
        // to { sections: [ { type: 'hero', ... }, ... ] }.
        // Normalise the list shape to an assoc keyed by type, then reuse the
        // legacy ordering logic so existing blades work unchanged.
        if (isset($content['sections']) && is_array($content['sections']) && array_is_list($content['sections'])) {
            $assoc = [];
            foreach ($content['sections'] as $section) {
                $type = $section['type'] ?? null;
                if (! is_string($type) || $type === '') {
                    continue;
                }
                $assoc[$type] = $section;
            }
            $content = $assoc + array_diff_key($content, ['sections' => null]);
        }

        // Legacy blades do {{ $field }} which breaks on TipTap doc arrays.
        // Flatten any {type:'doc', content:[...]} payload to an HTML string
        // via RichTextRenderer so blades receive a string, not a structure.
        $content = $this->flattenRichText($content);

        $order = ['hero', 'services', 'trust', 'story', 'values', 'details', 'contact_form', 'intro', 'process', 'faqs', 'benefits', 'article-body', 'cta', 'seo', 'geo'];
        $ordered = [];
        foreach ($order as $name) {
            if (isset($content[$name])) {
                $ordered[$name] = $content[$name];
            }
        }
        foreach ($content as $name => $data) {
            if (! isset($ordered[$name])) {
                $ordered[$name] = $data;
            }
        }

        return $ordered;
    }

    /**
     * Recursively walk a structure. Any array with shape
     * { type: 'doc', content: [...] } is replaced with rendered HTML. All
     * other arrays are preserved (recursed into). Scalars pass through.
     */
    private function flattenRichText(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (($value['type'] ?? null) === 'doc' && isset($value['content']) && is_array($value['content'])) {
            // Render to HTML then strip tags: legacy blades use {{ }} which
            // escape output, so emitting HTML would show raw markup in the
            // preview. Plain text is the safe common denominator. The new
            // versioned renderer (__site_v2) has its own rich-text rendering.
            return trim(strip_tags($this->richText->render($value)));
        }

        foreach ($value as $k => $v) {
            $value[$k] = $this->flattenRichText($v);
        }

        return $value;
    }
}
