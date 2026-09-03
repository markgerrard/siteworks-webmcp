<?php

namespace App\Services\Site\SiteClone;

class ContentJsonIdRemapper
{
    public int $itemIdsRemapped = 0;

    public int $pairIdsRemapped = 0;

    public int $unmappedDropped = 0;

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, array<int|string, int>>  $idMaps
     * @return array{0: array<string, mixed>, 1: bool}
     */
    public function remap(array $node, array $idMaps): array
    {
        $changed = false;
        $remapped = $this->walk(
            $node,
            $idMaps['project_items'] ?? [],
            $idMaps['before_after_pairs'] ?? [],
            $changed,
        );

        return [$remapped, $changed];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int|string, int>  $itemMap
     * @param  array<int|string, int>  $pairMap
     * @return array<string, mixed>
     */
    private function walk(array $node, array $itemMap, array $pairMap, bool &$changed): array
    {
        foreach ($node as $key => $value) {
            if ($key === 'item_ids' && is_array($value) && array_is_list($value)) {
                $node[$key] = $this->remapIdList($value, $itemMap, $this->itemIdsRemapped, $changed);

                continue;
            }
            if ($key === 'pair_ids' && is_array($value) && array_is_list($value)) {
                $node[$key] = $this->remapIdList($value, $pairMap, $this->pairIdsRemapped, $changed);

                continue;
            }
            if (is_array($value)) {
                $node[$key] = $this->walk($value, $itemMap, $pairMap, $changed);
            }
        }

        return $node;
    }

    /**
     * @param  list<mixed>  $ids
     * @param  array<int|string, int>  $map
     * @return list<int>
     */
    private function remapIdList(array $ids, array $map, int &$remapped, bool &$changed): array
    {
        $out = [];
        foreach ($ids as $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                $this->unmappedDropped++;
                $changed = true;

                continue;
            }

            $newId = $map[(int) $id] ?? null;
            if ($newId === null) {
                $this->unmappedDropped++;
                $changed = true;

                continue;
            }

            $out[] = $newId;
            $remapped++;
            if ($newId !== (int) $id) {
                $changed = true;
            }
        }

        if (count($out) !== count($ids)) {
            $changed = true;
        }

        return $out;
    }
}
