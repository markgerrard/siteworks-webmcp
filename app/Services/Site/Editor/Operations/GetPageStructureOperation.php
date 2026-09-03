<?php

namespace App\Services\Site\Editor\Operations;

use App\Models\GeneratedPage;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\Editor\SectionCatalog;
use App\Services\Site\Editor\SectionDescriber;
use App\Services\Site\PageLayoutRegistry;
use App\Services\Site\PageRenderer;

final class GetPageStructureOperation extends BaseOperation
{
    public function __construct(
        private readonly EditorStateFactory $states,
        private readonly PageRenderer $renderer,
        private readonly PageLayoutRegistry $layouts,
        private readonly SectionCatalog $catalog,
        private readonly SectionDescriber $describer,
    ) {}

    public function name(): string
    {
        return 'get_page_structure';
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function sideEffects(): string
    {
        return 'Reads the draft page section structure without changing it.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['page_id'],
            'properties' => [
                'page_id' => ['type' => 'integer'],
            ],
        ];
    }

    public function wrapInAdminChange(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(EditorContext $ctx, array $input): OperationResult
    {
        $state = $this->states->for($ctx->site, null);
        $pageId = $input['page_id'] ?? null;

        if (! is_int($pageId) && ! (is_string($pageId) && preg_match('/^[1-9][0-9]*$/', $pageId) === 1)) {
            return OperationResult::fail('not_found', 'Page not found.', $state);
        }

        $page = GeneratedPage::query()
            ->where('site_id', $ctx->site->id)
            ->whereNull('archived_at') // admin-edit resolution never serves archived pages either
            ->find((int) $pageId);

        if ($page === null) {
            return OperationResult::fail('not_found', 'Page not found.', $state);
        }

        $state = $this->states->for($ctx->site, $page);
        $page->loadMissing(['draftRevision', 'publishedRevision']);
        $content = $page->draftRevision?->content_data
            ?? $page->publishedRevision?->content_data
            ?? $page->content_data
            ?? [];
        $stored = is_array($content['sections'] ?? null) ? $content['sections'] : [];
        $pageKind = $this->layouts->layoutKindForPage($page) ?? '';
        $catalog = config('section_catalog', []);
        $sections = [];

        foreach (array_values($stored) as $index => $section) {
            if (! is_array($section) || ! is_string($section['type'] ?? null) || $section['type'] === '') {
                continue;
            }

            $sections[] = $this->describer->describe(
                $section,
                $pageKind,
                $index,
                array_key_exists($section['type'], $catalog) && ! $this->catalog->isInjectedOnly($section['type']),
            );
        }

        $ctx->site->loadMissing('businessProfile');

        // Exactly what the renderer injects (guards, phone_cta_strip + lead_form, splice position) — one source of truth.
        $injected = $this->renderer->injectedServiceBlock($ctx->site, $page, array_values($stored), useCache: false);
        if ($injected !== null) {
            $described = array_map(
                fn (array $section) => $this->describer->describe($section, $pageKind, null, false),
                $injected['block'],
            );
            if ($injected['index'] === null) {
                $sections = array_merge($sections, $described);
            } else {
                array_splice($sections, $injected['index'], 0, $described);
            }
        }

        return OperationResult::ok([
            'page_id' => $page->id,
            'page_type' => (string) $page->page_type,
            'draft_revision_id' => $page->draft_revision_id ?? $page->published_revision_id,
            'structure_epoch' => (int) ($page->structure_epoch ?? 0),
            'sections' => $sections,
        ], $state);
    }
}
