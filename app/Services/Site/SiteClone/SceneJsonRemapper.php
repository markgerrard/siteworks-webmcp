<?php

namespace App\Services\Site\SiteClone;

class SceneJsonRemapper
{
    /**
     * @param  array<string, mixed>|null  $scene
     * @param  array<string, array<int|string, int>>  $idMaps
     * @return array<string, mixed>|null
     */
    public static function remap(?array $scene, array $idMaps): ?array
    {
        if ($scene === null) {
            return null;
        }

        if (array_key_exists('composite_video_id', $scene) && $scene['composite_video_id'] !== null) {
            $old = (int) $scene['composite_video_id'];
            $scene['composite_video_id'] = $idMaps['hero_video_versions'][$old] ?? $scene['composite_video_id'];
        }

        foreach ($scene['slides'] ?? [] as $index => $slide) {
            if (! is_array($slide) || ! array_key_exists('asset_id', $slide) || $slide['asset_id'] === null) {
                continue;
            }

            $table = ($slide['asset_type'] ?? '') === 'hero_video_version'
                ? 'hero_video_versions'
                : 'hero_versions';
            $old = (int) $slide['asset_id'];
            $scene['slides'][$index]['asset_id'] = $idMaps[$table][$old] ?? $slide['asset_id'];
        }

        return $scene;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @param  array<string, array<int|string, int>>  $idMaps
     * @return array<string, mixed>|null
     */
    public static function remapComponentIds(?array $metadata, array $idMaps): ?array
    {
        if ($metadata === null) {
            return null;
        }

        if (! isset($metadata['component_ids']) || ! is_array($metadata['component_ids'])) {
            return $metadata;
        }

        $metadata['component_ids'] = array_map(
            fn (mixed $id): mixed => $idMaps['hero_video_versions'][(int) $id] ?? $id,
            $metadata['component_ids'],
        );

        return $metadata;
    }
}
