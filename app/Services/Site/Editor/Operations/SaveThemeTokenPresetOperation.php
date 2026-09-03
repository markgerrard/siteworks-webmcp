<?php

namespace App\Services\Site\Editor\Operations;

use App\Models\Site\SiteDraft;
use App\Models\ThemeTokenPreset;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorState;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;
use Illuminate\Database\UniqueConstraintViolationException;

final class SaveThemeTokenPresetOperation extends BaseOperation
{
    public function __construct(
        private readonly EditorStateFactory $states,
    ) {}

    public function name(): string
    {
        return 'save_theme_token_preset';
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function wrapInAdminChange(): bool
    {
        return false;
    }

    /**
     * @return list<string>|null
     */
    public function allowedRoles(): ?array
    {
        return ['staff'];
    }

    public function address(): string
    {
        return 'site';
    }

    public function sideEffects(): string
    {
        return 'Snapshots the current site token_overrides as a named preset. Copy, do not link. Staff only; does not mutate the draft.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['name', 'composition_revision'],
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Unique name for the snapshot of the current site token_overrides.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Optional human description of the preset.',
                ],
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
        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        if ($name === '') {
            return OperationResult::fail('validation', 'name is required.', $state, [
                'fields' => ['name' => ['required']],
            ]);
        }

        $description = $input['description'] ?? null;
        if ($description !== null && ! is_string($description)) {
            return OperationResult::fail('validation', 'description must be a string.', $state, [
                'fields' => ['description' => ['string']],
            ]);
        }
        $description = is_string($description) && trim($description) !== '' ? trim($description) : null;

        $tokens = self::currentTokenOverrides($ctx->site->id);
        if ($tokens === []) {
            return OperationResult::fail('validation', 'Cannot save a theme token preset from an empty token_overrides map.', $state, [
                'fields' => ['tokens' => ['empty']],
            ]);
        }

        if (ThemeTokenPreset::query()->where('name', $name)->exists()) {
            return self::duplicateName($name, $state);
        }

        try {
            $preset = ThemeTokenPreset::query()->create([
                'name' => $name,
                'description' => $description,
                'tokens' => $tokens,
                'created_by_user_id' => $ctx->actor->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return self::duplicateName($name, $state);
        }

        return OperationResult::ok([
            'name' => $preset->name,
            'description' => $preset->description,
            'tokens' => $preset->tokens,
        ], $state);
    }

    /**
     * @return array<string, string>
     */
    private static function currentTokenOverrides(int $siteId): array
    {
        $draft = SiteDraft::where('site_id', $siteId)->first();
        $theme = is_array($draft?->composition['theme'] ?? null) ? $draft->composition['theme'] : [];
        $existing = is_array($theme['token_overrides'] ?? null) ? $theme['token_overrides'] : [];

        return array_filter(
            $existing,
            fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    private static function duplicateName(string $name, EditorState $state): OperationResult
    {
        return OperationResult::fail('validation', "A theme token preset named [{$name}] already exists.", $state, [
            'fields' => ['name' => ['taken']],
        ]);
    }
}
