<?php

namespace App\Services\Site;

final readonly class HeroState
{
    /**
     * @param  array<string, mixed>  $placement
     * @param  array<string, mixed>|null  $scene
     */
    public function __construct(
        public string $mode,
        public ?int $image_version_id,
        public ?string $image_url,
        public ?int $video_version_id,
        public ?string $video_url,
        public array $placement,
        public ?array $scene,
        public string $reason,
    ) {}
}
