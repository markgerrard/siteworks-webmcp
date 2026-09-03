<?php

namespace App\Support;

use App\Enums\ProjectItemSource;
use App\Models\ProjectItem;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Resolves vocabulary for projects-page sections based on the site's
 * honest-framing state and each item's source.
 *
 * When honest framing is OFF (default for previews):
 *   - Headings always read "Recent Work" / "Case Studies"
 *   - No "Example" badge on any item
 *
 * When honest framing is ON:
 *   - Sections containing ANY ai_generated item use example vocabulary
 *   - AI-generated items get an "Example" badge
 *   - Sourced items (agent/client/FB/IG uploads) render with normal vocabulary;
 *     once a section's items are fully sourced, the heading upgrades.
 */
class ProjectSectionVocabulary
{
    public function __construct(
        public readonly bool $honestFraming,
    ) {}

    public static function for(Site $site): self
    {
        return new self($site->effectiveHonestFraming());
    }

    public function galleryHeading(Collection $items): string
    {
        return $this->shouldUseExampleVocabulary($items)
            ? 'Example Projects'
            : 'Recent Work';
    }

    public function galleryEyebrow(Collection $items): string
    {
        return $this->shouldUseExampleVocabulary($items)
            ? 'Examples'
            : 'Our Work';
    }

    public function caseStudyHeading(Collection $items): string
    {
        return $this->shouldUseExampleVocabulary($items)
            ? 'Example Project Highlights'
            : 'Case Studies';
    }

    public function caseStudyBlockLabel(ProjectItem $item): string
    {
        return $this->shouldShowExampleBadge($item)
            ? 'Example Project Highlight'
            : 'Case Study';
    }

    public function shouldShowExampleBadge(ProjectItem $item): bool
    {
        return $this->honestFraming && $item->source === ProjectItemSource::AiGenerated;
    }

    /**
     * @param  Collection<int, ProjectItem>  $items
     */
    public function countLine(int $n, Collection $items): string
    {
        return $this->shouldUseExampleVocabulary($items)
            ? "Showing {$n} example projects"
            : "Showing {$n} projects";
    }

    /**
     * @param  Collection<int, ProjectItem>  $items
     */
    public function allLabel(Collection $items): string
    {
        return $this->shouldUseExampleVocabulary($items)
            ? 'example projects'
            : 'projects';
    }

    /**
     * A section "needs example vocabulary" when honest framing is on AND
     * any item in the section is AI-generated. Mixed sections (some AI,
     * some sourced) use the conservative vocabulary — once all items in
     * the section are non-AI, it upgrades to the marketing vocabulary.
     */
    protected function shouldUseExampleVocabulary(Collection $items): bool
    {
        if (! $this->honestFraming) {
            return false;
        }

        return $items->contains(fn (ProjectItem $i) => $i->source === ProjectItemSource::AiGenerated);
    }
}
