<?php

namespace App\Services\Site;

use Illuminate\Support\Facades\Log;

/**
 * Translates legacy `content_data` (map shape produced by the pre-versioned
 * PreviewRenderer) to the new `sections` array shape expected by
 * {@see PageRenderer}.
 *
 * Legacy shape:
 *   ['hero' => [...], 'services' => [...], ..., 'seo' => [...], 'geo' => [...]]
 *
 * New shape:
 *   ['sections' => [['type' => 'hero', ...], ...], 'meta' => ['seo' => [...], 'geo' => [...]]]
 */
class ContentShapeTranslator
{
    /**
     * Preserved from the legacy PreviewRenderer::orderSections ordering.
     * `seo` and `geo` are intentionally absent — they become meta, not sections.
     *
     * @var list<string>
     */
    protected const SECTION_ORDER = [
        'hero', 'services', 'trust', 'story', 'values', 'details',
        'contact_form', 'intro', 'features', 'process', 'faqs', 'benefits', 'cta',
        'lead_form', 'service_area_card', 'cta_band',
    ];

    public function __construct(protected SectionSchema $schema) {}

    /**
     * Translate a legacy content_data array into the new shape.
     *
     * Idempotent: if the input already has a `sections` key, it is returned
     * unchanged.
     */
    public function translate(array $legacy): array
    {
        if (isset($legacy['sections'])) {
            return $legacy;
        }

        $sections = [];

        foreach (self::SECTION_ORDER as $type) {
            if (! array_key_exists($type, $legacy)) {
                continue;
            }

            $raw = $legacy[$type];
            if (! is_array($raw)) {
                continue;
            }

            $sections[] = ['type' => $type] + $this->translateSection($type, $raw);
        }

        $out = ['sections' => $sections];

        $meta = [];
        foreach (['seo', 'geo'] as $metaKey) {
            if (isset($legacy[$metaKey]) && is_array($legacy[$metaKey])) {
                $meta[$metaKey] = $legacy[$metaKey];
            }
        }

        // service_display_name is a top-level scalar on the AI flat shape. It
        // is not a renderable section but is read by PageRenderer::serviceDisplayName()
        // to personalise the injected lead_form title on service pages. Preserve
        // it under meta so it survives the translate() call.
        if (isset($legacy['service_display_name']) && is_string($legacy['service_display_name'])) {
            $meta['service_display_name'] = $legacy['service_display_name'];
        }

        if (! empty($meta)) {
            $out['meta'] = $meta;
        }

        // Warn on any unknown legacy keys we couldn't place.
        $known = array_merge(self::SECTION_ORDER, ['seo', 'geo', 'service_display_name']);
        foreach (array_keys($legacy) as $key) {
            if (! in_array($key, $known, true)) {
                Log::warning('ContentShapeTranslator: dropping unknown legacy section', [
                    'key' => $key,
                ]);
            }
        }

        return $out;
    }

