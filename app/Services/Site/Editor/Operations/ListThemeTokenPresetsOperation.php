<?php

namespace App\Services\Site\Editor\Operations;

use App\Models\ThemeTokenPreset;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;

final class ListThemeTokenPresetsOperation extends BaseOperation
{
    public function __construct(
        private readonly EditorStateFactory $states,
    ) {}

    public function name(): string
    {
        return 'list_theme_token_presets';
    }

    public function readOnly(): bool
    {
        return true;
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
        return 'Lists named theme token presets (name, description, token count). Staff only.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(EditorContext $ctx, array $input): OperationResult
    {
        $presets = ThemeTokenPreset::query()
            ->orderBy('name')
            ->get()
            ->map(fn (ThemeTokenPreset $preset): array => [
                'name' => $preset->name,
                'description' => $preset->description,
                'token_count' => is_array($preset->tokens) ? count($preset->tokens) : 0,
            ])
            ->values()
            ->all();

        return OperationResult::ok([
            'presets' => $presets,
        ], $this->states->for($ctx->site, null));
    }
}
