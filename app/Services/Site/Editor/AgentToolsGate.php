<?php

namespace App\Services\Site\Editor;

use App\Models\User;

final class AgentToolsGate
{
    /**
     * Two INDEPENDENT flags. `agent_tools.enabled=false` disables only the agent channels; it never
     * denies the `Ui` channel or disables the human editor. Each channel family answers to its own flag.
     *
     * The human channel is never role-gated: a user who passes `SitePolicy` is a legitimate editor. Agent
     * channels additionally require the actor's role to be allowlisted, because a browser agent (or an MCP
     * client) acting inside that user's session is a different trust question from the user themselves.
     */
    public function enabledFor(User $user, ActorChannel $channel): bool
    {
        if ($channel === ActorChannel::Ui) {
            return (bool) config('editor.operations.enabled', false);
        }

        if (! (bool) config('editor.agent_tools.enabled', false)) {
            return false;
        }

        $roles = config('editor.agent_tools.roles', []);

        return $this->actorHasRole($user, $roles);
    }

    /**
     * Same as enabledFor, with per-operation role narrowing when the operation
     * declares a non-null allowedRoles(). Intersection only — can only narrow,
     * never widen past the configured roles.
     *
     * Staff on Ui is trusted (SitePolicy is the wall) and skips allowedRoles().
     * A client never gets that pass: allowedRoles() applies on both Ui and
     * Webmcp so omitting X-Editor-Channel cannot skip staff-only ops.
     *
     * CommerceOperations::SANDBOX is the client allowlist on every channel
     * (Ui, Webmcp, Mcp): a client may run only those names, and only when
     * editor.agent_tools.client_portal_enabled is on (plus the agent-channel
     * role gate — Webmcp for the browser fronts, the actual channel on Mcp).
     * Every other op is denied for clients regardless of a null
     * allowedRoles(). Staff is unchanged.
     */
    public function enabledForUserAndOperation(User $user, ActorChannel $channel, Operation $operation): bool
    {
        if (! $this->enabledFor($user, $channel)) {
            return false;
        }

        if (! $this->clientPortalSandboxAllowed($user, $channel, $operation)) {
            return false;
        }

        if ($channel === ActorChannel::Ui && ! $user->isClientUser()) {
            return true;
        }

        $opRoles = $operation->allowedRoles();

        if ($opRoles !== null) {
            $roles = config('editor.agent_tools.roles', []);
            $narrowed = array_values(array_intersect($opRoles, $roles));

            return $this->actorHasRole($user, $narrowed);
        }

        return true;
    }

    public function assert(EditorContext $ctx): void
    {
        if (! $this->enabledFor($ctx->actor, $ctx->channel)) {
            throw new AgentToolsDisabledException('agent tools disabled');
        }
    }

    private function actorHasRole(User $user, array $roles): bool
    {
        return ($user->isStaff() && in_array('staff', $roles, true))
            || ($user->isClientUser() && in_array('client', $roles, true));
    }

    /**
     * A client may run only SANDBOX names on every channel, and those still
     * need the seed's two gates (deploy flag + agent-channel role).
     * Non-sandbox names are denied here even when allowedRoles() is null.
     * Staff skip this allowlist. Browser fronts re-check Webmcp so a
     * handcrafted Ui request cannot skip EDITOR_AGENT_TOOLS; Mcp re-checks
     * the actual channel.
     */
    private function clientPortalSandboxAllowed(User $user, ActorChannel $channel, Operation $operation): bool
    {
        if (! $user->isClientUser()) {
            return true;
        }

        if (! in_array($operation->name(), $this->clientAllowlist(), true)) {
            return false;
        }

        // Same two gates the portal seed requires: deploy flag AND the
        // agent-channel role gate. Re-check Webmcp on the browser fronts so
        // a handcrafted Ui request (no X-Editor-Channel) cannot skip
        // EDITOR_AGENT_TOOLS. On Mcp, re-check the actual channel so a
        // client SANDBOX op still needs both gates.
        $roleChannel = $channel === ActorChannel::Mcp
            ? ActorChannel::Mcp
            : ActorChannel::Webmcp;

        return (bool) config('editor.agent_tools.client_portal_enabled', false)
            && $this->enabledFor($user, $roleChannel);
    }

    /**
     * @return list<string>
     */
    private function clientAllowlist(): array
    {
        $allowed = CommerceOperations::SANDBOX;

        if (! (bool) config('demo.enabled')) {
            return $allowed;
        }

        $extra = config('demo.editor_client_operations', []);
        if (! is_array($extra) || $extra === []) {
            return $allowed;
        }

        $extra = array_values(array_filter(
            $extra,
            fn (mixed $name): bool => is_string($name) && $name !== '',
        ));

        return array_values(array_unique([...$allowed, ...$extra]));
    }
}
