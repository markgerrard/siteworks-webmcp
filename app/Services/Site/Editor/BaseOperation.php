<?php

namespace App\Services\Site\Editor;

abstract class BaseOperation implements Operation
{
    public function requiresApproval(): bool
    {
        return false;
    }

    public function destructive(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function delegatesTo(): array
    {
        return [];
    }

    public function ability(): string
    {
        return $this->readOnly() ? 'view' : 'update';
    }

    public function wrapInAdminChange(): bool
    {
        return true;
    }

    /**
     * When non-null, AgentToolsGate intersects this with configured roles,
     * narrowing only. null means no operation-specific narrowing.
     *
     * @return list<string>|null
     */
    public function allowedRoles(): ?array
    {
        return null;
    }

    public function address(): string
    {
        return 'page';
    }

    public function managesOwnRevision(): bool
    {
        return false;
    }

    /**
     * Call another operation's handle() from inside this one. EditorOperations::run executes a returned
     * `deferred` closure itself; a direct handle() call must do the same or the delegated work never happens
     * (upload_image/generate_image/regenerate_hero all defer their real work).
     *
     * @param  array<string, mixed>  $input
     */
    protected function delegate(Operation $operation, EditorContext $ctx, array $input): OperationResult
    {
        $result = $operation->handle($ctx, $input);

        if ($result->ok && $result->deferred !== null) {
            $result = ($result->deferred)();
        }

        $result->receipt = null;

        return $result;
    }
}
