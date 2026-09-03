<?php

namespace App\Services\Site\Editor\Operations;

use App\Exceptions\Site\StaleRevisionException;
use App\Models\GeneratedPage;
use App\Models\Site\PageRevision;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\PageService;

final class UpdatePageSettingsOperation extends BaseOperation
{
    /**
     * Rejected keys and the prerequisite each one names. Silently ignoring
     * any of these would let an agent believe it had changed a URL or live
     * column that this drafts-safe subset cannot write.
     *
     * @var array<string, string>
     */
    private const REJECTED = [
        'slug' => 'slug changes need a redirects table plus a drafted page_type',
        'page_type' => 'page_type changes need a redirects table plus a drafted page_type',
        'status' => 'status changes need a drafted status',
        'visibility' => 'visibility changes need a drafted status',
        'canonical_url' => 'canonical_url needs a dedicated column',
        'social_image' => 'social_image needs a dedicated column and renderer changes',
    ];

    public function __construct(
        private readonly PageService $pages,
        private readonly EditorStateFactory $states,
    ) {}

    public function name(): string
    {
        return 'update_page_settings';
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function wrapInAdminChange(): bool
    {
        return true;
    }

    public function address(): string
    {
        return 'page';
    }

    public function sideEffects(): string
    {
        return 'Writes draft page SEO meta_title and meta_description into the page revision. Rejects slug, page_type, status, visibility, canonical_url and social_image. Does not publish.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['page_id', 'revision_base'],
            'properties' => [
                'page_id' => ['type' => 'integer'],
                'meta_title' => ['type' => 'string'],
                'meta_description' => ['type' => 'string'],
                'revision_base' => ['type' => 'integer'],
                'slug' => [
                    'type' => 'string',
                    'description' => 'Rejected: slug changes need a redirects table plus a drafted page_type.',
                ],
                'page_type' => [
                    'type' => 'string',
                    'description' => 'Rejected: page_type changes need a redirects table plus a drafted page_type.',
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Rejected: status changes need a drafted status.',
                ],
                'visibility' => [
                    'type' => 'string',
                    'description' => 'Rejected: visibility changes need a drafted status.',
                ],
                'canonical_url' => [
                    'type' => 'string',
                    'description' => 'Rejected: canonical_url needs a dedicated column.',
                ],
                'social_image' => [
                    'type' => 'string',
                    'description' => 'Rejected: social_image needs a dedicated column and renderer changes.',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(EditorContext $ctx, array $input): OperationResult
    {
        $state = $this->states->for($ctx->site, null);

        $rejectedFields = [];
        foreach (self::REJECTED as $key => $prerequisite) {
            if (array_key_exists($key, $input)) {
                $rejectedFields[$key] = [$prerequisite];
            }
        }

        if ($rejectedFields !== []) {
            $first = array_key_first($rejectedFields);

            return OperationResult::fail(
                'validation',
                self::REJECTED[$first],
                $state,
                ['fields' => $rejectedFields],
            );
        }

        $pageId = self::intOrNull($input['page_id'] ?? null);
        $base = self::intOrNull($input['revision_base'] ?? null);

        if ($pageId === null || $base === null) {
            return OperationResult::fail('validation', 'page_id and revision_base are required.', $state, [
                'fields' => [
                    'page_id' => $pageId === null ? ['required integer'] : [],
                    'revision_base' => $base === null ? ['required integer'] : [],
                ],
            ]);
        }

        $hasTitle = array_key_exists('meta_title', $input);
        $hasDescription = array_key_exists('meta_description', $input);

        if (! $hasTitle && ! $hasDescription) {
            return OperationResult::fail('validation', 'meta_title or meta_description is required.', $state, [
                'fields' => [
                    'meta_title' => ['required unless meta_description is provided'],
                    'meta_description' => ['required unless meta_title is provided'],
                ],
            ]);
        }

        if ($hasTitle && ! is_string($input['meta_title'])) {
            return OperationResult::fail('validation', 'meta_title must be a string.', $state, [
                'fields' => ['meta_title' => ['string']],
            ]);
        }

        if ($hasDescription && ! is_string($input['meta_description'])) {
            return OperationResult::fail('validation', 'meta_description must be a string.', $state, [
                'fields' => ['meta_description' => ['string']],
            ]);
        }

        if ($hasDescription && mb_strlen($input['meta_description']) > 155) {
            return OperationResult::fail('validation', 'meta_description must be at most 155 characters.', $state, [
                'fields' => ['meta_description' => ['max 155 characters']],
            ]);
        }

        $page = GeneratedPage::query()
            ->where('site_id', $ctx->site->id)
            ->whereNull('archived_at')
            ->find($pageId);

        if (! $page) {
            return OperationResult::fail('not_found', 'Page not found.', $state);
        }

        $state = $this->states->for($ctx->site, $page);
        $seo = $this->currentSeo($page);

        if ($hasTitle) {
            $seo['meta_title'] = $input['meta_title'];
        }

        if ($hasDescription) {
            $seo['meta_description'] = $input['meta_description'];
        }

        try {
            $revision = $this->pages->editField(
                $page,
                'meta.seo',
                $seo,
                $ctx->actor->id,
                $base,
            );
        } catch (StaleRevisionException) {
            $fresh = $page->fresh();

            return OperationResult::fail('stale_revision', 'Page revision base is stale.', $state, [
                'current_revision_id' => $fresh->draft_revision_id ?? $fresh->published_revision_id,
            ]);
        }

        if ($hasTitle && mb_strlen($input['meta_title']) > 60) {
            $ctx->warnings->add(
                'meta_title_long',
                'meta_title is longer than 60 characters.',
                'meta.seo.meta_title',
            );
        }

        return OperationResult::ok([
            'draft_revision_id' => $revision->id,
        ], $this->states->for($ctx->site, $page->fresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function currentSeo(GeneratedPage $page): array
    {
        $content = [];
        $revisionId = $page->draft_revision_id ?? $page->published_revision_id;

        if ($revisionId) {
            $content = PageRevision::query()->find($revisionId)?->content_data ?? $page->content_data ?? [];
        } else {
            $content = $page->content_data ?? [];
        }

        return is_array($content['meta']['seo'] ?? null) ? $content['meta']['seo'] : [];
    }

    private static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^(0|-?[1-9][0-9]*)$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
