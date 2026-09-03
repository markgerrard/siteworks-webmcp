<?php

namespace App\Services\Site\Editor;

use App\Services\Site\PageLayoutRegistry;
use App\Services\Site\SectionSchema;
use Illuminate\Support\Arr;

final class SectionDescriber
{
    public function __construct(
        private readonly PageLayoutRegistry $layouts,
        private readonly SectionSchema $schema,
    ) {}

    /**
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    public function describe(array $section, string $pageKind, ?int $storedIndex, bool $mutable): array
    {
        $type = $section['type'];
        $schemaOptions = $this->schema->isKnownSectionType($type)
            ? $this->schema->variantOptionsFor($type)
            : [];

        return [
            'stored_index' => $storedIndex,
            'section_id' => $section['id'] ?? null,
            'type' => $type,
            'variant' => $section['variant'] ?? null,
            'variant_options' => array_values(array_unique([
                ...$this->layouts->variantOptionsFor($pageKind, $type),
                ...$schemaOptions,
            ])),
            'mutable' => $mutable,
            'fields' => $this->enumerateFields($type, $section),
            'repeatable_lists' => $this->schema->isKnownSectionType($type)
                ? $this->schema->repeatableLists($type)
                : [],
            'refs' => $this->refs($section),
        ];
    }

    /**
     * @param  array<string, mixed>  $section
     * @return list<array{path: string, type: string, value: mixed, constraints: array<string, mixed>}>
     */
    private function enumerateFields(string $type, array $section): array
    {
        $definitions = config("site_sections.{$type}.fields", []);
        if (! is_array($definitions)) {
            return [];
        }

        $fields = [];

        foreach ($definitions as $pattern => $rules) {
            if (! is_string($pattern) || ! is_array($rules)) {
                continue;
            }

            foreach ($this->expandFieldPattern($pattern, $section) as $path => $value) {
                $fields[] = [
                    'path' => $path,
                    'type' => $rules['type'] ?? 'plain',
                    'value' => $value,
                    'constraints' => $this->constraints($rules),
                ];
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function expandFieldPattern(string $pattern, array $section): array
    {
        if (! str_contains($pattern, '*')) {
            return [$pattern => Arr::get($section, $pattern)];
        }

        [$prefix, $suffix] = explode('.*.', $pattern, 2);
        $collection = Arr::get($section, $prefix);
        $expanded = [];

        if (is_array($collection)) {
            foreach (array_keys($collection) as $index) {
                $path = "{$prefix}.{$index}.{$suffix}";
                $expanded[$path] = Arr::get($section, $path);
            }
        }

        $expanded["{$prefix}.{n}.{$suffix}"] = null;

        return $expanded;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function constraints(array $rules): array
    {
        return array_filter(
            [
                'min' => $rules['min'] ?? null,
                'max' => $rules['max'] ?? null,
                'required' => $rules['required'] ?? null,
                'options' => $rules['options'] ?? $rules['values'] ?? null,
                'precision' => $rules['precision'] ?? null,
            ],
            fn (mixed $value): bool => $value !== null,
        );
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array{item_ids?: list<int>, pair_ids?: list<int>}
     */
    private function refs(array $section): array
    {
        $refs = [];

        foreach (['item_ids', 'pair_ids'] as $key) {
            if (! isset($section[$key]) || ! is_array($section[$key])) {
                continue;
            }

            $refs[$key] = array_values(array_map(intval(...), $section[$key]));
        }

        return $refs;
    }
}
