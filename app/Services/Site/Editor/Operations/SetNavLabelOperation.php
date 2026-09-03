<?php

namespace App\Services\Site\Editor\Operations;

use App\Enums\MutationSource;
use App\Models\GeneratedPage;
use App\Models\Site\SiteDraft;
use App\Services\Site\CompositionService;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;

final class SetNavLabelOperation extends BaseOperation
{
    public function __construct(
        private readonly CompositionService $composition,
        private readonly EditorStateFactory $states,
    ) {}

    public function name(): string
    {
        return 'set_nav_label';
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
        return 'site';
    }

    public function sideEffects(): string
    {
        return 'Writes the drafted composition nav item label for a page. Never writes generated_pages.nav_label.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['page_id', 'label', 'composition_revision'],
            'properties' => [
                'page_id' => ['type' => 'integer'],
                'label' => ['type' => 'string', 'maxLength' => 30],
                'composition_revision' => ['type' => 'integer'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(EditorContext $ctx, array $input): OperationResult
    {
        $state = $this->states->for($ctx->site, null);

        $pageId = self::intOrNull($input['page_id'] ?? null);
        if ($pageId === null) {
            return OperationResult::fail('validation', 'page_id is required.', $state, [
                'fields' => ['page_id' => ['required integer']],
            ]);
        }

        if (! is_string($input['label'] ?? null)) {
            return OperationResult::fail('validation', 'label is required.', $state, [
                'fields' => ['label' => ['required string']],
            ]);
        }

        $label = $input['label'];
        if (mb_strlen($label) > 30) {
            return OperationResult::fail('validation', 'label must be at most 30 characters.', $state, [
                'fields' => ['label' => ['max 30 characters']],
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

        // Reload the locked row inside applyAdminChange. Reusing a pre-lock
        // instance makes composition_revision move by +1 or +2 depending on
        // which SiteDraft is written through.
        $draft = SiteDraft::query()
            ->where('site_id', $ctx->site->id)
            ->lockForUpdate()
            ->firstOrFail();

        $items = is_array($draft->composition['nav']['items'] ?? null)
            ? $draft->composition['nav']['items']
            : [];
        $relabelled = self::relabel($items, $pageId, $label);

        if ($relabelled === null) {
            return OperationResult::fail('not_found', 'Nav item not found for this page.', $state);
        }

        $this->composition->updateNav($draft, $relabelled, MutationSource::System, $ctx->actor->id);

        return OperationResult::ok([
            'page_id' => $pageId,
            'label' => $label,
        ], $this->states->for($ctx->site, $page->fresh()));
    }

    /**
     * @param  list<mixed>  $items
     * @return list<array<string, mixed>>|null
     */
    private static function relabel(array $items, int $pageId, string $label): ?array
    {
        $found = false;
        $rewritten = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                $rewritten[] = $item;

                continue;
            }

            if (($item['type'] ?? null) === 'page' && (int) ($item['page_id'] ?? 0) === $pageId) {
                $item['label'] = $label;
                $found = true;
            }

            if (($item['type'] ?? null) === 'group' && is_array($item['children'] ?? null)) {
                $children = self::relabel($item['children'], $pageId, $label);
                if ($children !== null) {
                    $item['children'] = $children;
                    $found = true;
                }
            }

            $rewritten[] = $item;
        }

        return $found ? array_values($rewritten) : null;
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
