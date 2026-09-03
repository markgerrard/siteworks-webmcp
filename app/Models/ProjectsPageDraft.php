<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cached copy response for the projects-page generation pipeline.
 * See database/migrations/*_create_projects_page_drafts_table.php for
 * the rationale.
 */
class ProjectsPageDraft extends Model
{
    protected $fillable = [
        'site_id',
        'content_hash',
        'response',
    ];

    protected function casts(): array
    {
        return [
            'response' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Compute the content-fingerprint for a given input set. Stable as
     * long as the inputs are stable; any input change produces a new
     * fingerprint and forces a fresh copy generation call.
     *
     * @param  array<string, mixed>  $profile
     */
    public static function fingerprint(
        array $profile,
        string $businessName,
        string $businessType,
        string $location,
        ?string $country,
        bool $honestFraming,
    ): string {
        return sha1(json_encode([
            'profile' => $profile,
            'business_name' => $businessName,
            'business_type' => $businessType,
            'location' => $location,
            'country' => $country,
            'honest_framing' => $honestFraming,
        ]) ?: '');
    }
}
