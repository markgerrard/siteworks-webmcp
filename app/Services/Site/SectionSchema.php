<?php

namespace App\Services\Site;

class SectionSchema
{
    /** @var array<string, list<string>> */
    private array $variantCache = [];

    public function __construct(protected array $schemas) {}

    public function isKnownSectionType(string $type): bool
    {
        return array_key_exists($type, $this->schemas);
    }

    /**
     * Return the schema for a given section type. Throws if unknown.
     */
    public function for(string $type): array
    {
        if (! $this->isKnownSectionType($type)) {
            throw new \InvalidArgumentException("Unknown section type: {$type}");
        }

        return $this->schemas[$type];
    }

    /**
     * Resolve a field path against a section type — returns its declared type/rules.
     * Path uses dot notation; '*' wildcards in schema keys match any index.
     *
     * Example: for 'services' section, fieldPath 'items.2.title' matches schema key 'items.*.title'.
     */
    public function resolveField(string $sectionType, string $fieldPath): ?array
    {
        if ($fieldPath === 'variant') {
            $values = $this->variantOptionsFor($sectionType);
            if ($values === []) {
                return null;
            }

            return [
                'type' => 'enum',
                'values' => $values,
                'nullable' => true,
            ];
        }

        $schema = $this->for($sectionType);
        $patterns = $schema['fields'] ?? [];

        foreach ($patterns as $pattern => $rules) {
            if ($this->matchesPattern($fieldPath, $pattern)) {
                return $rules;
            }
        }

        return null;
    }

    /**
     * Families whose variants are curated-only (recipes): no per-section
     * picker, and — because resolveField('variant') derives from this list —
     * no raw `sections.N.variant` write through PageService::editField either.
     * Spec: form-section-variant-pack §Dashboard & tooling (schema-level decision).
     */
    public const PICKER_EXCLUDED_FAMILIES = ['lead_form'];

    /**
     * Registered variant tokens for a section family.
     *
     * Empty when the family has no file-backed blades and no inline registry
     * entry — callers treat that as an unknown field.
     *
     * @return list<string>
     */
    public function variantOptionsFor(string $type): array
    {
        if (in_array($type, self::PICKER_EXCLUDED_FAMILIES, true)) {
            return $this->variantCache[$type] = [];
        }

        if (array_key_exists($type, $this->variantCache)) {
            return $this->variantCache[$type];
        }

        $fromFiles = [];
        if (in_array($type, PageLayoutRegistry::FILE_BACKED_FAMILIES, true)) {
            $files = glob(resource_path("views/site/sections/variants/{$type}/*.blade.php")) ?: [];
            $fromFiles = array_map(
                fn (string $path): string => basename($path, '.blade.php'),
                $files,
            );
        }

        $inline = PageLayoutRegistry::INLINE_VARIANT_FAMILIES[$type] ?? [];

        if ($fromFiles === [] && $inline === []) {
            return $this->variantCache[$type] = [];
        }

        $classic = in_array('classic', $fromFiles, true) ? ['classic'] : [];
        $candidates = array_values(array_unique(array_merge($classic, $fromFiles, $inline)));
        $registry = app(PageLayoutRegistry::class);

        return $this->variantCache[$type] = array_values(array_filter(
            $candidates,
            fn (string $variant): bool => ! $registry->isDeadPersistedVariant($type, $variant),
        ));
    }

    public function isValidField(string $sectionType, string $fieldPath): bool
    {
        return $this->resolveField($sectionType, $fieldPath) !== null;
    }

    /**
     * Return the declared fields for one repeatable entry list.
     *
     * @return array<string, array<string, mixed>>
     */
    public function repeatableFieldRules(string $sectionType, string $listPath): array
    {
        $fields = $this->for($sectionType)['fields'] ?? [];
        $prefix = $listPath.'.*.';
        $rules = [];

        foreach ($fields as $path => $fieldRules) {
            if (! str_starts_with($path, $prefix)) {
                continue;
            }

            $entryField = substr($path, strlen($prefix));
            if ($entryField === '' || str_contains($entryField, '.') || str_contains($entryField, '*')) {
                continue;
            }

            $rules[$entryField] = $fieldRules;
        }

        return $rules;
    }

    /** @return list<string> */
    public function repeatableLists(string $sectionType): array
    {
        $lists = [];

        foreach (array_keys($this->for($sectionType)['fields'] ?? []) as $path) {
            if (preg_match('/^([a-z][a-z0-9_]*)\.\*\.[a-z][a-z0-9_]*$/', $path, $matches) === 1) {
                $lists[] = $matches[1];
            }
        }

        return array_values(array_unique($lists));
    }

