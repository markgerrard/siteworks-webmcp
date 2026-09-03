<?php

namespace App\Services\Media;

use App\Models\Site;
use App\Models\SiteMedia;
use App\Models\SiteMediaUsage;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class MediaAssignService
{
    public function assign(SiteMedia $media, Model $model, string $slot): SiteMediaUsage
    {
        $siteId = $model instanceof Site ? $model->id : ($model->getAttribute('site_id'));

        if ($siteId !== null && (int) $siteId !== (int) $media->site_id) {
            throw new InvalidArgumentException('Media does not belong to this site.');
        }

        return SiteMediaUsage::query()->updateOrCreate(
            [
                'usable_type' => $model->getMorphClass(),
                'usable_id' => $model->getKey(),
                'slot' => $slot,
            ],
            ['site_media_id' => $media->id],
        );
    }

    public function release(Model $model, string $slot): void
    {
        SiteMediaUsage::query()
            ->where('usable_type', $model->getMorphClass())
            ->where('usable_id', $model->getKey())
            ->where('slot', $slot)
            ->delete();
    }
}
