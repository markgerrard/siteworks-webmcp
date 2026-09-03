<?php

namespace App\Enums;

/**
 * Controls on which page types the lead_form section is rendered.
 *
 * Stored in business_profiles.profile_data.lead_form_policy (string).
 * See BusinessProfile::leadFormPolicy() for the resolution logic.
 *
 * Values:
 *   off           — lead_form removed everywhere; only contact page handles enquiries
 *   home          — lead_form on home page only
 *   home_services — lead_form on home + all service pages (recommended for trade sites)
 *   all           — home + service pages (about is always excluded per spec)
 *
 * Note: "all" and "home_services" are intentionally identical in effect
 * because the About page is always excluded regardless of policy.
 */
enum LeadFormPolicy: string
{
    case Off = 'off';
    case Home = 'home';
    case HomeServices = 'home_services';
    case All = 'all';

    /**
     * Whether the policy includes lead_form on the home page.
     */
    public function includesHome(): bool
    {
        return $this !== self::Off;
    }

    /**
     * Whether the policy includes lead_form on service pages.
     */
    public function includesServices(): bool
    {
        return $this === self::HomeServices || $this === self::All;
    }

    /**
     * Whether the about page should receive a cta_band section.
     * Always true when policy is not Off (about never gets a lead_form).
     */
    public function includesCtaBandOnAbout(): bool
    {
        return $this !== self::Off;
    }

    /**
     * Archetype-based default: trade/service archetypes get home_services;
     * brochure-leaning and unknown archetypes get home.
     */
    public static function defaultForArchetype(Archetype $archetype): self
    {
        return match ($archetype) {
            Archetype::EmergencyTrade,
            Archetype::LocalService => self::HomeServices,
            // SaaS sites use a single home-page demo-request form; service
            // sub-pages are feature/pricing oriented and don't need a form.
            Archetype::SaasPlatform => self::Home,
            default => self::Home,
        };
    }

    /**
     * Human-readable label for admin UI dropdowns.
     */
    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off (contact page only)',
            self::Home => 'Home page only',
            self::HomeServices => 'Home + Service pages (recommended)',
            self::All => 'All content pages',
        };
    }
}
