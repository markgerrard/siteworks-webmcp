<?php

namespace App\Services\Shop;

use Illuminate\Validation\ValidationException;

final class LinePersonalisation
{
    /**
     * @param  list<array<string, mixed>>  $definitions
     * @param  array<string, mixed>  $submitted
     * @return array<string, array<string, mixed>>|null
     */
    public static function freeze(array $definitions, array $submitted): ?array
    {
        $definitions = CustomerInputDefinition::normalize($definitions);
        $allowed = [];
        foreach ($definitions as $definition) {
            $allowed[$definition['slug']] = $definition;
        }

        foreach (array_keys($submitted) as $slug) {
            if (! isset($allowed[$slug])) {
                throw ValidationException::withMessages([
                    "personalisation.{$slug}" => ['That field is not defined on this product.'],
                ]);
            }
        }

        $frozen = [];
        foreach ($definitions as $definition) {
            $slug = $definition['slug'];
            $value = $submitted[$slug] ?? null;
            $captured = self::captureValue($definition, $value);
            if ($captured === null) {
                if ($definition['required']) {
                    throw ValidationException::withMessages([
                        "personalisation.{$slug}" => [self::requiredMessage($definition)],
                    ]);
                }
            }

            $frozen[$slug] = array_merge($definition, ['value' => $captured]);
        }

        if ($frozen === []) {
            return null;
        }

        self::assertPayloadSize($frozen);

        return $frozen;
    }

    /**
     * @param  array<string, mixed>|null  $personalisation
     */
    public static function hash(?array $personalisation): string
    {
        if ($personalisation === null || $personalisation === []) {
            return '';
        }

        return sha1(self::canonicalJson($personalisation));
    }

    /**
     * @param  array<string, mixed>  $personalisation
     * @return list<array{path: string, name: string, bytes: int, mime: string}>
     */
    public static function imageFiles(?array $personalisation): array
    {
        if ($personalisation === null) {
            return [];
        }

        $files = [];
        foreach ($personalisation as $entry) {
            if (! is_array($entry) || ($entry['kind'] ?? null) !== 'image') {
                continue;
            }
            $value = $entry['value'] ?? [];
            if (! is_array($value)) {
                continue;
            }
            foreach ($value as $file) {
                if (is_array($file) && is_string($file['path'] ?? null) && $file['path'] !== '') {
                    $files[] = $file;
                }
            }
        }

        return $files;
    }

    /**
     * @param  array<string, mixed>|null  $personalisation
     * @return list<array<string, mixed>>
     */
    public static function definitionsFromFrozen(?array $personalisation): array
    {
        if ($personalisation === null || $personalisation === []) {
            return [];
        }

        $defs = [];
        foreach ($personalisation as $slug => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $kind = (string) ($entry['kind'] ?? 'text');
            $definition = [
                'slug' => (string) $slug,
                'label' => (string) ($entry['label'] ?? $slug),
                'kind' => $kind,
                'required' => (bool) ($entry['required'] ?? false),
                'help' => (string) ($entry['help'] ?? ''),
            ];
            if ($kind === 'text' || $kind === 'textarea') {
                $definition['max_chars'] = (int) ($entry['max_chars'] ?? 500);
                $definition['pattern'] = $entry['pattern'] ?? null;
            } elseif ($kind === 'choice') {
                $value = $entry['value'] ?? '';
                $definition['options'] = is_array($entry['options'] ?? null)
                    ? $entry['options']
                    : (is_string($value) && $value !== '' ? [$value] : ['—']);
            } else {
                $definition['max_files'] = (int) ($entry['max_files'] ?? 3);
            }
            $defs[] = $definition;
        }

        return $defs;
    }

