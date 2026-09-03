<?php

namespace App\Services\Site;

use App\Models\Site;
use App\Models\Site\SiteDraft;
use Illuminate\Support\Collection;

final readonly class PublishLockContext
{
    /**
     * @param  Collection<int, \App\Models\Site\SiteDraftAssetSelection>  $selections
     */
    public function __construct(
        public Site $site,
        public ?SiteDraft $draft,
        public Collection $selections,
    ) {}
}
