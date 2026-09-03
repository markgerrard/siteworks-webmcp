<?php

namespace App\Enums;

/**
 * Closed enum over the six site archetypes that drive home-page composition.
 *
 * Exact string values are governed by Contract 3 — the Python
 * profiler emits these literal strings and consumers MUST default to
 * LocalService when encountering an unknown value.
 */
enum Archetype: string
{
    // plumber, locksmith, boiler-breakdown, recovery, 24/7 pest control
    case EmergencyTrade = 'emergency_trade';

    // joiner, cabinet maker, upholsterer, blacksmith, heritage trades
    case TraditionalCraftsman = 'traditional_craftsman';

    // bathroom designer, architect, interior designer
    case PremiumSpecialist = 'premium_specialist';

    // gardener, cleaner, window cleaner, dog walker, painter-decorator
    // (also the safe fallback when archetype is missing/invalid)
    case LocalService = 'local_service';

    // shop, salon, pub, cafe, studio (location-led)
    case RetailVenue = 'retail_venue';

    // accountant, solicitor, consultancy, coach (credentials-led)
    case ProfessionalService = 'professional_service';

    // SaaS product, software platform, web app (digital-product-led)
    case SaasPlatform = 'saas_platform';

    /**
     * Parse a raw string value emitted by the profiler. Unknown/null values
     * fall back to LocalService per Contract 3.
     */
    public static function fromProfile(?string $value): self
    {
        return self::tryFrom($value ?? '') ?? self::LocalService;
    }

    /**
     * Copy for the phone_cta_strip section (oversized phone-number band).
     *
     * The strip's blade was originally written for emergency trades —
     * "24/7 Emergency Call-Out" / "Rapid response across our coverage
     * area" — and the hardcoded blade fallback bled that framing onto
     * every non-emergency site that injected the strip without
     * overrides. Each archetype now declares its own framing here:
     * the strip injection callers populate the section data from this
     * map, and editor overrides still win (the blade reads
     * $section['title'] / $section['subtitle'] first).
     *
     * @return array{title: string, subtitle: string}
     */
    public function phoneCtaCopy(): array
    {
        return match ($this) {
            self::EmergencyTrade => [
                'title' => '24/7 Emergency Call-Out',
                'subtitle' => 'Rapid response across our coverage area',
            ],
            self::TraditionalCraftsman => [
                'title' => 'Talk to our workshop',
                'subtitle' => 'Call to discuss your commission',
            ],
            self::PremiumSpecialist => [
                'title' => 'Book a consultation',
                'subtitle' => 'Speak to our team about your project',
            ],
            self::LocalService => [
                'title' => 'Call about your project',
                'subtitle' => "We're happy to talk it through",
            ],
            self::RetailVenue => [
                'title' => 'Get in touch',
                'subtitle' => 'Call ahead or pop in',
            ],
            self::ProfessionalService => [
                'title' => 'Speak to our team',
                'subtitle' => 'Call for a no-obligation conversation',
            ],
            self::SaasPlatform => [
                'title' => 'Book a demo',
                'subtitle' => 'See it in action — no commitment needed',
            ],
        };
    }
}
