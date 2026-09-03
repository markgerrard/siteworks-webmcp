<?php

namespace App\Services\Site;

class CompositionDelta
{
    /**
     * @param  array<int, array{column: string, page_id: int}>  $footerColumnEntries
     * @param  array<int, array{page_id: int, label: string}>  $navEntries
     */
    public function __construct(
        public array $footerColumnEntries = [],
        public array $navEntries = [],
    ) {}

    /**
     * Add footer/nav links. Pure: does not mutate the input array.
     * Deterministic key order. Idempotent: apply(apply(x)) === apply(x).
     *
     * @param  array<string, mixed>  $composition
     * @return array<string, mixed>
     */
    public function apply(array $composition): array
    {
        foreach ($this->footerColumnEntries as $entry) {
            $composition = $this->addFooterEntry(
                $composition,
                (string) $entry['column'],
                (int) $entry['page_id'],
            );
        }

        foreach ($this->navEntries as $entry) {
            $composition = $this->addNavEntry(
                $composition,
                (int) $entry['page_id'],
                (string) $entry['label'],
            );
        }

        return $composition;
    }

    /**
     * Remove footer/nav links described by this delta. Pure and idempotent.
     *
     * @param  array<string, mixed>  $composition
     * @return array<string, mixed>
     */
    public function remove(array $composition): array
    {
        foreach ($this->footerColumnEntries as $entry) {
            $composition = $this->removeFooterEntry(
                $composition,
                (string) $entry['column'],
                (int) $entry['page_id'],
            );
        }

        foreach ($this->navEntries as $entry) {
            $composition = $this->removeNavEntry($composition, (int) $entry['page_id']);
        }

        return $composition;
    }

    /**
     * @param  array<string, mixed>  $composition
     * @return array<string, mixed>
     */
    protected function addFooterEntry(array $composition, string $column, int $pageId): array
    {
        if (! isset($composition['footer']) || ! is_array($composition['footer'])) {
            $composition['footer'] = ['columns' => []];
        }

        if (! isset($composition['footer']['columns']) || ! is_array($composition['footer']['columns'])) {
            $composition['footer']['columns'] = [];
        }

        $columns = $composition['footer']['columns'];
        $index = null;

        foreach ($columns as $i => $col) {
            if (is_array($col) && ($col['title'] ?? null) === $column) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            $columns[] = [
                'title' => $column,
                'items' => [['page_id' => $pageId]],
            ];
            $composition['footer']['columns'] = $columns;

            return $composition;
        }

        $items = $columns[$index]['items'] ?? [];
        if (! is_array($items)) {
            $items = [];
        }

        foreach ($items as $item) {
            if (is_array($item) && (int) ($item['page_id'] ?? 0) === $pageId) {
                return $composition;
            }
        }

        $items[] = ['page_id' => $pageId];
        $columns[$index]['items'] = $items;
        $composition['footer']['columns'] = $columns;

        return $composition;
    }

    /**
     * @param  array<string, mixed>  $composition
     * @return array<string, mixed>
     */
    protected function removeFooterEntry(array $composition, string $column, int $pageId): array
    {
        $columns = $composition['footer']['columns'] ?? null;
        if (! is_array($columns)) {
            return $composition;
        }

        foreach ($columns as $i => $col) {
            if (! is_array($col) || ($col['title'] ?? null) !== $column) {
                continue;
            }

            $items = $col['items'] ?? [];
            if (! is_array($items)) {
                break;
            }

            $columns[$i]['items'] = array_values(array_filter(
                $items,
                fn ($item) => ! is_array($item) || (int) ($item['page_id'] ?? 0) !== $pageId,
            ));
            break;
        }

        $composition['footer']['columns'] = $columns;

        return $composition;
    }

    /**
     * @param  array<string, mixed>  $composition
     * @return array<string, mixed>
     */
    protected function addNavEntry(array $composition, int $pageId, string $label): array
    {
        if (! isset($composition['nav']) || ! is_array($composition['nav'])) {
            $composition['nav'] = ['items' => []];
        }

        if (! isset($composition['nav']['items']) || ! is_array($composition['nav']['items'])) {
            $composition['nav']['items'] = [];
        }

        if ($this->navContainsPage($composition['nav']['items'], $pageId)) {
            return $composition;
        }

        $composition['nav']['items'][] = [
            'type' => 'page',
            'page_id' => $pageId,
            'label' => $label,
        ];

        return $composition;
    }

    /**
     * @param  array<string, mixed>  $composition
     * @return array<string, mixed>
     */
    protected function removeNavEntry(array $composition, int $pageId): array
    {
        $items = $composition['nav']['items'] ?? null;
        if (! is_array($items)) {
            return $composition;
        }

        $filtered = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                $filtered[] = $item;

                continue;
            }

            if (($item['type'] ?? null) === 'page' && (int) ($item['page_id'] ?? 0) === $pageId) {
                continue;
            }

            if (($item['type'] ?? null) === 'group' && isset($item['children']) && is_array($item['children'])) {
                $item['children'] = array_values(array_filter(
                    $item['children'],
                    fn ($child) => ! is_array($child) || (int) ($child['page_id'] ?? 0) !== $pageId,
                ));
            }

            $filtered[] = $item;
        }

        $composition['nav']['items'] = $filtered;

        return $composition;
    }

    /**
     * @param  array<int, mixed>  $items
     */
    protected function navContainsPage(array $items, int $pageId): bool
    {
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (($item['type'] ?? null) === 'page' && (int) ($item['page_id'] ?? 0) === $pageId) {
                return true;
            }

            if (($item['type'] ?? null) === 'group' && isset($item['children']) && is_array($item['children'])) {
                foreach ($item['children'] as $child) {
                    if (is_array($child) && (int) ($child['page_id'] ?? 0) === $pageId) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
