<?php

namespace App\Support\Shop;

use App\Services\Shop\CatalogueListing;

final class ShopListingQuery
{
    /**
     * @param  array<string, mixed>  $query
     * @return array{
     *     sort: string,
     *     defaultSort: string,
     *     sortExplicit: bool,
     *     page: int,
     *     cat: list<string>,
     *     price: list<string>,
     *     avail: list<string>,
     *     opt: list<string>,
     *     attrs: array<string, list<string>>
     * }
     */
    public static function parse(array $query, bool $hasRating, ?string $defaultSort = null): array
    {
        $attrs = [];
        $rawAttrs = $query['attr'] ?? [];
        if (is_array($rawAttrs)) {
            foreach ($rawAttrs as $group => $values) {
                if (! is_string($group) && ! is_int($group)) {
                    continue;
                }
                $list = self::listValues($values);
                if ($list !== []) {
                    $attrs[(string) $group] = $list;
                }
            }
        }

        $resolvedDefaultSort = CatalogueListing::resolveSort(null, $hasRating, $defaultSort);
        $rawSort = is_string($query['sort'] ?? null) ? $query['sort'] : null;

        return [
            'sort' => CatalogueListing::resolveSort(
                $rawSort,
                $hasRating,
                $defaultSort,
            ),
            'defaultSort' => $resolvedDefaultSort,
            'sortExplicit' => $rawSort !== null && $rawSort !== '',
            'page' => self::page($query['page'] ?? null),
            'cat' => self::listValues($query['cat'] ?? null),
            'price' => self::listValues($query['price'] ?? null),
            'avail' => self::listValues($query['avail'] ?? null),
            'opt' => self::listValues($query['opt'] ?? null),
            'attrs' => $attrs,
        ];
    }

    /**
     * Non-numeric page values become 1. Out-of-range clamping happens in paginate().
     */
    public static function page(mixed $value): int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : 1;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $page = (int) $value;

            return $page > 0 ? $page : 1;
        }

        return 1;
    }

    /**
     * @param  array{
     *     sort?: string,
     *     defaultSort?: string,
     *     page?: int,
     *     cat?: list<string>,
     *     price?: list<string>,
     *     avail?: list<string>,
     *     opt?: list<string>,
     *     attrs?: array<string, list<string>>
     * }  $state
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function toQuery(array $state, array $overrides = []): array
    {
        $merged = array_merge($state, $overrides);
        $query = [];

        $sort = $merged['sort'] ?? CatalogueListing::SORT_FEATURED;
        $defaultSort = $merged['defaultSort'] ?? CatalogueListing::SORT_FEATURED;
        if (is_string($sort) && $sort !== '' && $sort !== $defaultSort) {
            $query['sort'] = $sort;
        }

        foreach (['cat', 'price', 'avail', 'opt'] as $key) {
            $values = self::listValues($merged[$key] ?? []);
            if ($values !== []) {
                $query[$key] = $values;
            }
        }

        $attrs = is_array($merged['attrs'] ?? null) ? $merged['attrs'] : [];
        foreach ($attrs as $group => $values) {
            $list = self::listValues($values);
            if ($list !== []) {
                $query['attr'][(string) $group] = $list;
            }
        }

        $resetPage = array_key_exists('sort', $overrides)
            || array_key_exists('cat', $overrides)
            || array_key_exists('price', $overrides)
            || array_key_exists('avail', $overrides)
            || array_key_exists('opt', $overrides)
            || array_key_exists('attrs', $overrides);

        $page = $resetPage ? 1 : (int) ($merged['page'] ?? 1);
        if ($page > 1) {
            $query['page'] = $page;
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function activeFilterCount(array $state): int
    {
        $count = count($state['cat'] ?? [])
            + count($state['price'] ?? [])
            + count($state['avail'] ?? [])
            + count($state['opt'] ?? []);

        foreach ($state['attrs'] ?? [] as $values) {
            $count += count(self::listValues($values));
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $overrides
     */
    public static function url(string $path, array $state, array $overrides = []): string
    {
        $query = http_build_query(self::toQuery($state, $overrides), '', '&', PHP_QUERY_RFC3986);

        return $query === '' ? $path : $path.'?'.$query;
    }

    /**
     * @param  list<array{id: string, name: string, param: string, values: list<array{id: string, label: string, checked: bool}>}>  $groups
     * @param  array<string, mixed>  $state
     * @return list<array{label: string, href: string}>
     */
    public static function pills(array $groups, array $state, string $path): array
    {
        $pills = [];
        foreach ($groups as $group) {
            foreach ($group['values'] as $value) {
                if (! ($value['checked'] ?? false)) {
                    continue;
                }
                $pills[] = [
                    'label' => (string) $value['label'],
                    'href' => self::url($path, $state, self::withoutValue($state, (string) $group['id'], (string) $value['id'])),
                ];
            }
        }

        return $pills;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function withoutValue(array $state, string $groupId, string $valueId): array
    {
        $overrides = [];
        if (in_array($groupId, ['cat', 'price', 'avail', 'opt'], true)) {
            $overrides[$groupId] = array_values(array_filter(
                self::listValues($state[$groupId] ?? []),
                fn (string $item): bool => $item !== $valueId,
            ));

            return $overrides;
        }

        $attrs = is_array($state['attrs'] ?? null) ? $state['attrs'] : [];
        $attrs[$groupId] = array_values(array_filter(
            self::listValues($attrs[$groupId] ?? []),
            fn (string $item): bool => $item !== $valueId,
        ));
        $overrides['attrs'] = $attrs;

        return $overrides;
    }

    /**
     * @return list<string>
     */
    public static function listValues(mixed $value): array
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $item) {
                if (is_int($item) || is_float($item) || (is_string($item) && $item !== '')) {
                    $out[] = (string) $item;
                }
            }

            return array_values(array_unique($out));
        }

        if (is_int($value) || is_float($value)) {
            return [(string) $value];
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('trim', explode(',', $value)),
            fn (string $item): bool => $item !== '',
        )));
    }
}
