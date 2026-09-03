<?php

namespace App\Services\Site;

use Illuminate\Support\Collection;

/**
 * Splits a profiler-emitted service list into "build now" and "defer to
 * admin_suggestions" buckets per the cap rule:
 *
 *   n ≤ 8    → build all
 *   9–14     → build top 8 by confidence, defer rest
 *   ≥ 15     → build top 10 by confidence, defer rest
 *
 * Accepts both legacy string-list shape (profiler currently emits
 * `products: ["Foo", "Bar"]`) and the future array-shape
 * `[{"name": "Foo", "confidence": 0.7}, ...]`. When confidence is absent
 * every service defaults to 0.5, and PHP's stable sort preserves list
 * position — so string lists fall through as insertion-ordered.
 */
class ServicePageCapCalculator
{
    /**
     * @param  array<int, string|array<string, mixed>>  $services
     * @return array{to_build: array<int, array{name: string, confidence: float}>, deferred: array<int, array{name: string, confidence: float}>}
     */
    public function split(array $services): array
    {
        /** @var Collection<int, array{name: string, confidence: float}> $normalised */
        $normalised = collect($services)
            ->map(fn (mixed $s) => $this->normalise($s))
            ->filter(fn (?array $s) => $s !== null && $s['name'] !== '')
            ->values();

        $count = $normalised->count();
        $cap = match (true) {
            $count <= 8 => $count,
            $count <= 14 => 8,
            default => 10,
        };

        $sorted = $normalised->sortByDesc('confidence')->values();

        return [
            'to_build' => $sorted->take($cap)->values()->all(),
            'deferred' => $sorted->slice($cap)->values()->all(),
        ];
    }

    /**
     * @return array{name: string, confidence: float}|null
     */
    private function normalise(mixed $service): ?array
    {
        if (is_string($service)) {
            $name = trim($service);

            return $name === '' ? null : ['name' => $name, 'confidence' => 0.5];
        }

        if (! is_array($service)) {
            return null;
        }

        $name = is_string($service['name'] ?? null) ? trim($service['name']) : '';
        if ($name === '') {
            return null;
        }

        $confidence = $service['confidence'] ?? 0.5;
        $confidence = is_numeric($confidence) ? (float) $confidence : 0.5;
        $confidence = max(0.0, min(1.0, $confidence));

        return ['name' => $name, 'confidence' => $confidence];
    }
}
