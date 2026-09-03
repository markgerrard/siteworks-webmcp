<?php

namespace App\Services\Site\Editor\Operations;

use App\Models\HeroVersion;
use App\Models\Site;
use App\Models\SiteMedia;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;

final class UpdateAssetMetadataOperation extends BaseOperation
{
    /**
     * Framework-reserved keys that ride along in operation input but are NOT
     * declared by this operation's own schema. The Front 1/2 controller injects
     * `parent_origin` on every call; `ExpectedRevision` keeps the
     * `expected_revision` alias alive after resolving it (and the published
     * schema advertises it); `OperationSchemas` injects `approval_request_id`
     * when agent approval is on. `parent_origin` and `approval_request_id` also
     * appear in `ApprovalStore::HASH_EXCLUDED_KEYS` precisely because they ride
     * along with the operation input and are not schema keys. Naming them here
     * (rather than hand-copying the whole allowlist, which is what INPUT_KEYS
     * was) keeps the accepted set in lock-step with the schema: a newly declared
     * property is accepted automatically, and a new framework key added here is
     * accepted everywhere at once.
     *
     * I did not reuse `ApprovalStore::HASH_EXCLUDED_KEYS` directly: it also
     * carries `composition_revision`/`revision_base`/`structure_epoch` (genuine
     * operation keys excluded from the approval hash for a different reason) and
     * omits `expected_revision` (which the transport keeps alive after alias
     * resolution), so it neither exactly bounds the transport set nor covers it.
     *
     * @var list<string>
     */
    private const FRAMEWORK_RESERVED_KEYS = [
        'parent_origin',
        'expected_revision',
        'approval_request_id',
    ];

    /**
     * Metadata keys written into site_media.metadata.
     *
     * @var list<string>
     */
    private const METADATA_KEYS = [
        'caption',
        'attribution',
        'role',
        'focal_point',
    ];

    public function __construct(private readonly EditorStateFactory $states) {}

    public function name(): string
    {
        return 'update_asset_metadata';
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function address(): string
    {
        return 'site';
    }

    public function sideEffects(): string
    {
        return 'Updates the live site_media row (alt text, caption, attribution, role, focal point). This change is not drafted: editor metadata writes do not invalidate the public cache, so it lands on the next uncached public render rather than instantly repainting cached HTML.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['media_id', 'composition_revision'],
            'properties' => [
                'media_id' => ['type' => 'integer'],
                'alt_text' => ['type' => 'string', 'maxLength' => 250],
                'caption' => ['type' => 'string', 'maxLength' => 2000],
                'attribution' => ['type' => 'string', 'maxLength' => 2000],
                'role' => ['type' => 'string', 'maxLength' => 100],
                'focal_point' => ['type' => 'object', 'additionalProperties' => true],
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

        $unknownKeys = array_diff(array_keys($input), $this->acceptedInputKeys());
        if ($unknownKeys !== []) {
            return OperationResult::fail('validation', 'Unknown keys are not accepted.', $state, [
                'fields' => collect($unknownKeys)->mapWithKeys(
                    fn (string $key): array => [$key => ['unknown key; this operation accepts only '.implode(', ', $this->declaredKeys())]],
                )->all(),
            ]);
        }

        $mediaId = self::intOrNull($input['media_id'] ?? null);
        if ($mediaId === null) {
            return OperationResult::fail('validation', 'media_id is required.', $state, [
                'fields' => ['media_id' => ['required integer']],
            ]);
        }

        $media = SiteMedia::query()
            ->where('site_id', $ctx->site->id)
            ->find($mediaId);

        if ($media === null) {
            return OperationResult::fail('not_found', 'Media not found.', $state);
        }

        if (array_key_exists('alt_text', $input) && mb_strlen((string) $input['alt_text']) > 250) {
            return OperationResult::fail('validation', 'alt_text must be 250 characters or fewer.', $state, [
                'fields' => ['alt_text' => ['must be 250 characters or fewer']],
            ]);
        }

        if (array_key_exists('focal_point', $input) && $this->isHeroAsset($ctx->site, $media)) {
            return OperationResult::fail('validation', 'This is a hero asset; writing its focal point is not yet supported.', $state, [
                'reason' => 'hero_asset_focal_point',
                'fields' => ['focal_point' => ['hero focal point is not yet supported']],
            ]);
        }

        $metadata = is_array($media->metadata) ? $media->metadata : [];
        $altTextBefore = $media->alt_text;

        foreach (self::METADATA_KEYS as $key) {
            if (array_key_exists($key, $input)) {
                $ctx->changes->record(
                    'site',
                    "site_media.{$media->id}.metadata.{$key}",
                    $metadata[$key] ?? null,
                    $input[$key],
                    'set',
                );
            }
        }

        if (array_key_exists('alt_text', $input)) {
            $ctx->changes->record(
                'site',
                "site_media.{$media->id}.alt_text",
                $altTextBefore,
                $input['alt_text'],
                'set',
            );
        }

        foreach (self::METADATA_KEYS as $key) {
            if (array_key_exists($key, $input)) {
                $metadata[$key] = $input[$key];
            }
        }

        if (array_key_exists('alt_text', $input)) {
            $media->alt_text = $input['alt_text'];
        }
        $media->metadata = $metadata;
        $media->save();

        return OperationResult::ok($this->publicFields($media), $state);
    }

    /**
     * The property keys this operation itself declares and publishes.
     *
     * @return list<string>
     */
    private function declaredKeys(): array
    {
        return array_keys($this->inputSchema()['properties'] ?? []);
    }

    /**
     * The keys an agent may present without being refused: the declared schema
     * properties plus the framework-reserved transport keys. Derived, never
     * hand-copied, so the allowlist cannot drift from the published schema.
     *
     * @return list<string>
     */
    private function acceptedInputKeys(): array
    {
        return array_values(array_unique([
            ...$this->declaredKeys(),
            ...self::FRAMEWORK_RESERVED_KEYS,
        ]));
    }

    /**
     * A media is a hero asset when a hero version for this site points at it.
     * Hero versions are registered from a media row by URL (HeroVersionRegistrar),
     * so a URL match is the established relationship — not a coincidence of ids.
     */
    private function isHeroAsset(Site $site, SiteMedia $media): bool
    {
        if (! is_string($media->url) || $media->url === '') {
            return false;
        }

        return HeroVersion::query()
            ->where('site_id', $site->id)
            ->where('url', $media->url)
            ->exists();
    }

    /**
     * @return array{media_id: int, alt_text: string|null, caption: mixed, attribution: mixed, role: mixed, focal_point: mixed}
     */
    private function publicFields(SiteMedia $media): array
    {
        $metadata = is_array($media->metadata) ? $media->metadata : [];

        return [
            'media_id' => $media->id,
            'alt_text' => $media->alt_text,
            'caption' => $metadata['caption'] ?? null,
            'attribution' => $metadata['attribution'] ?? null,
            'role' => $metadata['role'] ?? null,
            'focal_point' => $metadata['focal_point'] ?? null,
        ];
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