    /**
     * Validate a value for a given field path against the section schema.
     * Returns array of error messages; empty array means valid.
     */
    public function validateField(string $sectionType, string $fieldPath, mixed $value): array
    {
        $rules = $this->resolveField($sectionType, $fieldPath);
        if (! $rules) {
            return ["Unknown field: {$sectionType}.{$fieldPath}"];
        }

        $errors = [];

        switch ($rules['type']) {
            case 'plain':
                if (! is_string($value)) {
                    $errors[] = 'must be a string';
                    break;
                }
                if (isset($rules['max']) && mb_strlen($value) > $rules['max']) {
                    $errors[] = "must be ≤ {$rules['max']} characters";
                }
                break;

            case 'rich':
                if (! is_array($value) || ($value['type'] ?? null) !== 'doc') {
                    $errors[] = 'must be a TipTap doc structure';
                    break;
                }
                // DoS guard: reject oversized documents before any recursion.
                if (strlen(json_encode($value)) > 65536) {
                    $errors[] = 'rich-text document exceeds 64 KB limit';
                    break;
                }
                $richErrors = $this->validateRichTree($value);
                if (! empty($richErrors)) {
                    $errors = array_merge($errors, $richErrors);
                }
                break;

            case 'url':
                if (! is_string($value) || ! preg_match('#^https?://#', $value)) {
                    $errors[] = 'must be a valid http(s) URL';
                }
                break;

            case 'link':
                // Site-relative path ("/shop", "#contact") or absolute http(s). Never any other
                // scheme — the value lands in an href on the public site.
                if (! is_string($value) || ! self::isSafeLink($value)) {
                    $errors[] = 'must be a site-relative path (/path or #anchor), an https:// URL, or a tel: link';
                }
                break;

            case 'image':
                if (! is_int($value) && ! (is_string($value) && preg_match('#^https?://#', $value))) {
                    $errors[] = 'must be an integer media id or a valid http(s) image URL';
                }
                break;

            case 'enum':
                $values = $rules['values'] ?? [];
                $nullable = $rules['nullable'] ?? false;
                if (! in_array($value, $values, true) && ! ($value === null && $nullable)) {
                    $errors[] = 'must be a registered variant';
                }
                break;

            case 'product_block_source':
                if (! \App\Support\Shop\ProductBlockSource::isValid($value)) {
                    $errors[] = 'must be manual, featured, newest, tag:<slug>, or category:<slug>';
                }
                break;

            case 'integer':
                if (is_int($value)) {
                    $int = $value;
                } elseif (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
                    $int = (int) $value;
                } else {
                    $errors[] = 'must be an integer';
                    break;
                }
                if (isset($rules['min']) && $int < $rules['min']) {
                    $errors[] = "must be ≥ {$rules['min']}";
                }
                if (isset($rules['max']) && $int > $rules['max']) {
                    $errors[] = "must be ≤ {$rules['max']}";
                }
                break;

            case 'decimal':
                if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
                    $errors[] = 'must be a number';
                    break;
                }
                $decimal = (float) $value;
                if (! is_finite($decimal)) {
                    $errors[] = 'must be a finite number';
                    break;
                }
                if (isset($rules['min']) && $decimal < $rules['min']) {
                    $errors[] = "must be ≥ {$rules['min']}";
                }
                if (isset($rules['max']) && $decimal > $rules['max']) {
                    $errors[] = "must be ≤ {$rules['max']}";
                }
                if (isset($rules['precision']) && round($decimal, $rules['precision']) !== $decimal) {
                    $errors[] = "must have at most {$rules['precision']} decimal place(s)";
                }
                break;

            case 'ranges':
                $errors = array_merge($errors, $this->validateRangesShape($value));
                break;

            case 'array':
                if (! is_array($value) || ! array_is_list($value)) {
                    $errors[] = 'must be a list';
                    break;
                }
                foreach ($value as $item) {
                    if (! is_string($item)) {
                        $errors[] = 'must be a list of strings';
                        break;
                    }
                }
                break;

            default:
                $errors[] = "Unknown field type: {$rules['type']}";
        }