    /**
     * Translate a single legacy section's inner array.
     *
     * Applies global renames (heading → title, subheading → subtitle),
     * per-type special handling, and wraps string values for schema-declared
     * rich-text fields as minimal TipTap docs.
     */
    protected function translateSection(string $type, array $data): array
    {
        // Special per-type handling first (these need to know about the original
        // legacy field names before the generic rename pass).
        switch ($type) {
            case 'cta':
                $out = [];
                if (isset($data['heading'])) {
                    $out['title'] = $data['heading'];
                }
                if (isset($data['subheading'])) {
                    $out['body'] = $data['subheading'];
                }
                if (isset($data['cta_label'])) {
                    $out['button_label'] = $data['cta_label'];
                }
                // accent_word is a string paired with the title;
                // pass through so the renderer can wrap the first match.
                if (isset($data['accent_word']) && is_string($data['accent_word'])) {
                    $out['accent_word'] = $data['accent_word'];
                }
                // Legacy `cta_url` has no target field in the new schema
                // (new uses `button_url` but legacy never populated it).
                return $out;

            case 'details':
                $out = [];
                if (isset($data['heading'])) {
                    $out['title'] = $data['heading'];
                }
                $items = [];
                $fieldLabels = [
                    'email' => 'Email',
                    'phone' => 'Phone',
                    'address' => 'Address',
                    'coverage' => 'Coverage',
                ];
                foreach ($fieldLabels as $field => $label) {
                    if (! empty($data[$field])) {
                        $items[] = ['label' => $label, 'value' => $data[$field]];
                    }
                }
                if (! empty($items)) {
                    $out['items'] = $items;
                }
                return $out;

            case 'intro':
                $out = [];
                if (isset($data['heading'])) {
                    $out['title'] = $data['heading'];
                }
                if (isset($data['body'])) {
                    $out['body'] = $this->wrapRich($data['body']);
                }
                // `eyebrow` has no target field — drop it.
                return $out;

            case 'contact_form':
                $out = [];
                if (isset($data['heading'])) {
                    $out['title'] = $data['heading'];
                }
                if (isset($data['submit_label'])) {
                    $out['submit_label'] = $data['submit_label'];
                }
                // `privacy_note` and `fields` have no target field — drop them.
                // Do NOT synthesize an `intro` field; legacy doesn't have one.
                return $out;

            case 'lead_form':
                // Pass all known lead_form fields through unchanged. The
                // translator must NOT strip benefits (string array) or
                // extra_fields (nested array of field descriptors) — these
                // are new keys that the generic pass would leave as-is, but
                // the explicit case makes the intention explicit and prevents
                // future generic changes from accidentally dropping them.
                $out = [];
                if (isset($data['heading'])) {
                    $out['title'] = $data['heading'];
                } elseif (isset($data['title'])) {
                    $out['title'] = $data['title'];
                }
                if (isset($data['intro'])) {
                    $out['intro'] = is_string($data['intro']) ? $data['intro'] : $this->wrapRich($data['intro']);
                }
                if (isset($data['submit_label'])) {
                    $out['submit_label'] = $data['submit_label'];
                }
                if (isset($data['benefits']) && is_array($data['benefits'])) {
                    $out['benefits'] = array_values(array_filter($data['benefits'], 'is_string'));
                }
                if (isset($data['extra_fields']) && is_array($data['extra_fields'])) {
                    $out['extra_fields'] = array_values(array_filter($data['extra_fields'], 'is_array'));
                }
                return $out;

            case 'service_area_card':
                // Pass all fields through untouched. The areas array is a flat
                // string array; title/intro/cta_label/cta_url are plain strings.
                $out = [];
                foreach (['title', 'intro', 'cta_label', 'cta_url'] as $field) {
                    if (isset($data[$field]) && is_string($data[$field])) {
                        $out[$field] = $data[$field];
                    }
                }
                if (isset($data['areas']) && is_array($data['areas'])) {
                    $out['areas'] = array_values(array_filter($data['areas'], 'is_string'));
                }
                return $out;

            case 'cta_band':
                // Pass all fields through untouched.
                $out = [];
                foreach (['title', 'subtitle', 'cta_label', 'cta_url'] as $field) {
                    if (isset($data[$field]) && is_string($data[$field])) {
                        $out[$field] = $data[$field];
                    }
                }
                return $out;
        }

        // Generic path for all other section types.
        $out = [];
        foreach ($data as $key => $value) {
            $newKey = match ($key) {
                'heading' => 'title',
                'subheading' => 'subtitle',
                default => $key,
            };

            if ($key === 'items' && is_array($value)) {
                $out[$newKey] = $this->translateItems($type, $value);

                continue;
            }

            if ($this->isRichField($type, $newKey) && ! is_array($value) && is_string($value)) {
                $out[$newKey] = $this->stringToTipTapDoc($value);

                continue;
            }

            $out[$newKey] = $value;
        }

        return $out;
    }

