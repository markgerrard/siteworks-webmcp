<?php

namespace App\Services\Site;

use App\Enums\LogoAssetVariant;
use App\Models\LogoConcept;
use App\Models\Site;
use Illuminate\Support\Facades\Storage;

final class LogoAssetCatalog
{
    public const TTL_MINUTES = 5;

    /**
     * @var array<string, string>
     */
    private const MIME_BY_EXTENSION = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
    ];

    public function active(Site $site): ?LogoConcept
    {
        $site->loadMissing('selectedLogoConcept');

        return $this->readable($site->selectedLogoConcept);
    }

    /**
     * Other downloadable variants that exist on disk, excluding the active
     * selected concept. Route key is a LogoAssetVariant value (never a path).
     *
     * @return list<array{variant: LogoAssetVariant, concept: LogoConcept, role: string}>
     */
    public function variants(Site $site): array
    {
        $active = $this->active($site);
        $emitted = $active !== null ? [$active->id] : [];
        $out = [];

        foreach (LogoAssetVariant::cases() as $variant) {
            if ($variant === LogoAssetVariant::Selected) {
                continue;
            }

            $concept = $this->resolve($site, $variant);
            if ($concept === null || in_array($concept->id, $emitted, true)) {
                continue;
            }

            $out[] = [
                'variant' => $variant,
                'concept' => $concept,
                'role' => $this->role($concept, $variant),
            ];
            $emitted[] = $concept->id;
        }

        return $out;
    }

    public function resolve(Site $site, LogoAssetVariant $variant): ?LogoConcept
    {
        $concepts = $this->concepts($site);

        return match ($variant) {
            LogoAssetVariant::Selected => $this->active($site),
            LogoAssetVariant::Overlay => $this->overlay($site, $concepts),
            LogoAssetVariant::Inverted => $this->firstMatching($concepts, function (LogoConcept $concept) use ($site): bool {
                return data_get($concept->metadata, 'variant') === 'inverted'
                    && $this->copyOfSelected($concept, $site, 'inverted_of');
            }),
            LogoAssetVariant::Transparent => $this->firstMatching($concepts, function (LogoConcept $concept) use ($site): bool {
                return data_get($concept->metadata, 'transparent') === true
                    && data_get($concept->metadata, 'variant') !== 'inverted'
                    && $this->copyOfSelected($concept, $site, 'source_concept_id');
            }),
            LogoAssetVariant::Wordmark => $this->firstMatching($concepts, fn (LogoConcept $c): bool => data_get($c->metadata, 'role') === 'wordmark'),
            LogoAssetVariant::Icon => $this->firstMatching($concepts, fn (LogoConcept $c): bool => data_get($c->metadata, 'role') === 'icon'),
            LogoAssetVariant::Light => $this->firstMatching($concepts, fn (LogoConcept $c): bool => data_get($c->metadata, 'role') === 'light')
                ?? $this->firstMatching($concepts, fn (LogoConcept $c): bool => data_get($c->metadata, 'reads_on_dark') === false && data_get($c->metadata, 'transparent') === true),
            LogoAssetVariant::Dark => $this->firstMatching($concepts, fn (LogoConcept $c): bool => data_get($c->metadata, 'role') === 'dark')
                ?? $this->firstMatching($concepts, fn (LogoConcept $c): bool => data_get($c->metadata, 'variant') === 'inverted'),
        };
    }

    public function bytes(LogoConcept $concept): ?string
    {
        if (! is_string($concept->path) || $concept->path === '') {
            return null;
        }

        try {
            $bytes = Storage::disk('s3')->get($concept->path);
        } catch (\Throwable) {
            return null;
        }

        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    public function mime(LogoConcept $concept): string
    {
        $extension = strtolower(pathinfo((string) $concept->path, PATHINFO_EXTENSION));

        return self::MIME_BY_EXTENSION[$extension] ?? 'application/octet-stream';
    }

    public function filename(LogoConcept $concept): string
    {
        $base = basename((string) $concept->path);

        return $base !== '' && $base !== '.' && $base !== '..' ? $base : 'logo';
    }

    /**
     * @return array{width: int|null, height: int|null}
     */
    public function dimensions(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);
        if (! is_array($info)) {
            return ['width' => null, 'height' => null];
        }

        $width = isset($info[0]) && is_int($info[0]) && $info[0] > 0 ? $info[0] : null;
        $height = isset($info[1]) && is_int($info[1]) && $info[1] > 0 ? $info[1] : null;

        return ['width' => $width, 'height' => $height];
    }

    /**
     * @return \Illuminate\Support\Collection<int, LogoConcept>
     */
    private function concepts(Site $site)
    {
        return $site->logoConcepts()->orderBy('id')->get();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, LogoConcept>  $concepts
     */
    private function overlay(Site $site, $concepts): ?LogoConcept
    {
        $id = $site->overlay_logo_concept_id;
        if ($id === null) {
            return null;
        }

        $concept = $concepts->firstWhere('id', $id);

        return $this->readable($concept);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, LogoConcept>  $concepts
     * @param  callable(LogoConcept): bool  $match
     */
    private function firstMatching($concepts, callable $match): ?LogoConcept
    {
        foreach ($concepts as $concept) {
            if ($match($concept) && $this->readable($concept) !== null) {
                return $concept;
            }
        }

        return null;
    }

    private function copyOfSelected(LogoConcept $concept, Site $site, string $metaKey): bool
    {
        $selectedId = $site->selectedLogoConcept?->id ?? $this->active($site)?->id;
        if ($selectedId === null) {
            return true;
        }

        $source = data_get($concept->metadata, $metaKey);

        return $source === null || (int) $source === (int) $selectedId;
    }

    private function readable(?LogoConcept $concept): ?LogoConcept
    {
        if (! $concept instanceof LogoConcept) {
            return null;
        }

        return $this->bytes($concept) === null ? null : $concept;
    }

    private function role(LogoConcept $concept, LogoAssetVariant $variant): string
    {
        $metaRole = data_get($concept->metadata, 'role');
        if (is_string($metaRole) && in_array($metaRole, ['wordmark', 'icon', 'light', 'dark', 'overlay'], true)) {
            return $metaRole;
        }

        return match ($variant) {
            LogoAssetVariant::Overlay => 'overlay',
            LogoAssetVariant::Inverted, LogoAssetVariant::Dark => 'dark',
            LogoAssetVariant::Transparent, LogoAssetVariant::Light => data_get($concept->metadata, 'reads_on_dark') === true ? 'dark' : 'light',
            LogoAssetVariant::Wordmark => 'wordmark',
            LogoAssetVariant::Icon => 'icon',
            LogoAssetVariant::Selected => data_get($concept->metadata, 'reads_on_dark') === true ? 'dark' : 'light',
        };
    }
}
