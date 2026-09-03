<?php

namespace App\Services\Site\Editor;

use App\Models\BeforeAfterPair;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Models\SiteMedia;
use App\Services\Site\SectionSchema;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class SectionCatalog
{
    /** @var array<string, array<string, mixed>> */
    private array $catalog;

    /**
     * @param  array<string, array<string, mixed>>|null  $catalog
     */
    public function __construct(private readonly SectionSchema $schema, ?array $catalog = null)
    {
        $this->catalog = $catalog ?? config('section_catalog', []);
    }

    public function allowedOn(string $type, string $pageType): bool
    {
        if (! isset($this->catalog[$type]) || $this->isInjectedOnly($type)) {
            return false;
        }

        $pageTypes = $this->catalog[$type]['page_types'] ?? [];

        return in_array('*', $pageTypes, true) || in_array($pageType, $pageTypes, true);
    }

    public function isSingleton(string $type): bool
    {
        return ($this->catalog[$type]['singleton'] ?? false) === true;
    }

    public function maxPerPage(string $type): ?int
    {
        $maximum = $this->catalog[$type]['max'] ?? null;

        return is_int($maximum) ? $maximum : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultPayload(string $type, Site $site): array
    {
        $definition = $this->definition($type);

        if (($definition['injected_only'] ?? false) === true) {
            throw new InvalidArgumentException("Section type is injected only: {$type}");
        }

        $defaults = $definition['defaults'] ?? [];

        foreach ($this->schema->repeatableLists($type) as $listPath) {
            $defaults[$listPath] ??= [];
        }

        foreach ($this->referenceFields($type) as $fieldPath => $referenceType) {
            if (in_array($fieldPath, ['item_ids', 'pair_ids'], true)) {
                $defaults[$fieldPath] ??= [];
            }
        }

        return ['type' => $type, 'variant' => null, ...$defaults];
    }

    /** @return list<string> */
    public function initialFields(string $type): array
    {
        if ($this->isInjectedOnly($type)) {
            return [];
        }

        return array_values($this->catalog[$type]['initial_fields'] ?? []);
    }

    /** @return array<string, string> */
    public function referenceFields(string $type): array
    {
        return $this->catalog[$type]['references'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $section
     * @return list<string>
     */
    public function validateReferences(Site $site, array $section): array
    {
        $type = $section['type'] ?? null;

        if (! is_string($type) || ! isset($this->catalog[$type])) {
            return ['Unknown section type.'];
        }

        $errors = [];

        foreach ($this->referenceFields($type) as $fieldPath => $referenceType) {
            $values = $this->referenceValues($section, explode('.', $fieldPath));
            $ids = [];

            foreach ($values as $value) {
                // Mirror the renderer's tolerance (PageRenderer::extractReferencedMediaIds): null / '' mean
                // "unset" and are skipped; numeric strings are ids; image *_id fields may also legitimately
                // hold a URL string (SectionSchema image rule) — only site-scope real ids.
                if ($value === null || $value === '') {
                    continue;
                }
                if (is_string($value) && ! ctype_digit($value)) {
                    if ($referenceType === 'site_media' && preg_match('~^(https?:)?/~i', $value) === 1) {
                        continue; // URL-valued image field (absolute or root-relative): not a site_media reference
                    }
                    $errors[] = "{$fieldPath} must contain positive integer ids.";

                    continue;
                }
                if ((! is_int($value) && ! is_string($value)) || (int) $value < 1) {
                    $errors[] = "{$fieldPath} must contain positive integer ids.";

                    continue;
                }

                $ids[] = (int) $value;
            }

            if ($ids === []) {
                continue;
            }

            $modelClass = $this->modelClassForReference($referenceType);
            $matchingIds = $modelClass::query()
                ->where('site_id', $site->getKey())
                ->whereKey(array_values(array_unique($ids)))
                ->pluck((new $modelClass)->getKeyName())
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            foreach (array_diff(array_unique($ids), $matchingIds) as $invalidId) {
                $errors[] = "{$fieldPath} contains an id outside this site: {$invalidId}.";
            }
        }

        return $errors;
    }

    public function isInjectedOnly(string $type): bool
    {
        return ($this->catalog[$type]['injected_only'] ?? false) === true;
    }

    /** @return array<string, mixed> */
    private function definition(string $type): array
    {
        if (! isset($this->catalog[$type])) {
            throw new InvalidArgumentException("Unknown section type: {$type}");
        }

        return $this->catalog[$type];
    }

    /**
     * @param  list<string>  $segments
     * @return list<mixed>
     */
    private function referenceValues(mixed $value, array $segments): array
    {
        if ($segments === []) {
            return is_array($value) && array_is_list($value) ? $value : [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $segment = array_shift($segments);

        if ($segment === '*') {
            $values = [];

            foreach ($value as $entry) {
                array_push($values, ...$this->referenceValues($entry, $segments));
            }

            return $values;
        }

        if (! array_key_exists($segment, $value)) {
            return [];
        }

        return $this->referenceValues($value[$segment], $segments);
    }

    /** @return class-string<Model> */
    private function modelClassForReference(string $referenceType): string
    {
        return match ($referenceType) {
            'site_media' => SiteMedia::class,
            'project_items' => ProjectItem::class,
            'before_after_pairs' => BeforeAfterPair::class,
            default => throw new InvalidArgumentException("Unknown reference type: {$referenceType}"),
        };
    }
}
