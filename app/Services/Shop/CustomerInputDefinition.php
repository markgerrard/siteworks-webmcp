<?php

namespace App\Services\Shop;

use Illuminate\Validation\ValidationException;

final class CustomerInputDefinition
{
    public const KINDS = ['text', 'textarea', 'choice', 'image'];

    /**
     * @return list<array<string, mixed>>
     */
    public static function normalize(mixed $raw): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }

        if (! is_array($raw) || ! array_is_list($raw)) {
            throw ValidationException::withMessages([
                'customer_inputs' => ['Must be a list of at most '.self::maxInputs().' inputs.'],
            ]);
        }

        if (count($raw) > self::maxInputs()) {
            throw ValidationException::withMessages([
                'customer_inputs' => ['At most '.self::maxInputs().' inputs.'],
            ]);
        }

        $out = [];
        $seen = [];

        foreach ($raw as $index => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages([
                    "customer_inputs.{$index}" => ['Each input must be an object.'],
                ]);
            }

            $normalized = self::normalizeOne($row, $index);
            $slug = $normalized['slug'];
            if (isset($seen[$slug])) {
                throw ValidationException::withMessages([
                    "customer_inputs.{$index}.slug" => ['Each slug must be unique.'],
                ]);
            }
            $seen[$slug] = true;
            $out[] = $normalized;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function normalizeOne(array $row, int $index): array
    {
        $prefix = "customer_inputs.{$index}";
        $kind = $row['kind'] ?? null;
        if (! is_string($kind) || ! in_array($kind, self::KINDS, true)) {
            throw ValidationException::withMessages([
                "{$prefix}.kind" => ['Kind must be text, textarea, choice, or image.'],
            ]);
        }

        $slug = $row['slug'] ?? null;
        if (! is_string($slug) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            throw ValidationException::withMessages([
                "{$prefix}.slug" => ['Slug must be kebab-case.'],
            ]);
        }

        $label = $row['label'] ?? null;
        if (! is_string($label) || trim($label) === '') {
            throw ValidationException::withMessages([
                "{$prefix}.label" => ['Label is required.'],
            ]);
        }

        $help = $row['help'] ?? '';
        if (! is_string($help)) {
            throw ValidationException::withMessages([
                "{$prefix}.help" => ['Help must be a string.'],
            ]);
        }
        if (mb_strlen($help) > (int) config('shop_input_presets.max_help', 120)) {
            throw ValidationException::withMessages([
                "{$prefix}.help" => ['Help must be at most 120 characters.'],
            ]);
        }

        $required = (bool) ($row['required'] ?? false);

        $normalized = [
            'slug' => $slug,
            'label' => trim($label),
            'kind' => $kind,
            'required' => $required,
            'help' => $help,
        ];

        if ($kind === 'text' || $kind === 'textarea') {
            $maxChars = $row['max_chars'] ?? (int) config('shop_input_presets.max_chars', 500);
            if (! is_int($maxChars) && ! (is_string($maxChars) && ctype_digit($maxChars))) {
                throw ValidationException::withMessages([
                    "{$prefix}.max_chars" => ['max_chars must be an integer between 1 and 500.'],
                ]);
            }
            $maxChars = (int) $maxChars;
            if ($maxChars < 1 || $maxChars > (int) config('shop_input_presets.max_chars', 500)) {
                throw ValidationException::withMessages([
                    "{$prefix}.max_chars" => ['max_chars must be an integer between 1 and 500.'],
                ]);
            }
            $pattern = $row['pattern'] ?? null;
            if ($pattern !== null && (! is_string($pattern) || ! array_key_exists($pattern, self::patterns()))) {
                throw ValidationException::withMessages([
                    "{$prefix}.pattern" => ['Pattern must be a named preset or empty.'],
                ]);
            }
            $normalized['max_chars'] = $maxChars;
            $normalized['pattern'] = is_string($pattern) ? $pattern : null;

            return $normalized;
        }

        if ($kind === 'choice') {
            $options = $row['options'] ?? null;
            if (! is_array($options) || ! array_is_list($options) || $options === []) {
                throw ValidationException::withMessages([
                    "{$prefix}.options" => ['Choice needs between 1 and 12 options.'],
                ]);
            }
            $maxOptions = (int) config('shop_input_presets.max_options', 12);
            if (count($options) > $maxOptions) {
                throw ValidationException::withMessages([
                    "{$prefix}.options" => ['Choice needs between 1 and 12 options.'],
                ]);
            }
            $clean = [];
            foreach ($options as $option) {
                if (! is_string($option) || trim($option) === '') {
                    throw ValidationException::withMessages([
                        "{$prefix}.options" => ['Each option must be a non-empty string.'],
                    ]);
                }
                $clean[] = trim($option);
            }
            $normalized['options'] = $clean;

            return $normalized;
        }

        $maxFiles = $row['max_files'] ?? 1;
        if (! is_int($maxFiles) && ! (is_string($maxFiles) && ctype_digit($maxFiles))) {
            throw ValidationException::withMessages([
                "{$prefix}.max_files" => ['max_files must be an integer between 1 and 3.'],
            ]);
        }
        $maxFiles = (int) $maxFiles;
        if ($maxFiles < 1 || $maxFiles > (int) config('shop_input_presets.max_files', 3)) {
            throw ValidationException::withMessages([
                "{$prefix}.max_files" => ['max_files must be an integer between 1 and 3.'],
            ]);
        }
        $normalized['max_files'] = $maxFiles;

        return $normalized;
    }

    /**
     * @return array<string, array{label: string, reject?: string, allow?: string}>
     */
    public static function patterns(): array
    {
        $patterns = config('shop_input_presets.patterns', []);

        return is_array($patterns) ? $patterns : [];
    }

    /**
     * @return list<array{key: string, label: string, definition: array<string, mixed>}>
     */
    public static function presets(): array
    {
        $presets = config('shop_input_presets.presets', []);

        return is_array($presets) ? array_values($presets) : [];
    }

    public static function maxInputs(): int
    {
        return (int) config('shop_input_presets.max_inputs', 3);
    }

    /**
     * JSON Schema fragment shared by agent draft ops.
     *
     * @return array<string, mixed>
     */
    public static function inputSchema(): array
    {
        return [
            'type' => 'array',
            'maxItems' => self::maxInputs(),
            'items' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['slug', 'label', 'kind'],
                'properties' => [
                    'slug' => ['type' => 'string', 'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$'],
                    'label' => ['type' => 'string'],
                    'kind' => ['type' => 'string', 'enum' => self::KINDS],
                    'required' => ['type' => 'boolean'],
                    'max_chars' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
                    'pattern' => ['type' => ['string', 'null']],
                    'options' => [
                        'type' => 'array',
                        'maxItems' => 12,
                        'items' => ['type' => 'string'],
                    ],
                    'max_files' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3],
                    'help' => ['type' => 'string', 'maxLength' => 120],
                ],
            ],
        ];
    }
}
