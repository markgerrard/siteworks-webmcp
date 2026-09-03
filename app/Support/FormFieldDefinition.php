<?php

namespace App\Support;

/**
 * The shared vocabulary for public form fields.
 *
 * Extracted so the three places that touch form fields — page-manager's
 * contact editor, lead-form-editor, and the front-end panel — cannot disagree
 * about types, caps or key format. They have disagreed before: lead-form
 * rejected the `email` type the contact renderer already emitted, and the two
 * capped fields differently.
 */
class FormFieldDefinition
{
    public const TYPES = ['text', 'tel', 'email', 'date', 'textarea', 'select', 'radio'];

    public const CHOICE_TYPES = ['select', 'radio'];

    /** Handled explicitly by SiteEnquirySubmitController; never client-owned. */
    public const RESERVED_KEYS = ['name', 'email', 'website', 'page_type'];

    public const MAX_FIELDS = 10;

    public const MAX_OPTIONS = 20;

    public const MAX_LABEL = 40;

    /**
     * How many operator-owned fields a form may carry.
     *
     * One number for every flavour. It used to be 5 for lead_form and 8 for
     * contact_form, and the difference kept drifting out of sync with the
     * places that enforce it — the renderer clamp, the admin lead-form editor,
     * and this endpoint — each of which failed differently: fields that saved
     * but never rendered, or a form the admin editor then refused to save.
     * Everything reads this now.
     */
    public static function capFor(string $sectionType): int
    {
        return self::MAX_FIELDS;
    }

    /**
     * Count the fields governed by the operator field cap.
     *
     * @param  array<int, array<string, mixed>>  $fields
     */
    public static function countableFieldTotal(string $sectionType, array $fields): int
    {
        if ($sectionType !== 'lead_form') {
            return count($fields);
        }

        return count(array_filter(
            $fields,
            fn (array $field): bool => ($field['name'] ?? null) !== 'message',
        ));
    }

    public static function normaliseKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9_]+/', '_', $key) ?? '';
        $key = trim($key, '_');

        if ($key !== '' && ! preg_match('/^[a-z]/', $key)) {
            $key = 'field_'.$key;
        }

        return mb_substr($key, 0, 60);
    }

    /**
     * @param  array<int, string>  $existingKeys
     */
    public static function deriveKey(string $label, array $existingKeys): string
    {
        $base = self::normaliseKey($label);

        if ($base === '') {
            $base = 'field';
        }

        if (! in_array($base, $existingKeys, true)) {
            return $base;
        }

        // Start at _2: the bare key IS the first one.
        for ($n = 2; $n < 100; $n++) {
            $candidate = $base.'_'.$n;
            if (! in_array($candidate, $existingKeys, true)) {
                return $candidate;
            }
        }

        return $base.'_'.uniqid();
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<int, string>  $existingKeys
     * @return array<string, mixed>
     */
    public static function normalise(array $field, array $existingKeys): array
    {
        $type = in_array($field['type'] ?? '', self::TYPES, true) ? $field['type'] : 'text';
        $label = mb_substr(trim((string) ($field['label'] ?? '')), 0, self::MAX_LABEL);

        $key = trim((string) ($field['name'] ?? ''));
        $key = self::deriveKey($key === '' ? $label : $key, $existingKeys);

        $out = [
            'name' => $key,
            'label' => $label,
            'type' => $type,
            'required' => (bool) ($field['required'] ?? false),
            'placeholder' => mb_substr(trim((string) ($field['placeholder'] ?? '')), 0, 100),
        ];

        if (in_array($type, self::CHOICE_TYPES, true)) {
            $options = array_values(array_filter(
                array_map('trim', (array) ($field['options'] ?? [])),
                fn ($o) => $o !== '',
            ));
            $out['options'] = array_slice($options, 0, self::MAX_OPTIONS);
        }

        return $out;
    }
}