    /**
     * Translate the `items` array of a section, wrapping rich fields per schema.
     */
    protected function translateItems(string $type, array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                $out[] = $item;

                continue;
            }
            $new = [];
            foreach ($item as $key => $value) {
                $newKey = match ($key) {
                    'heading' => 'title',
                    'subheading' => 'subtitle',
                    default => $key,
                };
                if ($this->isRichItemField($type, $newKey) && ! is_array($value) && is_string($value)) {
                    $new[$newKey] = $this->stringToTipTapDoc($value);

                    continue;
                }
                $new[$newKey] = $value;
            }
            $out[] = $new;
        }

        return $out;
    }

    /**
     * True iff the top-level section field is declared as type => 'rich'
     * in config/site_sections.php.
     */
    protected function isRichField(string $type, string $field): bool
    {
        if (! $this->schema->isKnownSectionType($type)) {
            return false;
        }
        $rules = $this->schema->resolveField($type, $field);

        return ($rules['type'] ?? null) === 'rich';
    }

    /**
     * True iff the repeating-item field (items.*.{field}) is declared
     * as type => 'rich' in config/site_sections.php.
     */
    protected function isRichItemField(string $type, string $field): bool
    {
        if (! $this->schema->isKnownSectionType($type)) {
            return false;
        }
        $rules = $this->schema->resolveField($type, "items.0.{$field}");

        return ($rules['type'] ?? null) === 'rich';
    }

    /**
     * Wrap a plain string as a minimal TipTap doc. If already a TipTap-shaped
     * array, leave untouched.
     */
    protected function wrapRich(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            return $this->stringToTipTapDoc($value);
        }

        return $value;
    }

    /**
     * Build a TipTap document from a legacy plain-text string. Splits on blank
     * lines (one or more \n\n in any combination) into separate paragraphs;
     * single newlines within a paragraph are preserved as hardBreak nodes so
     * soft line breaks don't merge into flow text.
     */
    public function stringToTipTapDoc(string $s): array
    {
        $normalised = preg_replace("/\r\n?/", "\n", $s);
        $chunks = preg_split("/\n{2,}/", $normalised);

        $paragraphs = [];
        foreach ($chunks as $chunk) {
            $chunk = trim($chunk, "\n");
            if ($chunk === '') {
                continue;
            }

            // Turn remaining single \n inside a chunk into hardBreak nodes.
            // Each non-empty line is further parsed for **bold** markdown
            // segments so the intro-body prompt (which asks for
            // audience terms like **homeowners**) surfaces as <strong>
            // tags on the public page — pure plain text wouldn't scan as
            // well on long service pages.
            $lines = explode("\n", $chunk);
            $inline = [];
            foreach ($lines as $i => $line) {
                if ($line !== '') {
                    foreach ($this->splitBoldSegments($line) as $segment) {
                        $inline[] = $segment;
                    }
                }
                if ($i < count($lines) - 1) {
                    $inline[] = ['type' => 'hardBreak'];
                }
            }

            $paragraphs[] = ['type' => 'paragraph', 'content' => $inline];
        }

        if (empty($paragraphs)) {
            $paragraphs[] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '']]];
        }

        return ['type' => 'doc', 'content' => $paragraphs];
    }

    /**
     * Split a single line of plain text into a list of TipTap inline
     * nodes, emitting `{type: text, marks: [{type: bold}]}` for any
     * portion wrapped in **double-asterisks**. Non-bold portions are
     * plain text nodes. Nested / unclosed markers fall through as
     * literal asterisks so partial markdown doesn't swallow content.
     *
     * Narrow by design — handles **bold** only. Italic, links, lists
     * etc. are out of scope; if the content pipeline ever needs those,
     * promote the generated output to TipTap JSON directly rather than
     * expanding this translator.
     *
     * @return array<int, array<string, mixed>>
     */
    private function splitBoldSegments(string $line): array
    {
        $segments = [];
        $parts = preg_split('/\*\*(.+?)\*\*/', $line, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return [['type' => 'text', 'text' => $line]];
        }

        foreach ($parts as $i => $part) {
            if ($part === '') {
                continue;
            }
            // Even indices are plain text between bold segments; odd are
            // the captured bold inner content (without the ** delimiters).
            if ($i % 2 === 0) {
                $segments[] = ['type' => 'text', 'text' => $part];
            } else {
                $segments[] = [
                    'type' => 'text',
                    'text' => $part,
                    'marks' => [['type' => 'bold']],
                ];
            }
        }

        return $segments === [] ? [['type' => 'text', 'text' => $line]] : $segments;
    }
}
