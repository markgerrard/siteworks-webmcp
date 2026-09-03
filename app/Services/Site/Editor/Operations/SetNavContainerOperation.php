<?php

namespace App\Services\Site\Editor\Operations;

use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\PublicPageCache;
use App\Support\ChromeKnobs;

final class SetNavContainerOperation extends BaseOperation
{
    public function __construct(
        private readonly EditorStateFactory $states,
        private readonly PublicPageCache $publicCache,
    ) {}

    public function name(): string
    {
        return 'set_nav_container';
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
        return 'Writes the live sites.nav_container_style and sites.nav_container_fill chrome knobs. Does not publish a draft.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['nav_container_style', 'nav_container_fill', 'composition_revision'],
            'properties' => [
                'nav_container_style' => [
                    'type' => 'string',
                    'enum' => ChromeKnobs::NAV_CONTAINER_STYLES,
                ],
                'nav_container_fill' => [
                    'type' => 'string',
                    'enum' => ChromeKnobs::NAV_CONTAINER_FILLS,
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
        $style = $input['nav_container_style'] ?? null;
        $fill = $input['nav_container_fill'] ?? null;

        if (! is_string($style) || ! in_array($style, ChromeKnobs::NAV_CONTAINER_STYLES, true)
            || ! is_string($fill) || ! in_array($fill, ChromeKnobs::NAV_CONTAINER_FILLS, true)) {
            return OperationResult::fail('validation', 'Nav container style or fill is invalid.', $state, [
                'fields' => [
                    'nav_container_style' => ['must be none, pill, plate, or band'],
                    'nav_container_fill' => ['must be surface, glass, brand, or pattern'],
                ],
            ]);
        }

        $storedStyle = $style;
        $storedFill = $fill;
        $previousStyle = $ctx->site->nav_container_style;
        $previousFill = $ctx->site->nav_container_fill;

        $ctx->site->update([
            'nav_container_style' => $storedStyle,
            'nav_container_fill' => $storedFill,
        ]);
        $this->publicCache->invalidate($ctx->site);

        $ctx->changes->record('site', 'sites.nav_container_style', $previousStyle, $storedStyle, 'update');
        $ctx->changes->record('site', 'sites.nav_container_fill', $previousFill, $storedFill, 'update');

        $fresh = $ctx->site->fresh();

        return OperationResult::ok([
            'nav_container_style' => ChromeKnobs::navContainerStyle($fresh),
            'nav_container_fill' => ChromeKnobs::navContainerFill($fresh),
        ], $state);
    }
}
