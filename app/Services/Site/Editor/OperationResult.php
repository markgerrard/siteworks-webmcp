<?php

namespace App\Services\Site\Editor;

use InvalidArgumentException;

final class OperationResult
{
    /**
     * @var list<string>
     */
    public const CODES = [
        'stale_revision',
        'not_found',
        'forbidden',
        'validation',
        'unsupported_field',
        'job_running',
        'quota_exceeded',
        'editor_busy',
        'approval_required',
        'published_product_immutable',
        'cycle',
        'depth',
        'slug_taken',
        'revision_conflict',
        'plan_stale',
        'internal',
    ];

    public ?\Closure $deferred = null;

    public ?ResultReceipt $receipt = null;

    /**
     * @param  array<string, mixed>  $data
     * @param  array{code: string, message: string, ...}|null  $error
     */
    private function __construct(
        public bool $ok,
        public array $data,
        public ?array $error,
        public EditorState $state,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function ok(array $data, EditorState $state): self
    {
        return new self(true, $data, null, $state);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function fail(string $code, string $message, EditorState $state, array $extra = []): self
    {
        if (! in_array($code, self::CODES, true)) {
            throw new InvalidArgumentException("Unknown operation error code [{$code}].");
        }

        // Canonical keys always win: $extra can never override code/message (consumers branch and log on error['code']).
        return new self(false, [], [
            'code' => $code,
            'message' => $message,
            ...array_diff_key($extra, ['code' => true, 'message' => true]),
        ], $state);
    }

    public function withState(EditorState $state): self
    {
        $copy = new self($this->ok, $this->data, $this->error, $state);
        $copy->deferred = $this->deferred;
        $copy->receipt = $this->receipt;

        return $copy;
    }

    /**
     * @return array{ok: true, data: array<string, mixed>, state: array<string, mixed>, receipt: array<string, mixed>}|array{ok: false, error: array{code: string, message: string, ...}, state: array<string, mixed>, receipt: array<string, mixed>}
     */
    public function toArray(): array
    {
        if ($this->ok) {
            return [
                'ok' => true,
                'data' => $this->data,
                'state' => $this->state->toArray(),
                'receipt' => ($this->receipt ?? ResultReceipt::neutral())->toArray(),
            ];
        }

        return [
            'ok' => false,
            'error' => $this->error,
            'state' => $this->state->toArray(),
            'receipt' => ($this->receipt ?? ResultReceipt::neutral())->toArray(),
        ];
    }
}
