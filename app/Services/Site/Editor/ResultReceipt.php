<?php

namespace App\Services\Site\Editor;

final readonly class ResultReceipt
{
    /**
     * @param  array<string, mixed>|null  $effective
     * @param  list<array<string, mixed>>  $changed
     * @param  list<array<string, mixed>>  $warnings
     */
    private function __construct(
        public ?int $newRevision,
        public ?array $effective,
        public array $changed,
        public array $warnings,
        public string $preview = 'not_applicable',
    ) {}

    /**
     * @param  list<array<string, mixed>>  $changed
     * @param  array<string, mixed>|null  $effective
     * @param  list<array<string, mixed>>  $warnings
     */
    public static function forWrite(
        EditorState $state,
        string $address,
        array $changed,
        ?array $effective,
        array $warnings,
    ): self {
        return new self(
            newRevision: $address === 'site' ? $state->compositionRevision : $state->draftRevisionId,
            effective: $effective,
            changed: $changed,
            warnings: $warnings,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $warnings
     */
    public static function forRead(array $warnings): self
    {
        return new self(null, null, [], $warnings);
    }

    public static function neutral(): self
    {
        return self::forRead([]);
    }

    /**
     * @param  array<string, mixed>  $receipt
     */
    public static function fromArray(array $receipt): self
    {
        $preview = $receipt['preview'] ?? 'not_applicable';

        return new self(
            newRevision: is_int($receipt['new_revision'] ?? null) ? $receipt['new_revision'] : null,
            effective: is_array($receipt['effective'] ?? null) ? $receipt['effective'] : null,
            changed: is_array($receipt['changed'] ?? null) ? array_values($receipt['changed']) : [],
            warnings: is_array($receipt['warnings'] ?? null) ? array_values($receipt['warnings']) : [],
            preview: in_array($preview, ['applied', 'deferred', 'unconfirmed', 'not_applicable'], true)
                ? $preview
                : 'not_applicable',
        );
    }

    /**
     * @return array{new_revision: int|null, effective: array<string, mixed>|null, changed: list<array<string, mixed>>, warnings: list<array<string, mixed>>, publishable: false, preview: string}
     */
    public function toArray(): array
    {
        return [
            'new_revision' => $this->newRevision,
            'effective' => $this->effective,
            'changed' => $this->changed,
            'warnings' => $this->warnings,
            'publishable' => false,
            'preview' => $this->preview,
        ];
    }
}