        return $errors;
    }

    /**
     * Validate range shape plus bounds against a title (codepoint length).
     *
     * @return list<string>
     */
    public function validateRangesAgainstTitle(mixed $value, string $title): array
    {
        $errors = $this->validateRangesShape($value);
        if ($errors !== []) {
            return $errors;
        }

        $length = mb_strlen($title);
        foreach ($value as $i => $range) {
            if (($range['start'] + $range['length']) > $length) {
                $errors[] = "range {$i} exceeds title length";
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validateRangesShape(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return ['must be a list of {start, length} ranges'];
        }

        $errors = [];
        $cursor = 0;

        foreach ($value as $i => $range) {
            if (! is_array($range)) {
                $errors[] = "range {$i} must be an object";

                continue;
            }

            $start = $range['start'] ?? null;
            $length = $range['length'] ?? null;
            if (! is_int($start) || $start < 0) {
                $errors[] = "range {$i} start must be a non-negative integer";

                continue;
            }
            if (! is_int($length) || $length < 1) {
                $errors[] = "range {$i} length must be a positive integer";

                continue;
            }
            if ($start < $cursor) {
                $errors[] = "range {$i} must be ascending and non-overlapping";

                continue;
            }

            $cursor = $start + $length;
        }

        return $errors;
    }

    /**
     * Iterate over all editable field paths for a given section's actual data,
     * yielding [fieldPath, type, value]. Used by the renderer to wrap markers.
     */
    public function eachEditableField(string $sectionType, array $sectionData): \Generator
    {
        $schema = $this->for($sectionType);
        $patterns = $schema['fields'] ?? [];

        foreach ($patterns as $pattern => $rules) {
            foreach ($this->expandPattern($pattern, $sectionData) as $expandedPath => $value) {
                yield [$expandedPath, $rules['type'], $value];
            }
        }
    }

    /**
     * Allowed TipTap node + mark types for rich-text fields.
     * Restricts the accepted subset to block + inline primitives we actually render;
     * anything else (scripts, embeds, images, custom nodes) is rejected server-side.
     */
    protected const ALLOWED_RICH_NODES = [
        'doc', 'paragraph', 'heading', 'bulletList', 'orderedList', 'listItem',
        'blockquote', 'text', 'hardBreak',
    ];

    protected const ALLOWED_RICH_MARKS = [
        'bold', 'italic', 'strike', 'link',
    ];

    /**
     * Recursively validate a TipTap JSON tree against the allowed node/mark subset.
     * Returns array of error messages; empty means valid.
     */
    protected function validateRichTree(array $node, string $path = 'doc', int $depth = 0): array
    {
        $errors = [];

        // DoS guard: cap recursion depth to prevent stack exhaustion on deeply
        // nested trees (e.g. 100k-deep nested blockquotes).
        if ($depth > 32) {
            $errors[] = "rich-text document exceeds maximum nesting depth of 32 at {$path}";

            return $errors;
        }

        $type = $node['type'] ?? null;
        if (! is_string($type) || ! in_array($type, self::ALLOWED_RICH_NODES, true)) {
            $errors[] = "disallowed node type at {$path}: ".var_export($type, true);

            return $errors;
        }

        // DoS guard: cap individual text node length.
        if ($type === 'text') {
            $text = $node['text'] ?? '';
            if (is_string($text) && mb_strlen($text) > 20000) {
                $errors[] = "text node at {$path} exceeds 20 000 character limit";

                return $errors;
            }
        }

        if (isset($node['marks']) && is_array($node['marks'])) {
            foreach ($node['marks'] as $i => $mark) {
                $markType = is_array($mark) ? ($mark['type'] ?? null) : null;
                if (! is_string($markType) || ! in_array($markType, self::ALLOWED_RICH_MARKS, true)) {
                    $errors[] = "disallowed mark type at {$path}.marks.{$i}: ".var_export($markType, true);

                    continue;
                }

                // Validate link mark attrs to prevent TypeError in RichTextRenderer::wrapLink.
                // href must be a string and must be an http(s) URL.
                if ($markType === 'link') {
                    $href = is_array($mark) ? ($mark['attrs']['href'] ?? null) : null;
                    if (! is_string($href) || ! preg_match('#^https?://#i', $href)) {
                        $errors[] = "link mark at {$path}.marks.{$i} must have a valid http(s) href string";
                    }
                }
            }
        }

        if (isset($node['content']) && is_array($node['content'])) {
            foreach ($node['content'] as $i => $child) {
                if (! is_array($child)) {
                    $errors[] = "non-array child at {$path}.content.{$i}";

                    continue;
                }
                $errors = array_merge($errors, $this->validateRichTree($child, "{$path}.content.{$i}", $depth + 1));
            }
        }

        return $errors;
    }

    protected function matchesPattern(string $fieldPath, string $pattern): bool
    {
        $regex = '/^'.str_replace('\*', '\d+', preg_quote($pattern, '/')).'$/';

        return (bool) preg_match($regex, $fieldPath);
    }

    /**
     * Given pattern 'items.*.title' and section data with 3 items, yield 3 concrete paths.
     */
    protected function expandPattern(string $pattern, array $sectionData): \Generator
    {
        if (! str_contains($pattern, '*')) {
            $value = data_get($sectionData, $pattern);
            if ($value !== null) {
                yield $pattern => $value;
            }

            return;
        }

        // Single wildcard support; nested '*'s are not used in the MVP schema
        [$prefix, $suffix] = explode('.*.', $pattern, 2);
        $collection = data_get($sectionData, $prefix);
        if (! is_array($collection)) {
            return;
        }
        foreach ($collection as $i => $_) {
            $expanded = "{$prefix}.{$i}.{$suffix}";
            $value = data_get($sectionData, $expanded);
            if ($value !== null) {
                yield $expanded => $value;
            }
        }
    }

    /**
     * A link the public renderer may place in an href. Delegates to the nav CTA rule
     * (root-relative path — never "//host" or backslash forms — https with a real host,
     * or tel:) and additionally allows a same-page "#anchor". One rule for every href.
     */
    public static function isSafeLink(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        if (\App\Support\NavCta::safeUrl($value) !== null) {
            return true;
        }

        return preg_match('/^#[A-Za-z0-9_-]*$/', $value) === 1;
    }
}
