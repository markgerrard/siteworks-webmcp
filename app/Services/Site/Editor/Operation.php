<?php

namespace App\Services\Site\Editor;

interface Operation
{
    public function name(): string;

    public function readOnly(): bool;

    public function requiresApproval(): bool;

    public function destructive(): bool;

    /**
     * @return list<string>
     */
    public function delegatesTo(): array;

    public function ability(): string;

    public function sideEffects(): string;

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    public function wrapInAdminChange(): bool;

    /** 'page' = page-addressed (revision_base); 'site' = site-level (composition_revision) */
    public function address(): string;

    /**
     * When true, EditorOperations skips the generic revision precondition so
     * handle() can look up an idempotency receipt before checking revision.
     */
    public function managesOwnRevision(): bool;

    /**
     * May return an ok result carrying a `deferred` closure — the real work then happens when the CALLER
     * executes it (EditorOperations::run does, after its transaction). Delegating operations must use
     * BaseOperation::delegate(), never a bare handle().
     *
     * @param  array<string, mixed>  $input
     */
    public function handle(EditorContext $ctx, array $input): OperationResult;
}
