<?php

namespace App\Support;

use App\Models\GeneratedPage;

/**
 * The key => label map for a page's form fields.
 *
 * Snapshotted onto each enquiry at submit time so historical answers stay
 * readable after the form is edited. The form definition is therefore only
 * consulted once, when the answer arrives — never when it is displayed.
 */
class EnquiryFieldLabels
{
    /** @return array<string, string> */
    public static function forPage(?GeneratedPage $page): array
    {
        if (! $page) {
            return [];
        }

        // Draft first: an enquiry submitted against an unpublished edit should
        // be labelled by the form the visitor actually saw.
        $content = $page->draftRevision?->content_data
            ?? $page->publishedRevision?->content_data
            ?? $page->content_data
            ?? [];

        $labels = [];

        foreach (($content['sections'] ?? []) as $section) {
            if (! is_array($section)) {
                continue;
            }
            foreach (['fields', 'extra_fields'] as $key) {
                foreach (($section[$key] ?? []) as $field) {
                    if (! is_array($field)) {
                        continue;
                    }
                    $name = trim((string) ($field['name'] ?? ''));
                    $label = trim((string) ($field['label'] ?? ''));
                    if ($name !== '' && $label !== '') {
                        $labels[$name] = $label;
                    }
                }
            }
        }

        return $labels;
    }

    public static function humanise(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }
}
