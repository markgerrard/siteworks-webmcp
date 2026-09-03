<?php

namespace App\Models;

use App\Enums\Archetype;
use App\Enums\LeadFormPolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BusinessProfile extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'site_id', 'profile_data', 'model_used',
        'layout_fingerprint', 'layout_fingerprint_updated_at',
        'has_visual_portfolio',
    ];

    protected function casts(): array
    {
        return [
            'profile_data' => 'array',
            'layout_fingerprint' => 'array',
            'layout_fingerprint_updated_at' => 'datetime',
            'has_visual_portfolio' => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Archetype from profile_data, normalised via Archetype::fromProfile so
     * unknown / missing values fall back to LocalService (Contract 3).
     */
    public function archetype(): Archetype
    {
        $value = $this->profile_data['archetype'] ?? null;

        return Archetype::fromProfile(is_string($value) ? $value : null);
    }

    /**
     * Resolve the lead_form_policy for this profile.
     *
     * Precedence:
     *   1. profile_data.lead_form_policy (new field) — use if present
     *   2. profile_data.home_lead_form_enabled (legacy boolean) — back-compat
     *   3. Archetype-based default (trade archetypes → home_services, others → home)
     */
    public function leadFormPolicy(): LeadFormPolicy
    {
        $data = $this->profile_data ?? [];

        // 1. New field wins.
        if (isset($data['lead_form_policy'])) {
            return LeadFormPolicy::tryFrom($data['lead_form_policy']) ?? LeadFormPolicy::Home;
        }

        // 2. Legacy boolean back-compat.
        if (array_key_exists('home_lead_form_enabled', $data)) {
            return $data['home_lead_form_enabled']
                ? LeadFormPolicy::Home
                : LeadFormPolicy::Off;
        }

        // 3. Archetype-based default.
        return LeadFormPolicy::defaultForArchetype($this->archetype());
    }

    /**
     * SHA-1 fingerprint of profile_data. Stable as long as the data is
     * stable; any change produces a different fingerprint, allowing
     * hero-generation jobs to skip unchanged profiles.
     */
    public function fingerprint(): string
    {
        return sha1(json_encode($this->profile_data ?? [], JSON_UNESCAPED_SLASHES));
    }
}
