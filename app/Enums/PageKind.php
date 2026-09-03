<?php

namespace App\Enums;

enum PageKind: string
{
    /**
     * Page types that are core rather than service pages.
     *
     * Single source of truth: the kind backfill migration and any seeding
     * path both read this, so a legal page added here is recognised
     * everywhere at once. The legal variants matter — a page wrongly
     * classified as a service gets a lead form injected into it by
     * PageRenderer::injectServiceLeadForm().
     *
     * @var list<string>
     */
    public const CORE_PAGE_TYPES = [
        'home', 'about', 'contact', 'projects',
        'privacy', 'privacy-policy', 'privacy-notice',
        'terms', 'terms-and-conditions', 'terms-of-service', 'terms-of-use',
        'cookies', 'cookie-policy',
        'legal', 'disclaimer', 'accessibility', 'sitemap',
    ];

    case Core = 'core';
    case Service = 'service';
    case Editorial = 'editorial';
    case Guide = 'guide';
    case CostGuide = 'cost_guide';
    case CaseStudy = 'case_study';
    case Hub = 'hub';
    case ProjectDetail = 'project_detail';

    public function label(): string
    {
        return match ($this) {
            self::Core => 'Core',
            self::Service => 'Service',
            self::Editorial => 'Editorial',
            self::Guide => 'Guide',
            self::CostGuide => 'Cost guide',
            self::CaseStudy => 'Case study',
            self::Hub => 'Hub',
            self::ProjectDetail => 'Project detail',
        };
    }
}
