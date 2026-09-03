<?php

namespace App\Services\Site\Editor;

use Illuminate\Support\Str;

final class SectionIdentifiers
{
    /**
     * Add an `id` to every section that lacks one.
     *
     * Existing ids are left untouched. Legacy-shape rows (no 'sections'
     * key, or 'sections' is not a sequential list) are returned unchanged.
     *
     * Idempotent: ensure(ensure($x)) === ensure($x).
     */
    public function ensure(array $contentData): array
    {
        if (! isset($contentData['sections']) || ! array_is_list($contentData['sections'])) {
            return $contentData;
        }

        $sections = $contentData['sections'];
        $changed = false;

        foreach ($sections as $i => $section) {
            if (! is_array($section)) {
                continue;
            }

            if (! (is_string($section['id'] ?? null) && $section['id'] !== '')) {
                $sections[$i]['id'] = (string) Str::ulid();
                $changed = true;
            }
        }

        if (! $changed) {
            return $contentData;
        }

        return array_merge($contentData, ['sections' => $sections]);
    }

    /**
     * Force a fresh `id` on every section.
     *
     * Clone only — never on an ordinary write. Ignores any existing id.
     */
    public function remint(array $contentData): array
    {
        if (! isset($contentData['sections']) || ! array_is_list($contentData['sections'])) {
            return $contentData;
        }

        $sections = $contentData['sections'];

        foreach ($sections as $i => $section) {
            if (! is_array($section)) {
                continue;
            }

            $sections[$i]['id'] = (string) Str::ulid();
        }

        return array_merge($contentData, ['sections' => $sections]);
    }

    /**
     * Copy section ids from $current onto $incoming where type matches at the
     * same position. Wholesale LLM/import writers use this so DraftDiffer can
     * pair surviving sections instead of reporting remove+insert churn.
     *
     * Never manufactures `id => null`. A type or position mismatch leaves the
     * key absent for ensure() to mint.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public function carryForward(array $current, array $incoming): array
    {
        if (! isset($incoming['sections']) || ! array_is_list($incoming['sections'])) {
            return $incoming;
        }

        if (! isset($current['sections']) || ! array_is_list($current['sections'])) {
            return $incoming;
        }

        $currentSections = $current['sections'];
        $incomingSections = $incoming['sections'];

        for ($i = 0; $i < count($incomingSections) && $i < count($currentSections); $i++) {
            if (! is_array($incomingSections[$i]) || ! is_array($currentSections[$i])) {
                continue;
            }

            $currentType = $currentSections[$i]['type'] ?? null;
            $incomingType = $incomingSections[$i]['type'] ?? null;
            $id = $currentSections[$i]['id'] ?? null;

            if ($currentType === $incomingType && is_string($id) && $id !== '') {
                $incomingSections[$i]['id'] = $id;
            }
        }

        return array_merge($incoming, ['sections' => $incomingSections]);
    }
}
