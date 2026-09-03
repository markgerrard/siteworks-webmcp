<?php

namespace App\Services\Site\Editor;

use App\Enums\HeroVersionSource;
use App\Models\HeroVersion;
use App\Models\Site;
use App\Models\SiteMedia;
use InvalidArgumentException;

final class HeroVersionRegistrar
{
    public function registerFromMedia(
        Site $site,
        SiteMedia $media,
        string $pageType,
        string $slot,
        ?int $userId,
    ): HeroVersion {
        if ($media->site_id !== $site->id) {
            throw new InvalidArgumentException('Media does not belong to this site.');
        }

        return HeroVersion::create([
            'site_id' => $site->id,
            'page_type' => $pageType,
            'slot' => $slot,
            'url' => $media->url,
            'watermark_url' => null,
            'prompt' => null,
            'model' => null,
            'placement' => null,
            'is_active' => false,
            'source' => HeroVersionSource::UserUpload,
        ]);
    }
}
