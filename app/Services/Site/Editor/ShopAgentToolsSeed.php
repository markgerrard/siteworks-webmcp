<?php

namespace App\Services\Site\Editor;

use App\Models\Shop\ShopDraft;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Js;
use InvalidArgumentException;

final class ShopAgentToolsSeed
{
    /**
     * Page-level advertisement sets the mount may choose. Tenant
     * classification (ToolExposure::nameFor) is a separate axis.
     *
     * @var list<string>
     */
    private const PAGE_SETS = ['sandbox', 'portal_base'];

    public function __construct(
        private readonly AgentToolsGate $gate,
        private readonly ToolExposure $exposure,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function config(User $user, Site $site, string $surface = 'shop-admin', string $set = 'sandbox'): array
    {
        $pageSet = $this->pageSet($set);
        $capabilities = ['edit', 'media'];
        $config = [
            'surface' => $surface,
            'siteId' => $site->id,
            'capabilities' => $capabilities,
            'catalogueRevision' => (int) (ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision') ?? 0),
        ];

        $agentToolsEnabled = $this->gate->enabledFor($user, ActorChannel::Webmcp);

        if ($surface === 'portal-shop') {
            $agentToolsEnabled = $agentToolsEnabled
                && (bool) config('editor.agent_tools.client_portal_enabled', false);
        }

        // Portal pages must not hand an in-page script the op endpoint + CSRF
        // when the client-portal gate is off. Shop-admin keeps the fields
        // unconditionally (staff shell).
        if ($agentToolsEnabled || $surface !== 'portal-shop') {
            $config['protocol'] = 'siteworks-editor-1';
            $config['csrfToken'] = csrf_token();
            $config['operationUrl'] = route('site.editor.operation', ['site' => $site->id, 'operation' => '__operation__'], false);
        }

        if (! $agentToolsEnabled) {
            return $config;
        }

        $capabilities[] = 'agent_tools';
        $config['capabilities'] = $capabilities;
        $config['exposureSet'] = $this->exposure->nameFor($site);
        $config['agentTools'] = array_values(array_intersect(
            $this->exposure->setFor($site),
            $pageSet,
        ));

        return $config;
    }

    public function js(User $user, Site $site, string $surface = 'shop-admin', string $set = 'sandbox'): string
    {
        return (string) Js::from($this->config($user, $site, $surface, $set));
    }

    /**
     * @return list<string>
     */
    private function pageSet(string $set): array
    {
        if (! in_array($set, self::PAGE_SETS, true)) {
            throw new InvalidArgumentException("Unknown page-level exposure set [{$set}].");
        }

        // 'sandbox' here is the shop-page allowlist (CommerceOperations::SANDBOX),
        // not the tenant sandbox set which also names specialist editor ops.
        if ($set === 'sandbox') {
            return CommerceOperations::SANDBOX;
        }

        return $this->exposure->named($set);
    }
}
