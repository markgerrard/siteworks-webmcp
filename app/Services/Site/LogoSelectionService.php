<?php

namespace App\Services\Site;

use App\Models\LogoConcept;
use App\Models\Site;
use App\Services\Site\Editor\DraftAssetSelections;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class LogoSelectionService
{
    public function select(
        Site $site,
        LogoConcept $concept,
        ?int $userId,
        bool $bumpAdmin,
    ): void {
        if ((int) $concept->site_id !== (int) $site->id) {
            throw new InvalidArgumentException('The logo concept does not belong to this site.');
        }

        if (! $bumpAdmin) {
            $this->applySelection($site, $concept);

            return;
        }

        DB::transaction(function () use ($site, $concept, $userId): void {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('SELECT pg_advisory_xact_lock(?)', [$site->id]);
            }
            $this->applySelection($site, $concept);
            app(CompositionService::class)->bumpAdminRevision($site, $userId);
            app(DraftAssetSelections::class)->clearMatching($site, 'logo', '', ''); // after site_drafts (canonical order)
        });
    }

    private function applySelection(Site $site, LogoConcept $concept): void
    {
        $site->logoConcepts()->update(['is_selected' => false]);
        $site->logoConcepts()->whereKey($concept->id)->update(['is_selected' => true]);
        $concept->refresh();
    }
}
