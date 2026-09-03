<?php

namespace App\Services\Site;

use App\Enums\Archetype;
use App\Enums\LeadFormPolicy;
use App\Models\Site;

/**
 * Apply an archetype recipe to freshly-translated home-page content_data.
 *
 * Input: sections-array shape produced by ContentShapeTranslator
 *   ['sections' => [['type' => 'hero', ...], ['type' => 'services', ...], ...], 'meta' => ...]
 *
 * Output: sections rebuilt in recipe order, with AI-generated content
 * preserved for overlapping section types and empty placeholders inserted
 * for recipe sections the content pipeline doesn't yet generate (e.g.
 * phone_cta_strip, opening_hours_strip — those Blade templates self-gate on
 * profile data).
 *
 * Only operates on NEW content at generation time. Existing published sites
 * keep their saved sections untouched because they don't re-flow through
 * GenerateContentJob.
 */
class ArchetypeComposer
{
    public function __construct(protected ArchetypeRecipe $recipe) {}

    /**
     * @param  array<string, mixed>  $contentData  Sections-array shape with
     *                                             optional 'meta' key. Non-'sections'/'meta' keys are preserved.
     * @return array<string, mixed>
     */
    public function compose(array $contentData, Archetype $archetype, ?LeadFormPolicy $policy = null): array
    {
        $recipe = $this->recipe->for($archetype);
        $generatedByType = $this->indexSectionsByType($contentData['sections'] ?? []);
        $phoneCtaCopy = $archetype->phoneCtaCopy();

        $weights = $recipe['weights'];
        $sections = $recipe['sections'];

        // Inject lead_form before the final cta when the policy asks for a
        // home-page form but the recipe doesn't list one. LeadFormPolicy
        // already says "every archetype gets a home form unless policy is
        // Off", but only the local_service recipe actually lists lead_form —
        // emergency_trade, premium_specialist, traditional_craftsman,
        // retail_venue, and professional_service silently drop it because
        // this loop below only knew how to REMOVE lead_form, not add it.
        // Recipes that already contain lead_form are left untouched.
        if ($policy !== null && $policy->includesHome() && ! in_array('lead_form', $sections, true)) {
            $ctaIdx = array_search('cta', $sections, true);
            if ($ctaIdx === false) {
                $sections[] = 'lead_form';
            } else {
                array_splice($sections, $ctaIdx, 0, ['lead_form']);
            }
        }

        $composed = [];

        foreach ($sections as $type) {
            // Honour lead_form_policy: remove lead_form from the home recipe
            // when the policy is 'off'. All other policies keep it as-is.
            if ($type === 'lead_form' && $policy !== null && ! $policy->includesHome()) {
                continue;
            }

            $section = $generatedByType[$type] ?? ['type' => $type];

            // phone_cta_strip carries archetype-specific copy. Without this
            // populate, the recipe-driven home (currently emergency_trade
            // only) would render the blade's neutral fallback rather than
            // the archetype-correct "24/7 Emergency Call-Out" framing.
            // Mirrors the injectServiceLeadForm path on service / projects
            // pages — single source of truth in Archetype::phoneCtaCopy().
            if ($type === 'phone_cta_strip') {
                if (! isset($section['title']) || $section['title'] === '') {
                    $section['title'] = $phoneCtaCopy['title'];
                }
                if (! isset($section['subtitle']) || $section['subtitle'] === '') {
                    $section['subtitle'] = $phoneCtaCopy['subtitle'];
                }
            }

            if (isset($weights[$type]) && is_array($weights[$type])) {
                // Merge weight keys into the section's payload. Existing
                // AI-generated fields win over recipe defaults — weights
                // should act as *hints* for later prompt tuning, not
                // clobber the content writer's output.
                $section = array_merge($weights[$type], $section);
            }

            // Hydrate suburb_list (user-facing "Areas covered") from
            // meta.geo.nearby_areas if it's present and the section
            // doesn't already carry its own areas. The content writer writes the
            // list at the page-meta level per page_content.md; the
            // section template reads from section.areas. Without this
            // wire-up the section rendered as null — the data was in
            // the payload, just in the wrong slot.
            if ($type === 'suburb_list' && empty($section['areas']) && empty($section['items'])) {
                $nearby = $contentData['meta']['geo']['nearby_areas'] ?? null;
                if (is_array($nearby) && $nearby !== []) {
                    $section['areas'] = array_values($nearby);
                }
            }

            $composed[] = $section;
        }

        $contentData['sections'] = $composed;

        return $contentData;
    }

