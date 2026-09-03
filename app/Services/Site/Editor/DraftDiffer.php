<?php

namespace App\Services\Site\Editor;

use Illuminate\Support\Arr;

final class DraftDiffer
{
    public const VALUE_LIMIT_BYTES = 512;

    private const BASE64_MIN_CHARS = 64;   // 64 base64 chars = 48 decoded bytes

    /**
     * @var list<string>
     */
    private const SELECTION_IGNORED_KEYS = [
        'id',
        'site_id',
        'created_at',
        'updated_at',
        'created_by_user_id',
        'family',
        'page_type',
        'slot',
    ];

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array{scope: 'page', page_id: ?int, stored_index: ?int, section_id: ?string, field_path: ?string, path: string, before: mixed, after: mixed, kind: 'set'|'unset'|'insert'|'remove'|'move', truncated: bool}>
     */
    public function diffContent(array $before, array $after, ?int $pageId): array
    {
        $entries = [];

        $this->diffSections(
            $this->sections($before),
            $this->sections($after),
            $pageId,
            $entries,
        );

        $beforeRest = $before;
        $afterRest = $after;
        unset($beforeRest['sections'], $afterRest['sections']);
        $this->diffTree($beforeRest, $afterRest, 'page', $pageId, null, null, '', $entries);

        return $this->sorted($entries);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array{scope: 'site', page_id: null, stored_index: null, section_id: null, field_path: null, path: string, before: mixed, after: mixed, kind: 'set'|'unset'|'insert'|'remove'|'move', truncated: bool}>
     */
    public function diffComposition(array $before, array $after): array
    {
        $entries = [];
        $this->diffTree($before, $after, 'site', null, null, null, 'composition', $entries);

        return $this->sorted($entries);
    }

    /**
     * @param  array<int|string, mixed>  $before
     * @param  array<int|string, mixed>  $after
     * @return list<array{scope: 'site', page_id: null, stored_index: null, section_id: null, field_path: null, path: string, before: mixed, after: mixed, kind: 'set'|'unset'|'insert'|'remove'|'move', truncated: bool}>
     */
    public function diffSelections(array $before, array $after): array
    {
        $entries = [];
        $beforeMap = $this->selectionMap($before);
        $afterMap = $this->selectionMap($after);
        $keys = array_unique([...array_keys($beforeMap), ...array_keys($afterMap)]);
        sort($keys, SORT_STRING);

        foreach ($keys as $key) {
            $had = array_key_exists($key, $beforeMap);
            $has = array_key_exists($key, $afterMap);
            $prefix = 'asset_selection.'.$key;
            $beforePayload = $had ? $beforeMap[$key] : [];
            $afterPayload = $has ? $afterMap[$key] : [];
            $fields = array_unique([...array_keys($beforePayload), ...array_keys($afterPayload)]);
            sort($fields, SORT_STRING);

            foreach ($fields as $field) {
                $inBefore = array_key_exists($field, $beforePayload);
                $inAfter = array_key_exists($field, $afterPayload);
                if ($inBefore && $inAfter && $beforePayload[$field] === $afterPayload[$field]) {
                    continue;
                }

                $kind = match (true) {
                    ! $had && $has => 'insert',
                    $had && ! $has => 'remove',
                    ! $inBefore && $inAfter => 'set',
                    $inBefore && ! $inAfter => 'unset',
                    default => 'set',
                };

                $this->emit(
                    'site',
                    null,
                    null,
                    null,
                    $prefix.'.'.$field,
                    $inBefore ? $beforePayload[$field] : null,
                    $inAfter ? $afterPayload[$field] : null,
                    $kind,
                    $entries,
                );
            }
        }

        return $this->sorted($entries);
    }

    /**
     * @param  array<int, mixed>  $before
     * @param  array<int, mixed>  $after
     * @param  list<array<string, mixed>>  $entries
     */
    private function diffSections(array $before, array $after, ?int $pageId, array &$entries): void
    {
        $pairedBefore = [];
        $pairedAfter = [];
        $afterById = [];

        foreach ($after as $index => $section) {
            $id = $this->sectionId($section);
            if ($id !== null && ! array_key_exists($id, $afterById)) {
                $afterById[$id] = $index;
            }
        }

        foreach ($before as $from => $section) {
            $id = $this->sectionId($section);
            if ($id === null || ! array_key_exists($id, $afterById)) {
                continue;
            }

            $to = $afterById[$id];
            $this->diffPairedSection($before, $after, $from, $to, $pageId, $entries);
            $pairedBefore[$from] = true;
            $pairedAfter[$to] = true;
        }

        $beforeByType = $this->indexesByType($before);
        $afterByType = $this->indexesByType($after);
        $types = array_unique([...array_keys($beforeByType), ...array_keys($afterByType)]);

        foreach ($types as $type) {
            $beforeIndexes = array_values(array_filter(
                $beforeByType[$type] ?? [],
                fn (int $index): bool => ! isset($pairedBefore[$index]),
            ));
            $afterIndexes = array_values(array_filter(
                $afterByType[$type] ?? [],
                fn (int $index): bool => ! isset($pairedAfter[$index]),
            ));
            $matched = min(count($beforeIndexes), count($afterIndexes));

            for ($i = 0; $i < $matched; $i++) {
                $from = $beforeIndexes[$i];
                $to = $afterIndexes[$i];
                $beforeId = $this->sectionId($before[$from] ?? null);
                $afterId = $this->sectionId($after[$to] ?? null);

                if ($beforeId !== null && $afterId !== null) {
                    $this->emit('page', $pageId, $from, $beforeId, 'sections.'.$from, $before[$from], null, 'remove', $entries);
                    $this->emit('page', $pageId, $to, $afterId, 'sections.'.$to, null, $after[$to], 'insert', $entries);

                    continue;
                }

                $this->diffPairedSection($before, $after, $from, $to, $pageId, $entries);
            }

            for ($i = $matched; $i < count($beforeIndexes); $i++) {
                $from = $beforeIndexes[$i];
                $this->emit('page', $pageId, $from, $this->sectionId($before[$from] ?? null), 'sections.'.$from, $before[$from], null, 'remove', $entries);
            }

            for ($i = $matched; $i < count($afterIndexes); $i++) {
                $to = $afterIndexes[$i];
                $this->emit('page', $pageId, $to, $this->sectionId($after[$to] ?? null), 'sections.'.$to, null, $after[$to], 'insert', $entries);
            }
        }
    }

    /**
     * @param  array<int, mixed>  $before
     * @param  array<int, mixed>  $after
     * @param  list<array<string, mixed>>  $entries
     */
    private function diffPairedSection(array $before, array $after, int $from, int $to, ?int $pageId, array &$entries): void
    {
        $sectionId = $this->sectionId($after[$to] ?? null) ?? $this->sectionId($before[$from] ?? null);

        if ($from !== $to) {
            $entries[] = $this->entry(
                'page',
                $pageId,
                $to,
                $sectionId,
                null,
                'sections.'.$to,
                $from,
                $to,
                'move',
                false,
            );
        }

        $beforeSection = is_array($before[$from]) ? $before[$from] : [];
        $afterSection = is_array($after[$to]) ? $after[$to] : [];
        unset($beforeSection['type'], $afterSection['type'], $beforeSection['id'], $afterSection['id']);
        $this->diffTree($beforeSection, $afterSection, 'page', $pageId, $to, $sectionId, 'sections.'.$to, $entries);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function diffTree(
        mixed $before,
        mixed $after,
        string $scope,
        ?int $pageId,
        ?int $storedIndex,
        ?string $sectionId,
        string $path,
        array &$entries,
    ): void {
        if ($before === $after) {
            return;
        }

        if (is_array($before) && is_array($after) && $this->treatAsList($before, $after)) {
            $length = max(count($before), count($after));
            for ($i = 0; $i < $length; $i++) {
                $child = $this->join($path, (string) $i);
                $hasBefore = array_key_exists($i, $before);
                $hasAfter = array_key_exists($i, $after);

                if (! $hasBefore) {
                    $this->emit($scope, $pageId, $storedIndex, $sectionId, $child, null, $after[$i], 'insert', $entries);
                } elseif (! $hasAfter) {
                    $this->emit($scope, $pageId, $storedIndex, $sectionId, $child, $before[$i], null, 'remove', $entries);
                } else {
                    $this->diffTree($before[$i], $after[$i], $scope, $pageId, $storedIndex, $sectionId, $child, $entries);
                }
            }

            return;
        }

        if (is_array($before) && is_array($after)) {
            $keys = array_unique([...array_keys($before), ...array_keys($after)]);
            sort($keys, SORT_STRING);

            foreach ($keys as $key) {
                $child = $this->join($path, (string) $key);
                $hasBefore = array_key_exists($key, $before);
                $hasAfter = array_key_exists($key, $after);

                if (! $hasBefore) {
                    if (is_array($after[$key])) {
                        $this->diffTree([], $after[$key], $scope, $pageId, $storedIndex, $sectionId, $child, $entries);
                    } else {
                        $this->emit($scope, $pageId, $storedIndex, $sectionId, $child, null, $after[$key], 'set', $entries);
                    }
                } elseif (! $hasAfter) {
                    if (is_array($before[$key])) {
                        $this->diffTree($before[$key], [], $scope, $pageId, $storedIndex, $sectionId, $child, $entries);
                    } else {
                        $this->emit($scope, $pageId, $storedIndex, $sectionId, $child, $before[$key], null, 'unset', $entries);
                    }
                } else {
                    $this->diffTree($before[$key], $after[$key], $scope, $pageId, $storedIndex, $sectionId, $child, $entries);
                }
            }

            return;
        }

        $kind = match (true) {
            $before === null => 'set',
            $after === null => 'unset',
            default => 'set',
        };

        $this->emit($scope, $pageId, $storedIndex, $sectionId, $path, $before, $after, $kind, $entries);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function emit(
        string $scope,
        ?int $pageId,
        ?int $storedIndex,
        ?string $sectionId,
        string $path,
        mixed $before,
        mixed $after,
        string $kind,
        array &$entries,
    ): void {
        $beforePresented = $this->present($before);
        $afterPresented = $this->present($after);

        $entries[] = $this->entry(
            $scope,
            $pageId,
            $storedIndex,
            $sectionId,
            $this->fieldPathFor($scope, $storedIndex, $path),
            $path,
            $beforePresented['value'],
            $afterPresented['value'],
            $kind,
            $beforePresented['truncated'] || $afterPresented['truncated'],
        );
    }

    /**
     * @return array{scope: string, page_id: ?int, stored_index: ?int, section_id: ?string, field_path: ?string, path: string, before: mixed, after: mixed, kind: string, truncated: bool}
     */
    private function entry(
        string $scope,
        ?int $pageId,
        ?int $storedIndex,
        ?string $sectionId,
        ?string $fieldPath,
        string $path,
        mixed $before,
        mixed $after,
        string $kind,
        bool $truncated,
    ): array {
        return [
            'scope' => $scope,
            'page_id' => $pageId,
            'stored_index' => $storedIndex,
            'section_id' => $sectionId,
            'field_path' => $fieldPath,
            'path' => $path,
            'before' => $before,
            'after' => $after,
            'kind' => $kind,
            'truncated' => $truncated,
        ];
    }

    /**
     * @return array{value: mixed, truncated: bool}
     */
    private function present(mixed $value): array
    {
        if (is_array($value)) {
            return ['value' => ['__count' => count($value)], 'truncated' => false];
        }

        if (is_string($value) && $this->isRedacted($value)) {
            return ['value' => null, 'truncated' => true];
        }

        if (is_string($value) && strlen($value) > self::VALUE_LIMIT_BYTES) {
            return [
                'value' => mb_strcut($value, 0, self::VALUE_LIMIT_BYTES, 'UTF-8'),
                'truncated' => true,
            ];
        }

        return ['value' => $value, 'truncated' => false];
    }

    private function isRedacted(string $value): bool
    {
        if (str_starts_with($value, 'data:')) {
            return true;
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            return true;
        }

        // C0 except tab/LF/CR, DEL, and C1 (U+0080–U+009F).
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{80}-\x{9F}]/u', $value) === 1) {
            return true;
        }

        // Only at or above the payload floor. Below it, a canonical base64 string decodes to fewer
        // than BASE64_MIN_CHARS characters' worth of bytes (48) - too small to be the media payload this rule exists to keep out
        // of a diff - while ordinary editor values ARE canonical base64 by accident: every
        // four-letter word is ('Home', 'hero', 'type', 'body', 'dark'), and so are 'Services' and
        // 'Projects', which are section types in this product. Redacting those blanks real content
        // silently, which is worse than echoing 48 bytes that cannot be a payload. The floor is the
        // whole of the precision trade; the pure-letter exemption that used to sit here was a hole,
        // because 64 'A's is canonical base64 of NUL bytes.
        if (strlen($value) < self::BASE64_MIN_CHARS) {
            return false;
        }

        return $this->isCanonicalBase64($value);
    }

    /**
     * Strict round-trip: alphabet (standard + URL-safe), strict decode, and
     * re-encoding equals the input. SHA-256 hex and long numeric codes match
     * this and are blanked — accepted cost of the absolute contract.
     */
    private function isCanonicalBase64(string $value): bool
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9+\/_-]+={0,2}$/', $value) !== 1) {
            return false;
        }

        $usesUrlSafe = str_contains($value, '-') || str_contains($value, '_');
        $usesStandard = str_contains($value, '+') || str_contains($value, '/');
        if ($usesUrlSafe && $usesStandard) {
            return false;
        }

        $standard = $usesUrlSafe ? strtr($value, '-_', '+/') : $value;
        $decoded = base64_decode($standard, true);
        if ($decoded === false) {
            return false;
        }

        $reencoded = base64_encode($decoded);
        if ($usesUrlSafe) {
            $reencoded = strtr($reencoded, '+/', '-_');
        }

        return $reencoded === $value;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<int, mixed>
     */
    private function sections(array $content): array
    {
        $sections = $content['sections'] ?? [];

        return is_array($sections) ? array_values($sections) : [];
    }

    private function sectionId(mixed $section): ?string
    {
        if (! is_array($section)) {
            return null;
        }

        $id = $section['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @param  array<int, mixed>  $sections
     * @return array<string, list<int>>
     */
    private function indexesByType(array $sections): array
    {
        $grouped = [];

        foreach ($sections as $index => $section) {
            $type = is_array($section) ? (string) ($section['type'] ?? '') : '';
            $grouped[$type][] = $index;
        }

        return $grouped;
    }

    /**
     * @param  array<int|string, mixed>  $raw
     * @return array<string, array<string, mixed>>
     */
    private function selectionMap(array $raw): array
    {
        $map = [];

        if ($this->looksLikeSelectionRows($raw)) {
            foreach ($raw as $row) {
                if (! is_array($row) || ! isset($row['family'])) {
                    continue;
                }

                $map[$this->selectionKey($row)] = Arr::except($row, self::SELECTION_IGNORED_KEYS);
            }

            return $map;
        }

        foreach ($raw as $key => $value) {
            $map[(string) $key] = is_array($value) ? $value : ['version_id' => $value];
        }

        return $map;
    }

    /**
     * @param  array<int|string, mixed>  $raw
     */
    private function looksLikeSelectionRows(array $raw): bool
    {
        if ($raw === []) {
            return true;
        }

        foreach ($raw as $row) {
            if (! is_array($row) || ! array_key_exists('family', $row)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function selectionKey(array $row): string
    {
        $parts = [(string) $row['family']];
        $pageType = (string) ($row['page_type'] ?? '');
        $slot = (string) ($row['slot'] ?? '');

        if ($pageType !== '') {
            $parts[] = $pageType;
        }
        if ($slot !== '') {
            $parts[] = $slot;
        }

        return implode('.', $parts);
    }

    /**
     * @param  array<int|string, mixed>  $left
     * @param  array<int|string, mixed>  $right
     */
    private function treatAsList(array $left, array $right): bool
    {
        return (array_is_list($left) && $left !== []) || (array_is_list($right) && $right !== []);
    }

    private function join(string $base, string $key): string
    {
        return $base === '' ? $key : $base.'.'.$key;
    }

    private function fieldPathFor(string $scope, ?int $storedIndex, string $path): ?string
    {
        if ($scope !== 'page') {
            return null;
        }

        if ($storedIndex === null) {
            return $path === '' ? null : $path;
        }

        $prefix = 'sections.'.$storedIndex;
        if ($path === $prefix) {
            return null;
        }
        if (str_starts_with($path, $prefix.'.')) {
            return substr($path, strlen($prefix) + 1);
        }

        return $path;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function sorted(array $entries): array
    {
        usort($entries, function (array $left, array $right): int {
            $page = ($left['page_id'] ?? PHP_INT_MAX) <=> ($right['page_id'] ?? PHP_INT_MAX);
            if ($page !== 0) {
                return $page;
            }

            $index = ($left['stored_index'] ?? PHP_INT_MAX) <=> ($right['stored_index'] ?? PHP_INT_MAX);
            if ($index !== 0) {
                return $index;
            }

            return $left['path'] <=> $right['path'];
        });

        return array_values($entries);
    }
}
