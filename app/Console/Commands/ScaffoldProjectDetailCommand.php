<?php

namespace App\Console\Commands;

use App\Enums\PageKind;
use App\Enums\PageOrigin;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\ProjectItem;
use App\Models\SiteMedia;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Services\Site\PageLayoutRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ScaffoldProjectDetailCommand extends Command
{
    protected $signature = 'site:scaffold-project-detail
        {site : Site id or domain}
        {project-item : ProjectItem id}
        {--preset= : Project detail layout preset key}
        {--dry-run : Report the detail page without writing it}';

    protected $description = 'Scaffold a draft detail page from a ProjectItem.';

    public function handle(PageLayoutRegistry $registry): int
    {
        $siteToken = $this->argument('site');
        $itemToken = $this->argument('project-item');

        if (! is_string($siteToken) || $siteToken === '' || ! is_string($itemToken) || ! ctype_digit($itemToken)) {
            $this->error('A valid site id or domain and numeric ProjectItem id are required.');

            return self::FAILURE;
        }

        $site = $this->findSite($siteToken);
        if ($site === null) {
            $this->error("Site [{$siteToken}] not found.");

            return self::FAILURE;
        }

        $item = ProjectItem::query()->find((int) $itemToken);
        if ($item === null) {
            $this->error("Project item [{$itemToken}] not found.");

            return self::FAILURE;
        }

        if ((int) $item->site_id !== (int) $site->id) {
            $this->error("Project item [{$item->id}] does not belong to site [{$site->id}].");

            return self::FAILURE;
        }

        $preset = $this->preset($site, $registry);
        if ($preset === null) {
            return self::FAILURE;
        }

        if ($item->detail_page_id !== null) {
            return $this->reportExisting($item);
        }

        if ($this->option('dry-run')) {
            return $this->dryRun($site, $item, $preset);
        }

        try {
            $result = DB::transaction(function () use ($site, $item, $preset): array {
                Site::query()->lockForUpdate()->findOrFail($site->id);

                $lockedItem = ProjectItem::query()->lockForUpdate()->findOrFail($item->id);
                if ((int) $lockedItem->site_id !== (int) $site->id) {
                    throw new \DomainException("Project item [{$lockedItem->id}] does not belong to site [{$site->id}].");
                }

                if ($lockedItem->detail_page_id !== null) {
                    return ['created' => false, 'page' => $lockedItem->detailPage()->firstOrFail()];
                }

                $projectsPage = $this->projectsPageQuery($site)->lockForUpdate()->first();
                if ($projectsPage === null) {
                    throw new \DomainException("Site [{$site->id}] has no active root projects page.");
                }

                $pageType = $this->availablePageType($site, $projectsPage, (string) $lockedItem->title);
                $contentData = $this->contentData($lockedItem);
                $navLabel = trim((string) $lockedItem->title) !== ''
                    ? (string) $lockedItem->title
                    : 'Project '.$lockedItem->id;
                // generated_pages.nav_label is varchar(30); real item titles
                // routinely exceed it, and a mid-title cut can strand
                // punctuation in the breadcrumb (both found on first live
                // scaffold).
                $navLabel = rtrim(mb_substr($navLabel, 0, 30), " \t,;:.\u{2013}\u{2014}-");

                $page = GeneratedPage::query()->create([
                    'site_id' => $site->id,
                    'parent_id' => $projectsPage->id,
                    'page_type' => $pageType,
                    'kind' => PageKind::ProjectDetail,
                    'layout_preset_key' => $preset === 'inherit' ? null : $preset,
                    'origin' => PageOrigin::Pipeline,
                    'nav_label' => $navLabel,
                    'content_data' => $contentData,
                    'version' => 1,
                    'model_used' => 'project-detail-scaffold',
                    'status' => PageStatus::Draft,
                    'hero_source' => 'shared',
                ]);

                $revision = PageRevision::query()->create([
                    'page_id' => $page->id,
                    'content_data' => $contentData,
                    'ai_generated' => false,
                    'created_at' => now(),
                ]);

                $page->update(['draft_revision_id' => $revision->id]);
                $lockedItem->update(['detail_page_id' => $page->id]);

                return ['created' => true, 'page' => $page->refresh()];
            }, 3);
        } catch (\DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        /** @var GeneratedPage $page */
        $page = $result['page'];
        if (! $result['created']) {
            $this->info("Already scaffolded: {$page->page_type} (page {$page->id}).");

            return self::SUCCESS;
        }

        $this->info("Created draft project detail page {$page->page_type} (page {$page->id}, preset {$preset}).");

        return self::SUCCESS;
    }

    private function dryRun(Site $site, ProjectItem $item, string $preset): int
    {
        $projectsPage = $this->projectsPageQuery($site)->first();
        if ($projectsPage === null) {
            $this->error("Site [{$site->id}] has no active root projects page.");

            return self::FAILURE;
        }

        $pageType = $this->availablePageType($site, $projectsPage, (string) $item->title);
        $this->info('Dry run: would create draft project detail page.');
        $this->line("page_type={$pageType}");
        $this->line("parent_id={$projectsPage->id}");
        $this->line('kind='.PageKind::ProjectDetail->value);
        $this->line("preset={$preset}");

        return self::SUCCESS;
    }

    private function reportExisting(ProjectItem $item): int
    {
        $page = $item->detailPage()->first();
        if ($page === null) {
            $this->error("Project item [{$item->id}] has an invalid detail page link.");

            return self::FAILURE;
        }

        $this->info("Already scaffolded: {$page->page_type} (page {$page->id}).");

        return self::SUCCESS;
    }

    /**
     * Returns the EXPLICIT preset key to pin, or the sentinel 'inherit'
     * when no --preset was given — an unpinned page follows the projects
     * personality via resolveForPage. Pinning 'classic' here instead of the
     * sentinel would silently block that inheritance.
     */
    private function preset(Site $site, PageLayoutRegistry $registry): ?string
    {
        $requested = $this->option('preset');
        if (! is_string($requested) || $requested === '') {
            return 'inherit';
        }
        $preset = $requested;
        $options = $registry->optionsFor($site, 'project_detail');

        if (! is_string($preset) || ! array_key_exists($preset, $options)) {
            $label = is_string($preset) && $preset !== '' ? $preset : '(none)';
            $this->error("Unknown project detail preset [{$label}].");

            if ($options !== []) {
                $this->line('Available: '.implode(', ', array_keys($options)));
            }

            return null;
        }

        return $preset;
    }

    private function projectsPageQuery(Site $site): Builder
    {
        return GeneratedPage::query()
            ->where('site_id', $site->id)
            ->whereNull('parent_id')
            ->where('page_type', 'projects')
            ->where('status', '!=', PageStatus::Archived->value)
            ->orderBy('id');
    }

    private function availablePageType(Site $site, GeneratedPage $parent, string $title): string
    {
        $baseLeaf = Str::slug($title);
        if ($baseLeaf === '') {
            $baseLeaf = 'project';
        }

        $maximumLeafLength = 200 - Str::length($parent->page_type) - 1;
        $baseLeaf = rtrim(Str::substr($baseLeaf, 0, $maximumLeafLength), '-');
        $leaf = $baseLeaf;
        $suffix = 2;

        while (GeneratedPage::slugIsTaken($site, $leaf, $parent)) {
            $suffixText = '-'.$suffix++;
            $trimmedBase = rtrim(Str::substr($baseLeaf, 0, $maximumLeafLength - Str::length($suffixText)), '-');
            $leaf = $trimmedBase.$suffixText;
        }

        return $parent->page_type.'/'.$leaf;
    }

    /**
     * @return array{sections: list<array<string, mixed>>}
     */
    private function contentData(ProjectItem $item): array
    {
        $galleryImageIds = $item->galleryImages()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($galleryImageIds === []) {
            // Gallery-sourced items attach media with NO role tag (real
            // data; the role-filtered relation only sees case-study
            // uploads). Fall back to any media attached to the item.
            $galleryImageIds = SiteMedia::query()
                ->where('site_id', $item->site_id)
                ->where('project_item_id', $item->id)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }

        return ['sections' => [
            [
                'type' => 'project_detail_hero',
                'title' => (string) $item->title,
                // Hero keeps the SHORT tile blurb; long generated copy lives
                // in the project_about body section.
                'intro' => (string) $item->description,
                'hero_image_id' => $galleryImageIds[0] ?? null,
            ],
            [
                'type' => 'project_about',
                'title' => (string) $item->title,
                'body' => (string) ($item->metadata['detail_long_description'] ?? ''),
                // Meta lives INSIDE the About column (saves a full band of
                // height). project_meta_band remains a supported family for
                // existing pages; no longer seeded.
                'project_type' => (string) $item->category,
                'location' => (string) ($item->metadata['detail_location'] ?? ''),
                // Second image so the split variant never mirrors the hero.
                'image_id' => $galleryImageIds[1] ?? ($galleryImageIds[0] ?? null),
            ],
            [
                'type' => 'project_photo_essay',
                'title' => 'Project gallery',
                'intro' => (string) ($item->metadata['detail_essay_intro'] ?? ''),
                'category' => (string) $item->category,
                // Hero already shows the first image — the essay gets the rest.
                'image_ids' => array_values(array_slice($galleryImageIds, 1)),
            ],
            [
                'type' => 'project_cta_row',
                'title' => 'Planning something similar?',
                'body' => 'Tell us what you have in mind and we’ll help shape the next steps.',
                'cta_label' => 'Start a conversation',
                'cta_url' => '#contact',
            ],
        ]];
    }

    private function findSite(string $token): ?Site
    {
        if (ctype_digit($token)) {
            return Site::query()->find((int) $token);
        }

        return Site::query()
            ->where(function ($query) use ($token): void {
                $query->where('custom_domain', $token)
                    ->orWhere('preview_domain', $token);
            })
            ->first();
    }
}