    /**
     * Inject the cta_band section at the bottom of an about page's sections list
     * when the lead_form_policy is not Off. About pages never get a lead_form;
     * the cta_band is the replacement conversion section.
     *
     * @param  array<string, mixed>  $contentData  Already-translated about page content.
     * @return array<string, mixed>
     */
    public function injectCtaBandOnAbout(array $contentData, LeadFormPolicy $policy): array
    {
        if (! $policy->includesCtaBandOnAbout()) {
            return $contentData;
        }

        $sections = $contentData['sections'] ?? [];

        // Avoid double-injection on re-runs.
        foreach ($sections as $section) {
            if (($section['type'] ?? null) === 'cta_band') {
                return $contentData;
            }
        }

        // Strip any existing `cta` section — the About page content usually
        // includes a generic generated phone-CTA band, which would double
        // up with our more conversion-focused cta_band. Keep only one.
        $sections = array_values(array_filter(
            $sections,
            fn ($s) => ($s['type'] ?? null) !== 'cta',
        ));

        $sections[] = [
            'type' => 'cta_band',
            'title' => 'Ready to Start Your Project?',
            'subtitle' => 'Get in touch today for a free, no-obligation quote.',
            'cta_label' => 'Get a free quote',
            'cta_url' => '#contact',
        ];

        $contentData['sections'] = $sections;

        return $contentData;
    }

    /**
     * Resolve whether a site should include a projects page.
     *
     * Returns the explicit override when set; otherwise falls back to the
     * business profile's has_visual_portfolio hint.
     */
    public function includesProjectsPage(Site $site): bool
    {
        if ($site->projects_page_enabled !== null) {
            return $site->projects_page_enabled;
        }

        return (bool) $site->businessProfile?->has_visual_portfolio;
    }

    /**
     * Inject a reviews_summary section on the home page after the last
     * "what we do / why us" anchor section, when the auto-include gate passes.
     *
     * Conversion-order rule: testimonials read better AFTER the visitor has
     * seen what's offered (Services, Why Choose Us, Benefits) but BEFORE the
     * action sections (Process, Contact). Falls back to index 1 (after hero)
     * if no anchor is present.
     *
     * @param  array<string, mixed>  $contentData
     * @return array<string, mixed>
     */
    public function injectReviewsSummaryOnHome(array $contentData, Site $site): array
    {
        if (! $this->reviewsGatePasses($site)) {
            return $contentData;
        }
        $sections = $contentData['sections'] ?? [];
        // Avoid double-injection on re-runs.
        foreach ($sections as $section) {
            if (($section['type'] ?? null) === 'reviews_summary') {
                return $contentData;
            }
        }

        $insertAt = 1;
        $anchors = ['services', 'why_choose_us', 'benefits', 'values', 'features', 'trust'];
        foreach ($sections as $i => $s) {
            if (in_array($s['type'] ?? '', $anchors, true)) {
                $insertAt = $i + 1;
            }
        }

        array_splice($sections, $insertAt, 0, [['type' => 'reviews_summary', 'provider' => 'google',
            'display_style' => 'carousel', 'show_rating' => true, 'show_review_count' => true,
            'show_attribution' => true, 'max_items' => 20]]);
        $contentData['sections'] = $sections;

        return $contentData;
    }

