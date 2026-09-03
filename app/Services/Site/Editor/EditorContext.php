<?php

namespace App\Services\Site\Editor;

use App\Models\Site;
use App\Models\User;

final class EditorContext
{
    public function __construct(
        public readonly User $actor,
        public readonly Site $site,
        public readonly ActorChannel $channel,
        public readonly ?string $grantPrincipal = null,
        public readonly ?string $continuationOfApprovalId = null,
        public readonly bool $includeChanges = false,
        public readonly WarningBag $warnings = new WarningBag,
        public readonly ChangeBag $changes = new ChangeBag,
    ) {}
}
