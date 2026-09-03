<?php

namespace App\Services\Site\Editor\Operations;

use App\Models\SiteMedia;
use App\Services\Media\MediaLibraryService;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;
use App\Support\Media\MediaKind;
use App\Support\Media\MediaOrigin;

final class ListMediaOperation extends BaseOperation
{
    public function __construct(
        private readonly MediaLibraryService $library,
        private readonly EditorStateFactory $states,
    ) {}

    public function name(): string
    {
        return 'list_media';
    }

    public function readOnly(): bool
    {
        return true;
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
        return 'Reads the site media library (non-provisional assets) with the same filters as the library grid.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => [MediaKind::Image->value]],
                'origin' => ['type' => 'string', 'enum' => array_column(MediaOrigin::cases(), 'value')],
                'tag' => ['type' => 'string'],
                'usage' => ['type' => 'string', 'enum' => ['used', 'unused']],
                'q' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(EditorContext $ctx, array $input): OperationResult
    {
        $state = $this->states->for($ctx->site, null);
        $filters = [];

        foreach (['kind', 'origin', 'tag', 'usage', 'q'] as $key) {
            if (! array_key_exists($key, $input) || $input[$key] === null || $input[$key] === '') {
                continue;
            }
            if (! is_string($input[$key])) {
                return OperationResult::fail('validation', "{$key} is invalid.", $state, [
                    'fields' => [$key => ['must be a string']],
                ]);
            }
            $filters[$key] = $input[$key];
        }

        if (isset($filters['kind']) && $filters['kind'] !== MediaKind::Image->value) {
            return OperationResult::fail('validation', 'kind is invalid.', $state, [
                'fields' => ['kind' => ['must be image']],
            ]);
        }

        if (isset($filters['origin']) && MediaOrigin::tryFrom($filters['origin']) === null) {
            return OperationResult::fail('validation', 'origin is invalid.', $state, [
                'fields' => ['origin' => ['must be generated, uploaded, or imported']],
            ]);
        }

        if (isset($filters['usage']) && ! in_array($filters['usage'], ['used', 'unused'], true)) {
            return OperationResult::fail('validation', 'usage is invalid.', $state, [
                'fields' => ['usage' => ['must be used or unused']],
            ]);
        }

        $items = $this->library->list($ctx->site, $filters)->map(function (SiteMedia $media): array {
            return [
                'id' => $media->id,
                'title' => $media->title,
                'kind' => $media->kind->value,
                'origin' => $media->origin->value,
                'width' => $media->width,
                'height' => $media->height,
                'url' => $media->url,
                'alt_text' => $media->alt_text,
                'tags' => $media->tags ?? [],
                'used' => $media->usages->isNotEmpty(),
                'usages' => $media->usages->map(fn ($usage): array => [
                    'usable_type' => $usage->usable_type,
                    'usable_id' => $usage->usable_id,
                    'slot' => $usage->slot,
                ])->values()->all(),
            ];
        })->values()->all();

        return OperationResult::ok(['media' => $items], $state);
    }
}
