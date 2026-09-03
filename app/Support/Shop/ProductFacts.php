<?php

namespace App\Support\Shop;

use App\Models\Shop\Product;
use App\Models\Site;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProductFacts
{
    public const MAX_GROUPS = 24;

    public const MAX_GROUP_LABEL = 40;

    public const MAX_PAIRS = 40;

    public const MAX_PAIR_LABEL = 60;

    public const MAX_PAIR_VALUE = 200;

    public const MAX_TEXT = 4000;

    public const CARD_LINE_MAX = 60;

    public const KINDS = ['pairs', 'text'];

    public const SCHEMAS = ['nutrition', 'ingredients', 'material', 'size'];

    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * @var array<string, string>
     */
    private const NUTRITION_KEYS = [
        'calories' => 'calories',
        'energy' => 'calories',
        'fat' => 'fatContent',
        'carbohydrate' => 'carbohydrateContent',
        'carbohydrates' => 'carbohydrateContent',
        'protein' => 'proteinContent',
        'sugar' => 'sugarContent',
        'sugars' => 'sugarContent',
        'salt' => 'sodiumContent',
        'sodium' => 'sodiumContent',
        'serving size' => 'servingSize',
    ];

    /**
     * @return list<array{slug: string, label: string, kind: string, show_on_card: bool, schema: string|null}>
     */
    public static function groups(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $groups = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalized = self::normalizeGroup($row);
            if ($normalized !== null) {
                $groups[] = $normalized;
            }
        }

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{slug: string, label: string, kind: string, show_on_card: bool, schema: string|null}|null
     */
    public static function normalizeGroup(array $row): ?array
    {
        $slug = is_string($row['slug'] ?? null) ? $row['slug'] : '';
        $label = is_string($row['label'] ?? null) ? $row['label'] : '';
        $kind = is_string($row['kind'] ?? null) ? $row['kind'] : '';
        if ($slug === '' || $label === '' || ! in_array($kind, self::KINDS, true)) {
            return null;
        }

        $schema = $row['schema'] ?? null;
        if ($schema === '') {
            $schema = null;
        }
        if ($schema !== null && ! in_array($schema, self::SCHEMAS, true)) {
            $schema = null;
        }

        return [
            'slug' => $slug,
            'label' => $label,
            'kind' => $kind,
            'show_on_card' => (bool) ($row['show_on_card'] ?? false),
            'schema' => $schema,
        ];
    }

    /**
     * @return list<array{slug: string, label: string, kind: string, show_on_card: bool, schema: string|null}>
     */
    public static function validateGroups(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (! is_array($raw)) {
            throw ValidationException::withMessages([
                'product_fact_groups' => 'Fact groups must be a list.',
            ]);
        }

        if (count($raw) > self::MAX_GROUPS) {
            throw ValidationException::withMessages([
                'product_fact_groups' => 'A store may have at most '.self::MAX_GROUPS.' fact groups.',
            ]);
        }

        $groups = [];
        $seen = [];
        foreach (array_values($raw) as $index => $row) {
            $prefix = 'product_fact_groups.'.$index;
            if (! is_array($row)) {
                throw ValidationException::withMessages([
                    $prefix => 'Each fact group must be an object.',
                ]);
            }

            $slug = is_string($row['slug'] ?? null) ? trim($row['slug']) : '';
            if ($slug === '' || preg_match(self::SLUG_PATTERN, $slug) !== 1) {
                throw ValidationException::withMessages([
                    $prefix.'.slug' => 'Fact group slug must be kebab-case.',
                ]);
            }
            if (isset($seen[$slug])) {
                throw ValidationException::withMessages([
                    $prefix.'.slug' => 'Fact group slugs must be unique.',
                ]);
            }
            $seen[$slug] = true;

            $label = is_string($row['label'] ?? null) ? trim($row['label']) : '';
            if ($label === '') {
                throw ValidationException::withMessages([
                    $prefix.'.label' => 'Fact group label is required.',
                ]);
            }
            if (mb_strlen($label) > self::MAX_GROUP_LABEL) {
                throw ValidationException::withMessages([
                    $prefix.'.label' => 'Fact group label may not be greater than '.self::MAX_GROUP_LABEL.' characters.',
                ]);
            }

            $kind = $row['kind'] ?? null;
            if (! is_string($kind) || ! in_array($kind, self::KINDS, true)) {
                throw ValidationException::withMessages([
                    $prefix.'.kind' => 'Fact group kind must be pairs or text.',
                ]);
            }

            $schema = $row['schema'] ?? null;
            if ($schema === '') {
                $schema = null;
            }
            if ($schema !== null && (! is_string($schema) || ! in_array($schema, self::SCHEMAS, true))) {
                throw ValidationException::withMessages([
                    $prefix.'.schema' => 'Fact group schema is invalid.',
                ]);
            }

            $groups[] = [
                'slug' => $slug,
                'label' => $label,
                'kind' => $kind,
                'show_on_card' => (bool) ($row['show_on_card'] ?? false),
                'schema' => $schema,
            ];
        }

        return $groups;
    }

    /**
     * @param  list<array{slug: string, kind: string}>  $groups
     * @return array<string, array{pairs: list<array{label: string, value: string}>}|array{text: string}>
     */
    public static function validateFacts(mixed $raw, array $groups, bool $rejectUnknown = true): array
    {
        if ($raw === null) {
            return [];
        }

        if (! is_array($raw)) {
            throw ValidationException::withMessages([
                'facts' => 'Facts must be an object keyed by group slug.',
            ]);
        }

        $bySlug = [];
        foreach ($groups as $group) {
            $bySlug[$group['slug']] = $group;
        }

        $unknown = [];
        foreach (array_keys($raw) as $slug) {
            if (! is_string($slug) || ! isset($bySlug[$slug])) {
                $unknown[] = is_string($slug) ? $slug : '(invalid)';
            }
        }

        if ($rejectUnknown && $unknown !== []) {
            $valid = array_keys($bySlug);
            $validList = $valid === [] ? '(none)' : implode(', ', $valid);
            throw ValidationException::withMessages([
                'facts' => 'Unknown fact group slugs: '.implode(', ', $unknown).'. Valid slugs: '.$validList.'.',
            ]);
        }

        $out = [];
        foreach ($raw as $slug => $value) {
            if (! is_string($slug) || ! isset($bySlug[$slug])) {
                continue;
            }
            $out[$slug] = self::validateFactValue($slug, $value, $bySlug[$slug]['kind']);
        }

        return $out;
    }

    /**
     * Keep orphan slugs (removed groups) while overwriting current groups from the editor form.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $fromEditor
     * @param  list<array{slug: string, kind: string}>  $groups
     * @return array<string, mixed>
     */
    public static function mergeEditorFacts(array $existing, array $fromEditor, array $groups): array
    {
        $validated = self::validateFacts($fromEditor, $groups, rejectUnknown: true);
        foreach ($groups as $group) {
            $slug = $group['slug'];
            if (isset($validated[$slug]) && ! self::valueIsEmpty($validated[$slug])) {
                $existing[$slug] = $validated[$slug];
            } else {
                unset($existing[$slug]);
            }
        }

        return $existing === [] ? [] : $existing;
    }

    /**
     * @return array{pairs: list<array{label: string, value: string}>}|array{text: string}
     */
    public static function validateFactValue(string $slug, mixed $value, string $kind): array
    {
        if (! is_array($value)) {
            throw ValidationException::withMessages([
                'facts.'.$slug => 'Fact values must be an object.',
            ]);
        }

        if ($kind === 'text') {
            if (array_key_exists('pairs', $value) && ! array_key_exists('text', $value)) {
                throw ValidationException::withMessages([
                    'facts.'.$slug => 'This fact group is text, not pairs.',
                ]);
            }
            $text = $value['text'] ?? '';
            if (! is_string($text)) {
                throw ValidationException::withMessages([
                    'facts.'.$slug.'.text' => 'Fact text must be a string.',
                ]);
            }
            if (mb_strlen($text) > self::MAX_TEXT) {
                throw ValidationException::withMessages([
                    'facts.'.$slug.'.text' => 'Fact text may not be greater than '.self::MAX_TEXT.' characters.',
                ]);
            }

            return ['text' => $text];
        }

        if (array_key_exists('text', $value) && ! array_key_exists('pairs', $value)) {
            throw ValidationException::withMessages([
                'facts.'.$slug => 'This fact group is pairs, not text.',
            ]);
        }

        $pairs = $value['pairs'] ?? [];
        if (! is_array($pairs)) {
            throw ValidationException::withMessages([
                'facts.'.$slug.'.pairs' => 'Fact pairs must be a list.',
            ]);
        }
        if (count($pairs) > self::MAX_PAIRS) {
            throw ValidationException::withMessages([
                'facts.'.$slug.'.pairs' => 'A fact group may have at most '.self::MAX_PAIRS.' pairs.',
            ]);
        }

        $normalized = [];
        foreach (array_values($pairs) as $index => $pair) {
            if (! is_array($pair)) {
                throw ValidationException::withMessages([
                    'facts.'.$slug.'.pairs.'.$index => 'Each pair must be an object.',
                ]);
            }
            $label = is_string($pair['label'] ?? null) ? $pair['label'] : '';
            $pairValue = is_string($pair['value'] ?? null) ? $pair['value'] : '';
            if (mb_strlen($label) > self::MAX_PAIR_LABEL) {
                throw ValidationException::withMessages([
                    'facts.'.$slug.'.pairs.'.$index.'.label' => 'Pair label may not be greater than '.self::MAX_PAIR_LABEL.' characters.',
                ]);
            }
            if (mb_strlen($pairValue) > self::MAX_PAIR_VALUE) {
                throw ValidationException::withMessages([
                    'facts.'.$slug.'.pairs.'.$index.'.value' => 'Pair value may not be greater than '.self::MAX_PAIR_VALUE.' characters.',
                ]);
            }
            $normalized[] = ['label' => $label, 'value' => $pairValue];
        }

        return ['pairs' => $normalized];
    }

    public static function valueIsEmpty(mixed $value): bool
    {
        if (! is_array($value)) {
            return true;
        }

        if (array_key_exists('text', $value)) {
            return ! is_string($value['text']) || trim($value['text']) === '';
        }

        $pairs = $value['pairs'] ?? null;
        if (! is_array($pairs) || $pairs === []) {
            return true;
        }

        foreach ($pairs as $pair) {
            if (! is_array($pair)) {
                continue;
            }
            $label = is_string($pair['label'] ?? null) ? trim($pair['label']) : '';
            $pairValue = is_string($pair['value'] ?? null) ? trim($pair['value']) : '';
            if ($label !== '' || $pairValue !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array{text: string}|array{pairs: list<array{label: string, value: string}>}
     */
    public static function convertValueToKind(mixed $value, string $toKind): array
    {
        $value = is_array($value) ? $value : [];

        if ($toKind === 'text') {
            if (is_string($value['text'] ?? null) && ! array_key_exists('pairs', $value)) {
                return ['text' => $value['text']];
            }
            $lines = [];
            foreach ($value['pairs'] ?? [] as $pair) {
                if (! is_array($pair)) {
                    continue;
                }
                $label = is_string($pair['label'] ?? null) ? trim($pair['label']) : '';
                $pairValue = is_string($pair['value'] ?? null) ? trim($pair['value']) : '';
                $line = trim($label.' '.$pairValue);
                if ($line !== '') {
                    $lines[] = $line;
                }
            }

            return ['text' => implode("\n", $lines)];
        }

        if (is_array($value['pairs'] ?? null) && ! array_key_exists('text', $value)) {
            return ['pairs' => array_values($value['pairs'])];
        }
        $text = is_string($value['text'] ?? null) ? $value['text'] : '';

        return ['pairs' => [['label' => '', 'value' => $text]]];
    }

    public static function convertProductsToKind(Site $site, string $slug, string $toKind): void
    {
        Product::query()->where('site_id', $site->id)->whereNotNull('facts')->each(function (Product $product) use ($slug, $toKind): void {
            $facts = is_array($product->facts) ? $product->facts : [];
            if (! isset($facts[$slug]) || ! is_array($facts[$slug]) || self::valueIsEmpty($facts[$slug])) {
                return;
            }
            $facts[$slug] = self::convertValueToKind($facts[$slug], $toKind);
            $product->update(['facts' => $facts]);
        });
    }

    /**
     * Site groups that have a non-empty value on this product, in site order.
     *
     * @param  list<array{slug: string, label: string, kind: string, show_on_card: bool, schema: string|null}>  $groups
     * @param  array<string, mixed>  $facts
     * @return list<array{slug: string, label: string, kind: string, show_on_card: bool, schema: string|null, value: array{pairs?: list<array{label: string, value: string}>, text?: string}}>
     */
    public static function visibleTabs(array $groups, array $facts): array
    {
        $tabs = [];
        foreach ($groups as $group) {
            $value = self::convertValueToKind($facts[$group['slug']] ?? null, $group['kind']);
            if (self::valueIsEmpty($value)) {
                continue;
            }
            $tabs[] = $group + ['value' => $value];
        }

        return $tabs;
    }

    /**
     * Values for the site's current groups only. Orphan slugs stay in the
     * product row and are omitted from snapshots and storefront payloads.
     *
     * @param  list<array{slug: string, kind: string}>  $groups
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public static function forCurrentGroups(array $groups, array $facts): array
    {
        $out = [];
        foreach ($groups as $group) {
            $slug = $group['slug'];
            $value = $facts[$slug] ?? null;
            if (self::valueIsEmpty($value)) {
                continue;
            }
            $out[$slug] = $value;
        }

        return $out;
    }

    /**
     * Flagged pairs contribute "label value"; flagged text contributes the
     * first line. The joined line is truncated to CARD_LINE_MAX.
     *
     * @param  list<array{slug: string, kind: string, show_on_card: bool}>  $groups
     * @param  array<string, mixed>  $facts
     */
    public static function cardLine(array $groups, array $facts): ?string
    {
        $parts = [];
        foreach ($groups as $group) {
            if (! ($group['show_on_card'] ?? false)) {
                continue;
            }
            $value = $facts[$group['slug']] ?? null;
            if (! is_array($value)) {
                continue;
            }
            if (($group['kind'] ?? '') === 'text') {
                $text = is_string($value['text'] ?? null) ? trim($value['text']) : '';
                if ($text === '') {
                    continue;
                }
                $firstLine = trim(explode("\n", str_replace("\r\n", "\n", $text), 2)[0]);
                if ($firstLine !== '') {
                    $parts[] = $firstLine;
                }

                continue;
            }
            if (! is_array($value['pairs'] ?? null)) {
                continue;
            }
            $pair = null;
            foreach ($value['pairs'] as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }
                $label = is_string($candidate['label'] ?? null) ? trim($candidate['label']) : '';
                $pairValue = is_string($candidate['value'] ?? null) ? trim($candidate['value']) : '';
                if ($label === '' && $pairValue === '') {
                    continue;
                }
                $pair = trim($label.' '.$pairValue);
                break;
            }
            if (is_string($pair) && $pair !== '') {
                $parts[] = $pair;
            }
        }

        if ($parts === []) {
            return null;
        }

        $line = implode(' · ', $parts);
        if (mb_strlen($line) <= self::CARD_LINE_MAX) {
            return $line;
        }

        return mb_substr($line, 0, self::CARD_LINE_MAX);
    }

    /**
     * @param  list<array{slug: string, label: string, kind: string, schema: string|null}>  $groups
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function applyJsonLd(array $payload, array $groups, array $facts): array
    {
        $additional = [];
        $nutrition = [];

        foreach (self::visibleTabs($groups, $facts) as $group) {
            $schema = $group['schema'] ?? null;
            $value = $group['value'];
            if ($schema === 'nutrition') {
                foreach ($value['pairs'] ?? [] as $pair) {
                    if (! is_array($pair)) {
                        continue;
                    }
                    $label = is_string($pair['label'] ?? null) ? $pair['label'] : '';
                    $pairValue = is_string($pair['value'] ?? null) ? trim($pair['value']) : '';
                    if ($pairValue === '') {
                        continue;
                    }
                    $mapped = self::nutritionProperty($label);
                    if ($mapped !== null) {
                        $nutrition[$mapped] = $pairValue;
                    } else {
                        $additional[] = self::propertyValue($label !== '' ? $label : $group['label'], $pairValue);
                    }
                }

                continue;
            }

            if ($schema === 'material') {
                $scalar = self::scalarValue($group, $value);
                if ($scalar !== null) {
                    $payload['material'] = $scalar;
                }

                continue;
            }

            if ($schema === 'size') {
                $scalar = self::scalarValue($group, $value);
                if ($scalar !== null) {
                    $payload['size'] = $scalar;
                }

                continue;
            }

            if ($schema === 'ingredients') {
                $scalar = self::scalarValue($group, $value);
                if ($scalar !== null) {
                    $additional[] = self::propertyValue($group['label'], $scalar);
                }

                continue;
            }

            if (is_array($value['pairs'] ?? null)) {
                foreach ($value['pairs'] as $pair) {
                    if (! is_array($pair)) {
                        continue;
                    }
                    $label = is_string($pair['label'] ?? null) ? trim($pair['label']) : '';
                    $pairValue = is_string($pair['value'] ?? null) ? trim($pair['value']) : '';
                    if ($pairValue === '' && $label === '') {
                        continue;
                    }
                    $additional[] = self::propertyValue(
                        $label !== '' ? $label : $group['label'],
                        $pairValue !== '' ? $pairValue : $label,
                    );
                }

                continue;
            }

            $scalar = self::scalarValue($group, $value);
            if ($scalar !== null) {
                $additional[] = self::propertyValue($group['label'], $scalar);
            }
        }

        if ($nutrition !== []) {
            $payload['nutrition'] = ['@type' => 'NutritionInformation'] + $nutrition;
        }

        if (count($additional) === 1) {
            $payload['additionalProperty'] = $additional[0];
        } elseif (count($additional) > 1) {
            $payload['additionalProperty'] = $additional;
        }

        return $payload;
    }

    /**
     * @return array<string, array{label: string, groups: list<array{slug: string, label: string, kind: string, show_on_card: bool, schema: string|null}>}>
     */
    public static function presets(): array
    {
        /** @var array<string, array{label: string, groups: list<array<string, mixed>>}> $presets */
        $presets = config('shop_fact_presets', []);

        return $presets;
    }

    /**
     * @return list<array{slug: string, label: string, kind: string, show_on_card: bool, schema: string|null}>
     */
    public static function presetGroups(string $key): array
    {
        $presets = self::presets();
        if (! isset($presets[$key]) || ! is_array($presets[$key]['groups'] ?? null)) {
            throw ValidationException::withMessages([
                'preset' => 'Unknown fact group preset.',
            ]);
        }

        return self::validateGroups($presets[$key]['groups']);
    }

    /**
     * @param  list<string>  $existingSlugs
     */
    public static function uniqueSlug(string $label, array $existingSlugs): string
    {
        $base = Str::slug($label);
        if ($base === '' || preg_match(self::SLUG_PATTERN, $base) !== 1) {
            $base = 'group';
        }

        $slug = $base;
        $n = 2;
        $taken = array_fill_keys($existingSlugs, true);
        while (isset($taken[$slug])) {
            $slug = $base.'-'.$n;
            $n++;
        }

        return $slug;
    }

    public static function productsWithValuesCount(Site $site, string $slug): int
    {
        return Product::query()
            ->where('site_id', $site->id)
            ->whereNotNull('facts')
            ->get(['facts'])
            ->filter(function (Product $product) use ($slug): bool {
                $facts = $product->facts;

                return is_array($facts) && ! self::valueIsEmpty($facts[$slug] ?? null);
            })
            ->count();
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public static function agentSchemaError(array $facts, array $groups): ?string
    {
        try {
            self::validateFacts($facts, $groups, rejectUnknown: true);

            return null;
        } catch (ValidationException $exception) {
            return collect($exception->errors())->flatten()->first();
        }
    }

    /**
     * JSON Schema fragment for agent draft/update operations.
     *
     * @return array<string, mixed>
     */
    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'text' => ['type' => 'string', 'maxLength' => self::MAX_TEXT],
                    'pairs' => [
                        'type' => 'array',
                        'maxItems' => self::MAX_PAIRS,
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['label', 'value'],
                            'properties' => [
                                'label' => ['type' => 'string', 'maxLength' => self::MAX_PAIR_LABEL],
                                'value' => ['type' => 'string', 'maxLength' => self::MAX_PAIR_VALUE],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array{kind: string, label: string}  $group
     * @param  array{pairs?: list<array{label: string, value: string}>, text?: string}  $value
     */
    private static function scalarValue(array $group, array $value): ?string
    {
        if (($group['kind'] ?? '') === 'text') {
            $text = is_string($value['text'] ?? null) ? trim($value['text']) : '';

            return $text !== '' ? $text : null;
        }

        $parts = [];
        foreach ($value['pairs'] ?? [] as $pair) {
            if (! is_array($pair)) {
                continue;
            }
            $pairValue = is_string($pair['value'] ?? null) ? trim($pair['value']) : '';
            if ($pairValue !== '') {
                $parts[] = $pairValue;
            }
        }

        if ($parts === []) {
            return null;
        }

        return implode(', ', $parts);
    }

    /**
     * @return array{@type: string, name: string, value: string}
     */
    private static function propertyValue(string $name, string $value): array
    {
        return [
            '@type' => 'PropertyValue',
            'name' => $name,
            'value' => $value,
        ];
    }

    private static function nutritionProperty(string $label): ?string
    {
        $normalized = mb_strtolower(trim($label));
        $normalized = preg_replace('/\s*\([^)]*\)/', '', $normalized) ?? $normalized;
        $normalized = trim(preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized);

        if ($normalized === '') {
            return null;
        }

        if (isset(self::NUTRITION_KEYS[$normalized])) {
            return self::NUTRITION_KEYS[$normalized];
        }

        $first = explode(' ', $normalized)[0];

        return self::NUTRITION_KEYS[$first] ?? null;
    }
}
