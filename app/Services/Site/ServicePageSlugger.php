<?php

namespace App\Services\Site;

use Illuminate\Support\Str;

/**
 * Canonical slug builder for service pages.
 *
 * The admin path (page-manager.blade.php) keeps a private copy for now to
 * stay within scope; the new pipeline job uses this shared helper.
 * When the admin path is next touched, drop its local copy and delegate
 * here too.
 */
class ServicePageSlugger
{
    /**
     * National businesses (or empty-location sites) don't need a location
     * suffix — otherwise the slug reads as "ai-preview-generator-united-kingdom"
     * which is both ugly and redundant.
     */
    public function makeSlug(string $serviceName, string $location, string $scope): string
    {
        if ($scope === 'national' || trim($location) === '') {
            return Str::slug($serviceName);
        }

        return Str::slug($serviceName.'-'.$location);
    }
}