    /**
     * Inject a thin reviews_badge bar at index 1 (directly under the hero)
     * for instant third-party trust signal — five gold stars + count + Google
     * "G" mark — before the visitor scrolls into Services. Always sits above
     * any reviews_summary the auto-include also adds further down the page.
     *
     * @param  array<string, mixed>  $contentData
     * @return array<string, mixed>
     */
    public function injectReviewsBadgeOnHome(array $contentData, Site $site): array
    {
        if (! $this->reviewsGatePasses($site)) {
            return $contentData;
        }
        $sections = $contentData['sections'] ?? [];
        foreach ($sections as $section) {
            if (($section['type'] ?? null) === 'reviews_badge') {
                return $contentData;
            }
        }
        array_splice($sections, 1, 0, [['type' => 'reviews_badge', 'provider' => 'google']]);
        $contentData['sections'] = $sections;

        return $contentData;
    }

    /**
     * Inject a reviews section after the intro section on the about page
     * when the auto-include gate passes for this site.
     *
     * @param  array<string, mixed>  $contentData
     * @return array<string, mixed>
     */
    public function injectReviewsOnAbout(array $contentData, Site $site): array
    {
        if (! $this->reviewsGatePasses($site)) {
            return $contentData;
        }
        $sections = $contentData['sections'] ?? [];
        // Avoid double-injection on re-runs.
        foreach ($sections as $section) {
            if (($section['type'] ?? null) === 'reviews') {
                return $contentData;
            }
        }
        // Two-slot pattern, mirroring home: a thin reviews_badge directly
        // under the hero (instant trust signal, doesn't gate the story) and
        // the full carousel AFTER the page's content, immediately before the
        // trailing CTA block ("proof before the ask"). The old single-slot
        // rule ("after intro, else index 1") degraded to hero-then-carousel
        // on about pages without an intro section, parking a full-width
        // reviews slab in front of the actual content.
        $ctaTypes = ['cta_band', 'cta', 'lead_form', 'contact_form'];
        $idx = 0;
        for ($i = count($sections) - 1; $i >= 0; $i--) {
            if (! in_array($sections[$i]['type'] ?? '', $ctaTypes, true)) {
                $idx = $i + 1;
                break;
            }
        }
        array_splice($sections, $idx, 0, [['type' => 'reviews', 'provider' => 'google',
            'display_style' => 'carousel', 'show_rating' => true, 'show_review_count' => true,
            'show_attribution' => true, 'max_items' => 5]]);

        $hasBadge = collect($sections)->contains(fn ($s) => ($s['type'] ?? null) === 'reviews_badge');
        if (! $hasBadge) {
            array_splice($sections, 1, 0, [['type' => 'reviews_badge', 'provider' => 'google']]);
        }

        $contentData['sections'] = $sections;

        return $contentData;
    }

    /**
     * Evaluate whether the reviews auto-include gate passes for a given site.
     *
     * Checks: feature flag, trusted place ID source, minimum rating, minimum count.
     */
    private function reviewsGatePasses(Site $site): bool
    {
        if (! config('site.reviews_auto_include_enabled')) {
            return false;
        }
        // scrape_link requires fuzzy-name match against business_name (spec §4.3)
        // before it can be trusted; that check needs the resolved place name
        // stored on the site, which is a follow-up task. Until then, scraped
        // candidates require explicit editor confirmation, so we treat them as
        // effectively manual — the agent must promote them in the editor.
        $trusted = ['manual_id', 'manual_url'];
        if (! in_array($site->review_place_id_source, $trusted, true)) {
            return false;
        }
        $cache = $site->reviews_cache ?? null;
        if (! is_array($cache)) {
            return false;
        }
        if ((int) ($cache['user_ratings_total'] ?? 0) < 3) {
            return false;
        }
        if ((float) ($cache['rating'] ?? 0) < 4.0) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<string, array<string, mixed>>
     */
    private function indexSectionsByType(array $sections): array
    {
        $out = [];
        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }
            $type = $section['type'] ?? null;
            if (is_string($type) && $type !== '' && ! isset($out[$type])) {
                $out[$type] = $section;
            }
        }

        return $out;
    }
}
