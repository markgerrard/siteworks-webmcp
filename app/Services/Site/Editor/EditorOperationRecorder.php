<?php

namespace App\Services\Site\Editor;

use App\Models\Site\EditorOperationLog;

/**
 * The single writer for `editor_operation_log`.
 *
 * Extracted from `EditorOperations::finish()` so that the legacy paths which deliberately do NOT route
 * through the operations layer can still record what they did. Those paths exist for good reasons — a
 * byte-compatible response body, or cache invalidation the layer is forbidden to perform — but their
 * absence from the log meant it could not be read as "everything that happened to this site":
 *
 *   1. human form saves        (FormUpdateController, ui path — keeps draftOnly:false for the live preview)
 *   2. multipart file uploads  (SiteMediaUploadController, the human file-picker branch)
 *   3. portrait uploads        (PortraitUploadController — never delegated)
 *   4. publish / discard-all   (SitePublishController — unreachable from any tool front by design)
 *
 * A fifth was needed for the log to be coherent rather than merely fuller: `edit_field`'s legacy branch,
 * taken when the human operations layer is switched off. Without it the log would have carried form saves
 * and uploads but not text edits, which is worse than either extreme.
 *
 * Recording here is deliberately NOT flag-gated. An audit trail that switches off with a feature flag is
 * not an audit trail.
 */
final class EditorOperationRecorder
{
    private static ?string $pendingSubjectType = null;

    private static ?string $pendingSubjectRef = null;

    public static function rememberProduct(string $slug): void
    {
        self::$pendingSubjectType = 'product';
        self::$pendingSubjectRef = $slug;
    }

    public function record(
        int $siteId,
        ?int $pageId,
        int $actorUserId,
        ActorChannel $channel,
        string $operation,
        string $resultCode = 'ok',
        int $durationMs = 0,
    ): void {
        EditorOperationLog::query()->create([
            'site_id' => $siteId,
            'page_id' => $pageId,
            'actor_user_id' => $actorUserId,
            'actor_channel' => $channel->value,
            'operation' => $operation,
            'result_code' => $resultCode,
            'duration_ms' => $durationMs,
            'subject_type' => self::$pendingSubjectType,
            'subject_ref' => self::$pendingSubjectRef,
            'created_at' => now(),
        ]);
        self::$pendingSubjectType = null;
        self::$pendingSubjectRef = null;
    }
}
