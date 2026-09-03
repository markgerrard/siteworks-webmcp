<?php

namespace App\Services\Site;

use App\Exceptions\Site\SitePublishException;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decides — after a batch of pipeline mutations — whether it is safe to
 * auto-publish on the site's behalf.
 *
 * Safety rule: auto-publish iff the site's admin_revision is unchanged
 * between batch start and batch completion. Any admin-sourced mutation
 * during the batch window means the admin was editing; we do NOT
 * silently steamroll that work.
 *
 * Every decision is logged to the `logging.auth_channel` as a
 * structured `auto_publish_decision` event so "why didn't it publish?"
 * is answerable by a single log grep.
 *
 * Scope for v1: called only from the service-page generation batch
 * (page-manager's "Add service pages live" workflow). Not generalised.
 */
class AutoPublishCoordinator
{
    public function __construct(protected SitePublishService $publish) {}

    /**
     * Finalise decision after a service-page generation batch completes.
     *
     * @param  int  $siteId          The site whose draft the batch mutated.
     * @param  int  $preBatchRev     admin_revision value captured at dispatch.
     * @param  ?int $userId          Admin who triggered the batch (for audit).
     * @param  string $batchId       Laravel Bus batch id (for correlating logs).
     * @param  int  $pagesExpected   How many service-page jobs the batch carried.
     */
    public function finalizeAfterBatch(
        int $siteId,
        int $preBatchRev,
        ?int $userId,
        string $batchId,
        int $pagesExpected,
    ): void {
        $site = Site::find($siteId);
        if (! $site) {
            $this->audit(
                decision: false,
                reason: 'site_missing',
                siteId: $siteId,
                batchId: $batchId,
                preBatchRev: $preBatchRev,
                postBatchRev: null,
                newVersionId: null,
                pagesExpected: $pagesExpected,
                userId: $userId,
                error: null,
            );

            return;
        }

        // Wrap the admin_revision read + publish in a single DB transaction
        // with lockForPublish(). Taking site_drafts here and then letting
        // publishSite lock sites would invert the canonical order
        // (advisory → sites → site_drafts → generated_pages) into
        // site_drafts → sites — an ABBA against discardAllDrafts.
        try {
            $outcome = DB::transaction(function () use ($preBatchRev, $site, $userId, $pagesExpected) {
                $ctx = $this->publish->lockForPublish($site);
                $postBatchRev = (int) ($ctx->draft?->admin_revision ?? 0);

                if ($postBatchRev !== $preBatchRev) {
                    return [
                        'published' => false,
                        'reason' => 'admin_revision_changed',
                        'postBatchRev' => $postBatchRev,
                        'versionId' => null,
                        'error' => null,
                    ];
                }

                try {
                    $version = $this->publish->publishSite(
                        $ctx->site,
                        publishNote: "Auto-publish after bulk service-page generation ({$pagesExpected} pages)",
                        userId: $userId,
                        locks: $ctx,
                    );

                    return [
                        'published' => true,
                        'reason' => 'batch_complete_admin_revision_unchanged',
                        'postBatchRev' => $postBatchRev,
                        'versionId' => $version->id,
                        'error' => null,
                    ];
                } catch (SitePublishException $e) {
                    return [
                        'published' => false,
                        'reason' => 'publish_failed',
                        'postBatchRev' => $postBatchRev,
                        'versionId' => null,
                        'error' => $e->getMessage(),
                    ];
                }
            });

            $this->audit(
                decision: $outcome['published'],
                reason: $outcome['reason'],
                siteId: $siteId,
                batchId: $batchId,
                preBatchRev: $preBatchRev,
                postBatchRev: $outcome['postBatchRev'],
                newVersionId: $outcome['versionId'],
                pagesExpected: $pagesExpected,
                userId: $userId,
                error: $outcome['error'],
            );
        } catch (Throwable $e) {
            // Unexpected failure (DB error, deadlock, etc.). Audit so ops
            // can grep for it, then rethrow so the queue's retry/reporting
            // machinery can act. Narrow Throwable catches inside the
            // transaction handle the expected failure modes; this outer
            // catch exists solely for observability on the unexpected.
            $this->audit(
                decision: false,
                reason: 'unexpected_error',
                siteId: $siteId,
                batchId: $batchId,
                preBatchRev: $preBatchRev,
                postBatchRev: null,
                newVersionId: null,
                pagesExpected: $pagesExpected,
                userId: $userId,
                error: $e->getMessage(),
            );

            throw $e;
        }
    }

    /**
     * Called from Bus::batch->catch() when one or more jobs in the batch
     * failed (after retries). We don't auto-publish a half-complete batch.
     */
    public function logBatchFailure(int $siteId, string $batchId, string $error, ?int $userId): void
    {
        $this->audit(
            decision: false,
            reason: 'batch_failed',
            siteId: $siteId,
            batchId: $batchId,
            preBatchRev: null,
            postBatchRev: null,
            newVersionId: null,
            pagesExpected: 0,
            userId: $userId,
            error: $error,
        );
    }

    /**
     * Emit the structured auto_publish_decision event. Single place, every
     * outcome, so "grep auto_publish_decision" answers every variant.
     */
    protected function audit(
        bool $decision,
        string $reason,
        int $siteId,
        string $batchId,
        ?int $preBatchRev,
        ?int $postBatchRev,
        ?int $newVersionId,
        int $pagesExpected,
        ?int $userId,
        ?string $error,
    ): void {
        Log::channel(config('logging.auth_channel', 'stack'))->info('auto_publish_decision', [
            'event' => 'auto_publish_decision',
            'decision' => $decision,
            'reason' => $reason,
            'site_id' => $siteId,
            'batch_id' => $batchId,
            'pre_batch_revision' => $preBatchRev,
            'post_batch_revision' => $postBatchRev,
            'new_version_id' => $newVersionId,
            'pages_expected' => $pagesExpected,
            'triggered_by_user_id' => $userId,
            'error' => $error,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
