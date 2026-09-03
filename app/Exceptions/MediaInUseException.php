<?php

namespace App\Exceptions;

use App\Models\SiteMedia;
use App\Models\SiteMediaUsage;
use Illuminate\Support\Collection;
use RuntimeException;

final class MediaInUseException extends RuntimeException
{
    /**
     * @param  Collection<int, SiteMediaUsage>  $usages
     */
    public function __construct(
        public readonly SiteMedia $media,
        public readonly Collection $usages,
    ) {
        $listing = $usages
            ->map(fn (SiteMediaUsage $usage): string => "{$usage->usable_type}#{$usage->usable_id}:{$usage->slot}")
            ->implode(', ');

        parent::__construct("Media is in use and cannot be deleted ({$listing}).");
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'media_id' => $this->media->id,
            'usages' => $this->usages->map(fn (SiteMediaUsage $usage): array => [
                'usable_type' => $usage->usable_type,
                'usable_id' => $usage->usable_id,
                'slot' => $usage->slot,
            ])->all(),
        ];
    }
}
