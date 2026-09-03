<?php

namespace App\Services\Site\Editor\Operations;

use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\PublicPageCache;
use App\Support\ChromeKnobs;

final class SetHeroCopyStyleOperation extends BaseOperation
{
    /** @var list<string> */
    public const VALUES = ChromeKnobs::HERO_COPY_STYLES;

    public function __construct(
        private readonly EditorStateFactory $states,
        private readonly PublicPageCache $publicCache,
    ) {}

    public function name(): string
    {
        return 'set_hero_copy_style';
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function wrapInAdminChange(): bool
    {
        return false;
    }

    public function address(): string
    {
        return 'site';
    }

    public function sideEffects(): string
    {
        return 'Writes the live sites.hero_copy_style chrome knob (preset|plain|panel|boxed). Does not publish a draft.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['hero_copy_style', 'composition_revision'],
            'properties' => [
                'hero_copy_style' => [
                    'type' => 'string',
                    'enum' => self::VALUES,
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
        $value = $input['hero_copy_style'] ?? null;

        if (! is_string($value) || ! in_array($value, self::VALUES, true)) {
            return OperationResult::fail('validation', 'hero_copy_style is invalid.', $state, [
                'fields' => ['hero_copy_style' => ['must be preset, plain, panel, or boxed']],
            ]);
        }

        $stored = $value === 'preset' ? null : $value;
        $previous = $ctx->site->hero_copy_style;
        $ctx->site->update(['hero_copy_style' => $stored]);
        $this->publicCache->invalidate($ctx->site);

        $ctx->changes->record(
            'site',
            'sites.hero_copy_style',
            $previous,
            $stored,
            'update',
        );

        return OperationResult::ok([
            'hero_copy_style' => ChromeKnobs::heroCopyStyle($ctx->site->fresh()),
        ], $state);
    }
}
