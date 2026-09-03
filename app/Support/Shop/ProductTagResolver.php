<?php

namespace App\Support\Shop;

final class ProductTagResolver
{
    /**
     * @param  list<array{slug: string, label: string, show_as_badge: bool, tone: string}>  $vocabulary
     * @param  list<string>  $manualSlugs
     * @param  list<string>  $autoSlugs
     * @param  array<string, array{enabled: bool, label: string, show_as_badge: bool, tone: string, params: array<string, int>}>  $autoConfig
     * @return list<array{slug: string, label: string, badge: bool, tone: string}>
     */
    public static function resolve(array $vocabulary, array $manualSlugs, array $autoSlugs, array $autoConfig): array
    {
        $manual = array_fill_keys($manualSlugs, true);
        $out = [];
        $seen = [];

        foreach ($vocabulary as $tag) {
            $slug = $tag['slug'];
            if (! isset($manual[$slug]) || isset($seen[$slug])) {
                continue;
            }
            $out[] = [
                'slug' => $slug,
                'label' => $tag['label'],
                'badge' => (bool) $tag['show_as_badge'],
                'tone' => $tag['tone'],
            ];
            $seen[$slug] = true;
        }

        $autoSet = array_fill_keys($autoSlugs, true);
        foreach (AutoTagConfig::RULES as $rule) {
            if (! isset($autoSet[$rule]) || isset($seen[$rule])) {
                continue;
            }
            $cfg = $autoConfig[$rule] ?? null;
            if (! is_array($cfg)) {
                continue;
            }
            $out[] = [
                'slug' => $rule,
                'label' => $cfg['label'],
                'badge' => (bool) $cfg['show_as_badge'],
                'tone' => $cfg['tone'],
            ];
            $seen[$rule] = true;
        }

        return $out;
    }
}
