<?php

namespace App\Services\Site\Editor;

final class ChangeBag
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $changes = [];

    public function record(
        string $scope,
        string $path,
        mixed $before,
        mixed $after,
        string $kind,
    ): void {
        $this->changes[] = [
            'scope' => $scope,
            'page_id' => null,
            'stored_index' => null,
            'section_id' => null,
            'field_path' => null,
            'path' => $path,
            'before' => $before,
            'after' => $after,
            'kind' => $kind,
            'truncated' => false,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->changes;
    }
}
