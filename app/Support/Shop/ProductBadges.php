<?php

namespace App\Support\Shop;

final class ProductBadges
{
    /**
     * @param  list<array{slug?: string, label?: string, badge?: bool, tone?: string}>  $tags
     * @return list<array{slug: string, label: string, badge: bool, tone: string}>
     */
    public static function visible(array $tags, ?int $max = 2): array
    {
        $out = [];
        foreach ($tags as $tag) {
            if (! is_array($tag) || ! ($tag['badge'] ?? false)) {
                continue;
            }
            $label = trim((string) ($tag['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $out[] = [
                'slug' => (string) ($tag['slug'] ?? ''),
                'label' => $label,
                'badge' => true,
                'tone' => (string) ($tag['tone'] ?? 'accent'),
            ];
            if ($max !== null && count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    public static function toneStyle(string $tone): string
    {
        return match ($tone) {
            'success' => 'background-color: var(--color-accent); color: var(--color-text-on-accent, #ffffff); filter: saturate(0.85);',
            'warning' => 'background-color: var(--color-accent); color: var(--color-text-on-accent, #ffffff); opacity: 0.92;',
            'neutral' => 'background-color: var(--color-surface-alt); color: var(--color-text);',
            default => 'background-color: var(--brand-accent, var(--color-accent)); color: var(--color-text-on-accent, #ffffff);',
        };
    }

    /**
     * @param  list<array{slug?: string, label?: string, badge?: bool, tone?: string}>  $tags
     */
    public static function markup(array $tags, ?int $max, string $placement): string
    {
        $visible = self::visible($tags, $max);
        if ($visible === []) {
            return '';
        }

        $pills = '';
        foreach ($visible as $badge) {
            $pills .= '<span style="display: inline-flex; align-items: center; border-radius: 9999px; padding: 0.125rem 0.5rem; font-size: 0.75rem; font-weight: 600; '.self::toneStyle($badge['tone']).'">'.e($badge['label']).'</span>';
        }

        if ($placement === 'pdp') {
            return '<div style="display: flex; flex-wrap: wrap; gap: 0.375rem; margin-bottom: 0.75rem;">'.$pills.'</div>';
        }

        return '<div style="position: absolute; top: 0.5rem; left: 0.5rem; z-index: 1; display: flex; flex-direction: column; gap: 0.25rem; pointer-events: none;">'.$pills.'</div>';
    }
}
