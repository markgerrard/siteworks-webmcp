<?php

namespace App\Services\Site;

use App\Models\SiteMedia;
use Illuminate\Validation\ValidationException;

class RepeatableSectionEntries
{
    public function __construct(private readonly SectionSchema $schema) {}

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    public function validated(string $sectionType, string $listPath, array $entries, int $siteId): array
    {
        if (count($entries) > 100) {
            throw ValidationException::withMessages([
                'entries' => 'The entry list may not contain more than 100 items.',
            ]);
        }

        $fieldRules = $this->schema->repeatableFieldRules($sectionType, $listPath);
        if ($fieldRules === []) {
            throw ValidationException::withMessages([
                'list_path' => "Unknown repeatable list: {$sectionType}.{$listPath}",
            ]);
        }

        $errors = [];

        $mediaIds = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            foreach ($entry as $field => $value) {
                if (is_string($field)
                    && is_int($value)
                    && ($fieldRules[$field]['type'] ?? null) === 'image'
                    && str_ends_with($field, '_id')) {
                    $mediaIds[] = $value;
                }
            }
        }
        $ownedMediaIds = SiteMedia::query()
            ->where('site_id', $siteId)
            ->whereIn('id', array_values(array_unique($mediaIds)))
            ->pluck('id')
            ->mapWithKeys(fn (int $id): array => [$id => true])
            ->all();

        foreach ($entries as $entryIndex => $entry) {
            if (! is_array($entry)) {
                $errors["entries.{$entryIndex}"][] = 'must be an array';

                continue;
            }

            foreach ($entry as $field => $value) {
                $errorKey = "entries.{$entryIndex}.{$field}";
                if (! is_string($field)) {
                    $errors[$errorKey][] = 'field names must be strings';

                    continue;
                }
                $rules = $fieldRules[$field] ?? null;

                if ($rules === null) {
                    $errors[$errorKey][] = "Unknown field: {$sectionType}.{$listPath}.*.{$field}";

                    continue;
                }

                $isMediaId = ($rules['type'] ?? null) === 'image' && str_ends_with($field, '_id');
                if ($isMediaId) {
                    if ($value === null) {
                        continue;
                    }

                    if (! is_int($value)) {
                        $errors[$errorKey][] = 'must be an integer media id';

                        continue;
                    }

                    if (! isset($ownedMediaIds[$value])) {
                        $errors[$errorKey][] = 'must reference media belonging to this site';
                    }

                    continue;
                }

                foreach ($this->schema->validateField($sectionType, "{$listPath}.{$entryIndex}.{$field}", $value) as $message) {
                    $errors[$errorKey][] = $message;
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return array_values($entries);
    }
}
