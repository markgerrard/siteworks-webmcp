<?php

namespace App\Services\Site\Editor\Results;

use App\Enums\LogoConceptSource;
use App\Models\LogoConcept;

final class LogoConceptsResult
{
    /**
     * @return array{concept_ids: list<int>}
     */
    public static function build(int $siteId, ?int $idFloor = null): array
    {
        return [
            'concept_ids' => LogoConcept::query()
                ->where('site_id', $siteId)
                ->where('source', LogoConceptSource::Generated)
                ->when($idFloor !== null, fn ($q) => $q->where('id', '>', $idFloor)) // only THIS run's concepts
                ->latest('id')
                ->limit(8)
                ->pluck('id')
                ->all(),
        ];
    }
}
