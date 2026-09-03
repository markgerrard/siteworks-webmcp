<?php

namespace App\Enums;

/**
 * Where a hero / intro image came from.
 *
 * - AiGenerated: produced by the (now-removed) generation pipeline.
 *   Default for every row created by the orchestrator.
 * - UserUpload: an agent uploaded a file via the admin CP picker.
 *   Bypasses the AI pipeline; the bytes go straight to S3 and a
 *   HeroVersion row is created pointing at them. Surfaced in the picker
 *   alongside AI-generated versions with a "User" badge.
 * - FacebookImport: an agent promoted a mirrored Facebook photo into
 *   the picker. It follows the same inactive-version activation path
 *   as other variants, surfaced with an "FB" badge.
 *
 * Add new sources here when introducing a new origin path (e.g.
 * client_upload for direct end-customer uploads, scraped for hero
 * images sourced from the prospect site, etc.). Keep the underlying
 * column varchar to avoid PG enum migration cost.
 */
enum HeroVersionSource: string
{
    case AiGenerated = 'ai_generated';
    case UserUpload = 'user_upload';
    case FacebookImport = 'facebook_import';

    public function label(): string
    {
        return match ($this) {
            self::AiGenerated => 'AI',
            self::UserUpload => 'User',
            self::FacebookImport => 'FB',
        };
    }
}
