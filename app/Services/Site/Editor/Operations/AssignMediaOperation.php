<?php

namespace App\Services\Site\Editor\Operations;

use App\Models\Site;
use App\Models\SiteMedia;
use App\Services\Media\MediaAssignService;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\PublicPageCache;
use App\Support\Media\MediaKind;
use App\Support\MediaStorage;
use App\Support\Site\SitePublicObject;
use Illuminate\Support\Facades\Storage;

final class AssignMediaOperation extends BaseOperation
{
    public const TARGET_BRAND_ROW = 'brand_row';

    public function __construct(
        private readonly MediaAssignService $assign,
        private readonly EditorStateFactory $states,
        private readonly PublicPageCache $publicCache,
    ) {}

    public function name(): string
    {
        return 'assign_media';
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
        return 'Assigns a library asset to a live chrome slot (brand_row). Writes sites.brand_image_media_id and keeps brand_image_path in sync; does not publish a draft.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['target', 'media_id', 'composition_revision'],
            'properties' => [
                'target' => ['type' => 'string', 'enum' => [self::TARGET_BRAND_ROW]],
                'media_id' => ['type' => 'integer'],
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
        $target = $input['target'] ?? null;
        $mediaId = self::intOrNull($input['media_id'] ?? null);

        if ($target !== self::TARGET_BRAND_ROW) {
            return OperationResult::fail('validation', 'target is invalid.', $state, [
                'fields' => ['target' => ['must be brand_row']],
            ]);
        }

        if ($mediaId === null) {
            return OperationResult::fail('validation', 'media_id is required.', $state, [
                'fields' => ['media_id' => ['required integer']],
            ]);
        }

        $media = SiteMedia::query()
            ->library()
            ->where('site_id', $ctx->site->id)
            ->find($mediaId);

        if ($media === null) {
            return OperationResult::fail('not_found', 'Media not found.', $state);
        }

        if ($media->kind !== MediaKind::Image) {
            return OperationResult::fail('validation', 'brand_row requires an image.', $state, [
                'fields' => ['media_id' => ['must be an image']],
            ]);
        }

        $previousMediaId = $ctx->site->brand_image_media_id;
        $path = $this->brandPathFor($ctx->site, $media);
        $ctx->site->update([
            'brand_image_media_id' => $media->id,
            'brand_image_path' => $path,
        ]);
        $this->assign->assign($media, $ctx->site, self::TARGET_BRAND_ROW);
        $this->publicCache->invalidate($ctx->site);

        $data = [
            'media_id' => $media->id,
            'target' => self::TARGET_BRAND_ROW,
            'brand_image_path' => $path,
        ];
        $ctx->changes->record(
            'site',
            'sites.brand_image_media_id',
            $previousMediaId,
            $media->id,
            'update',
        );

        return OperationResult::ok($data, $state);
    }

    private function brandPathFor(Site $site, SiteMedia $media): string
    {
        $key = (string) $media->s3_key;
        $prefix = 'sites/'.$site->id.'/brand/';
        if ($key !== '' && str_starts_with($key, $prefix)) {
            return $key;
        }

        $bytes = '';
        foreach (array_unique(['s3', MediaStorage::diskName()]) as $diskName) {
            $disk = Storage::disk($diskName);
            if ($key !== '' && $disk->exists($key)) {
                $bytes = (string) $disk->get($key);
                break;
            }
        }

        $filename = basename($key);
        if ($filename === '' || $filename === '.' || $filename === '/') {
            $filename = 'library-'.$media->id.'.webp';
        }

        if ($bytes === '') {
            throw new \RuntimeException('Media object is missing from storage; cannot publish an empty brand image.');
        }

        return SitePublicObject::put($site->id, 'brand', $filename, $bytes);
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
