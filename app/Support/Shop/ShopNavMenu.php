<?php

namespace App\Support\Shop;

use App\Models\Site;
use App\Services\Shop\SnapshotReader;
use App\Support\ChromeKnobs;

final class ShopNavMenu
{
    /**
     * Visible top-level categories from the live snapshot, in tree sort order.
     * Hidden categories and their subtrees are omitted.
     *
     * @return list<array{type: string, label: string, href: string, children: list<array{type: string, label: string, href: string, children: list<empty>}>}>
     */
    public static function categories(Site $site): array
    {
        if (! $site->hasPurchasableShop()) {
            return [];
        }

        $snapshot = app(SnapshotReader::class)->forSite($site->id) ?? [];
        $cats = $snapshot['categories'] ?? [];
        if (! is_array($cats) || $cats === []) {
            return [];
        }

        $tree = [];
        foreach ($cats as $cat) {
            if (! self::isVisibleTopLevel($cat)) {
                continue;
            }

            $tree[] = self::node($cat, $cats);
        }

        return $tree;
    }

    /**
     * Render-time only: turn a stored Shop link into a group when the knob
     * is dropdown/mega and the snapshot has at least one visible category.
     *
     * @param  list<array<string, mixed>>  $navItems
     * @return list<array<string, mixed>>
     */
    public static function expand(Site $site, array $navItems): array
    {
        $style = ChromeKnobs::shopNavStyle($site);
        if ($style === 'link') {
            return $navItems;
        }

        $tree = self::categories($site);
        if ($tree === []) {
            return $navItems;
        }

        return array_map(function (array $item) use ($style, $tree): array {
            if (($item['type'] ?? null) !== 'shop') {
                return $item;
            }

            $label = is_string($item['label'] ?? null) && $item['label'] !== '' ? $item['label'] : 'Shop';
            $expanded = [
                'type' => 'group',
                'label' => $label,
                'footer_label' => is_string($item['footer_label'] ?? null) && $item['footer_label'] !== ''
                    ? $item['footer_label']
                    : $label,
                'href' => is_string($item['href'] ?? null) && $item['href'] !== '' ? $item['href'] : '/shop',
                'page_type' => null,
                'shop_nav_style' => $style,
                'children' => $tree,
            ];

            if ($style === 'mega') {
                $expanded['all_products_href'] = '/shop';
            }

            return $expanded;
        }, $navItems);
    }

    /**
     * @param  array<string, mixed>  $cat
     */
    private static function isVisibleTopLevel(mixed $cat): bool
    {
        if (! is_array($cat)) {
            return false;
        }
        if (($cat['visibility'] ?? 'visible') !== 'visible') {
            return false;
        }

        $parent = $cat['parent_slug'] ?? null;
        $depth = (int) ($cat['depth'] ?? 1);

        return ($parent === null || $parent === '') && $depth <= 1;
    }

    /**
     * @param  array<string, mixed>  $cat
     * @param  array<string, mixed>  $all
     * @return array{type: string, label: string, href: string, children: list<array{type: string, label: string, href: string, children: list<empty>}>}
     */
    private static function node(array $cat, array $all): array
    {
        $path = (string) ($cat['path'] ?? $cat['slug'] ?? '');
        $children = [];
        foreach ($cat['children'] ?? [] as $slug) {
            if (! is_string($slug) || $slug === '') {
                continue;
            }
            $child = $all[$slug] ?? null;
            if (! is_array($child) || ($child['visibility'] ?? 'visible') !== 'visible') {
                continue;
            }

            $childPath = (string) ($child['path'] ?? $slug);
            $children[] = [
                'type' => 'category',
                'label' => (string) ($child['name'] ?? $slug),
                'href' => ShopUrls::collection($childPath),
                'children' => [],
            ];
        }

        return [
            'type' => 'category',
            'label' => (string) ($cat['name'] ?? $cat['slug'] ?? 'Category'),
            'href' => ShopUrls::collection($path),
            'children' => $children,
        ];
    }
}
