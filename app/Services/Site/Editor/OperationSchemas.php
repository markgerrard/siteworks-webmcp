<?php

namespace App\Services\Site\Editor;

use App\Mcp\Tools\Editor\JsonSchemaBridge;

final class OperationSchemas
{
    public const PREVIEW_GUIDANCE = 'Result `receipt.preview` of `unconfirmed` means the edit is saved but the preview may be stale — do not retry; `deferred` means the preview is on another page or being edited.';

    public const POSITIONAL_APPROVAL_GAP = 'Approval binding for positionally-addressed operations awaits stable section identifiers; this operation is not covered by the approval boundary.';

    public const ONE_USE_APPROVAL = 'This operation requires a one-use human approval.';

    /** Front 2 reads `positionalApprovalGap` from the export of this list — do not duplicate it there. */
    private const POSITIONAL_OPERATIONS = [
        'remove_section',
        'edit_field',
        'add_section',
        'move_section',
        'set_variant',
        'set_title_emphasis',
        'update_form',
        'restore_media_version',
    ];

    public function __construct(private readonly OperationRegistry $registry) {}

    /**
     * @return array<string, array{sideEffects: string, description: string, readOnly: bool, address: string, requiresApproval: bool, destructive: bool, positionalApprovalGap: bool, inputSchema: array<string, mixed>}>
     */
    public function all(): array
    {
        return collect($this->registry->all())
            ->mapWithKeys(function (Operation $operation): array {
                $inputSchema = ExpectedRevision::schema(
                    $operation,
                    JsonSchemaBridge::normalize($operation->inputSchema()),
                );

                if ((bool) config('editor.agent_approval.enabled')) {
                    $inputSchema['properties']['approval_request_id'] = [
                        'type' => 'string',
                        'format' => 'uuid',
                        'description' => 'One-use human approval request id returned by approval_required.',
                    ];
                }

                if (($inputSchema['properties'] ?? null) === []) {
                    $inputSchema['properties'] = (object) [];
                }

                return [$operation->name() => [
                    'sideEffects' => $operation->sideEffects(),
                    'description' => self::description($operation),
                    'readOnly' => $operation->readOnly(),
                    'address' => $operation->address(),
                    'requiresApproval' => $this->registry->effectiveRequiresApproval($operation->name()),
                    'destructive' => $this->registry->effectiveDestructive($operation->name()),
                    'positionalApprovalGap' => in_array($operation->name(), self::POSITIONAL_OPERATIONS, true),
                    'inputSchema' => $inputSchema,
                ]];
            })
            ->all();
    }

    public static function description(Operation $operation): string
    {
        $notes = [$operation->sideEffects()];

        if (! $operation->readOnly()) {
            $notes[] = self::PREVIEW_GUIDANCE;
        }

        $notes = match ($operation->name()) {
            'get_brand_context' => [...$notes, 'The `hero` value is the hero each page renders, including `__shared_service_hero`.'],
            'upload_image' => [...$notes, '`data_base64` must be un-wrapped strict base64 with no line breaks.'],
            'edit_field' => [...$notes, 'For `rich` fields, `value` is a TipTap document object.'],
            'restore_media_version' => [...$notes, 'This operation targets image fields only.'],
            default => $notes,
        };

        if ((bool) config('editor.agent_approval.enabled')) {
            if (in_array($operation->name(), self::POSITIONAL_OPERATIONS, true)) {
                $notes[] = self::POSITIONAL_APPROVAL_GAP;
            } elseif (app(OperationRegistry::class)->effectiveRequiresApproval($operation->name())) {
                $notes[] = self::ONE_USE_APPROVAL;
            }
        }

        $notes[] = '`error.job_ref === null` on `job_running` means there is nothing to poll.';
        $notes[] = '`quota_exceeded` means the site\'s monthly cap has been reached.';

        return implode(' ', $notes);
    }
}
