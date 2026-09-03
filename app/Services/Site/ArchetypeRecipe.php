<?php

namespace App\Services\Site;

use App\Enums\Archetype;

/**
 * Lookup wrapper around config/site_archetypes.php. Centralises the
 * "archetype → home section recipe" decision so call sites don't have to
 * re-implement the fallback on every read.
 */
class ArchetypeRecipe
{
    /**
     * @return array{sections: array<int, string>, weights: array<string, array<string, mixed>>}
     */
    public function for(Archetype $archetype): array
    {
        $recipe = config("site_archetypes.{$archetype->value}");
        if (! is_array($recipe) || ! isset($recipe['sections'])) {
            $recipe = config('site_archetypes.local_service');
        }

        return [
            'sections' => array_values($recipe['sections'] ?? []),
            'weights' => is_array($recipe['weights'] ?? null) ? $recipe['weights'] : [],
        ];
    }
}
