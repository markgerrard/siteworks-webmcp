<?php

namespace App\Services\Site\Editor;

use RuntimeException;

/**
 * A queued job refused at run time because the human it was authorised for no longer qualifies.
 *
 * Typed so it can be told apart from a breakage: EditorJobStatus::failureCode() maps it to `forbidden`,
 * which is what an agent polling get_job_status needs in order to distinguish "your access was revoked
 * while this sat on the queue" from "something broke". A plain RuntimeException surfaced as `internal`.
 */
class QueuedJobDenied extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('queued job denied at run time: '.$reason);
    }
}