    /**
     * @param  array<string, mixed>|null  $personalisation
     * @return list<array<string, mixed>>
     */
    public static function displayRows(?array $personalisation): array
    {
        if ($personalisation === null || $personalisation === []) {
            return [];
        }

        $rows = [];
        foreach ($personalisation as $slug => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $kind = (string) ($entry['kind'] ?? 'text');
            $label = (string) ($entry['label'] ?? $slug);
            $value = $entry['value'] ?? null;
            if ($value === null || $value === [] || $value === '') {
                continue;
            }
            $display = '';
            $title = '';
            $images = [];
            if ($kind === 'image' && is_array($value)) {
                foreach ($value as $file) {
                    if (! is_array($file)) {
                        continue;
                    }
                    $images[] = [
                        'path' => (string) ($file['path'] ?? ''),
                        'name' => (string) ($file['name'] ?? ''),
                        'bytes' => (int) ($file['bytes'] ?? 0),
                        'mime' => (string) ($file['mime'] ?? ''),
                    ];
                }
            } elseif (is_scalar($value)) {
                $title = (string) $value;
                $display = mb_strlen($title) > 80 ? mb_substr($title, 0, 80).'…' : $title;
            }

            $rows[] = [
                'slug' => (string) $slug,
                'label' => $label,
                'kind' => $kind,
                'value' => $value,
                'display' => $display,
                'title' => $title,
                'images' => $images,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $personalisation
     * @return array<string, mixed>
     */
    public static function relocateImages(array $personalisation, callable $relocatePath): array
    {
        foreach ($personalisation as $slug => $entry) {
            if (! is_array($entry) || ($entry['kind'] ?? null) !== 'image' || ! is_array($entry['value'] ?? null)) {
                continue;
            }
            foreach ($entry['value'] as $i => $file) {
                if (! is_array($file) || ! is_string($file['path'] ?? null)) {
                    continue;
                }
                $personalisation[$slug]['value'][$i]['path'] = $relocatePath($file['path']);
            }
        }

        return $personalisation;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function captureValue(array $definition, mixed $value): mixed
    {
        $kind = $definition['kind'];
        $slug = $definition['slug'];

        if ($kind === 'image') {
            if ($value === null || $value === [] || $value === '') {
                return null;
            }
            if (! is_array($value) || ! array_is_list($value)) {
                throw ValidationException::withMessages([
                    "personalisation.{$slug}" => ['Upload an image.'],
                ]);
            }
            $maxFiles = (int) ($definition['max_files'] ?? 1);
            if (count($value) > $maxFiles) {
                throw ValidationException::withMessages([
                    "personalisation.{$slug}" => ['Too many files.'],
                ]);
            }
            $files = [];
            foreach ($value as $file) {
                if (! is_array($file) || ! is_string($file['path'] ?? null) || $file['path'] === '') {
                    throw ValidationException::withMessages([
                        "personalisation.{$slug}" => ['Upload an image.'],
                    ]);
                }
                $files[] = [
                    'path' => $file['path'],
                    'name' => (string) ($file['name'] ?? 'image'),
                    'bytes' => (int) ($file['bytes'] ?? 0),
                    'mime' => (string) ($file['mime'] ?? 'image/jpeg'),
                ];
            }

            return $files;
        }

        if (! is_string($value)) {
            if ($value === null) {
                return null;
            }

            throw ValidationException::withMessages([
                "personalisation.{$slug}" => [self::requiredMessage($definition)],
            ]);
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $maxChars = (int) ($definition['max_chars'] ?? config('shop_input_presets.max_chars', 500));
        if (mb_strlen($trimmed) > $maxChars) {
            throw ValidationException::withMessages([
                "personalisation.{$slug}" => ['Must be at most '.$maxChars.' characters.'],
            ]);
        }

        if ($kind === 'choice') {
            $options = $definition['options'] ?? [];
            if (! in_array($trimmed, $options, true)) {
                throw ValidationException::withMessages([
                    "personalisation.{$slug}" => ['Choose one of the listed options.'],
                ]);
            }

            return $trimmed;
        }

        self::assertPattern($definition, $trimmed, $slug);

        return $trimmed;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function assertPattern(array $definition, string $value, string $slug): void
    {
        $name = $definition['pattern'] ?? null;
        if (! is_string($name) || $name === '') {
            return;
        }

        $patterns = CustomerInputDefinition::patterns();
        $pattern = $patterns[$name] ?? null;
        if (! is_array($pattern)) {
            throw ValidationException::withMessages([
                "personalisation.{$slug}" => ['That pattern is not available.'],
            ]);
        }

        if (isset($pattern['reject']) && is_string($pattern['reject']) && preg_match($pattern['reject'], $value) === 1) {
            throw ValidationException::withMessages([
                "personalisation.{$slug}" => ['Contains characters that are not allowed.'],
            ]);
        }

        if (isset($pattern['allow']) && is_string($pattern['allow']) && preg_match($pattern['allow'], $value) !== 1) {
            throw ValidationException::withMessages([
                "personalisation.{$slug}" => ['Contains characters that are not allowed.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function requiredMessage(array $definition): string
    {
        return ($definition['label'] ?? 'This field').' is required.';
    }

    /**
     * @param  array<string, mixed>  $personalisation
     */
    public static function canonicalJson(array $personalisation): string
    {
        return json_encode(self::ksortRecursive($personalisation), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $personalisation
     */
    private static function assertPayloadSize(array $personalisation): void
    {
        $max = (int) config('shop_input_presets.payload_max_bytes', 65536);
        $encoded = self::canonicalJson($personalisation);
        if (strlen($encoded) > $max) {
            throw ValidationException::withMessages([
                'personalisation' => ['Personalisation is too large.'],
            ]);
        }
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::ksortRecursive($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
