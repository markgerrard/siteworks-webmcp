<?php

namespace App\Services\Site\Editor\Operations;

use App\Models\Shop\ShopDraft;
use App\Services\Site\Editor\AgentToolsGate;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationRegistry;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\Editor\Shop\ShopEntityResolver;
use App\Services\Site\Editor\ToolExposure;

final class GetSiteContextOperation extends BaseOperation
{
    public function __construct(
        private readonly EditorStateFactory $states,
        private readonly AgentToolsGate $gate,
        private readonly ShopEntityResolver $shops,
    ) {}

    public function name(): string
    {
        return 'get_site_context';
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function wrapInAdminChange(): bool
    {
        return false;
    }

    public function address(): string
    {
        return 'site';
    }

    /**
     * @return list<string>
     */
    public function allowedRoles(): ?array
    {
        return ['staff', 'client'];
    }

    public function sideEffects(): string
    {
        return 'Reads this site\'s identity, shop flags, and the tool names this actor may call on this surface.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(EditorContext $ctx, array $input): OperationResult
    {
        $site = $ctx->site;
        $hasShop = $this->shops->hasShop($site);

        $data = [
            'site_id' => $site->id,
            'business_name' => $site->business_name,
            'slug' => $site->slug,
            'shop_enabled' => $site->shopEnabled(),
            'has_shop' => $hasShop,
            'capabilities' => $this->capabilities($ctx),
        ];

        if (is_string($site->site_type) && $site->site_type !== '') {
            $data['site_type'] = $site->site_type;
        }

        if (is_string($site->region) && $site->region !== '') {
            $data['region'] = $site->region;
        }

        if ($hasShop) {
            $data['catalogue_revision'] = (int) (ShopDraft::query()->where('site_id', $site->id)->value('catalogue_revision') ?? 0);
        }

        return OperationResult::ok($data, $this->states->for($site, null));
    }

    /**
     * ToolExposure::setFor is the site's reachable catalogue; the gate then
     * drops names this actor/channel cannot actually call (client SANDBOX
     * wall, role intersection). Registry is resolved here, not in the
     * constructor: discover() instantiates this class while building the
     * registry singleton.
     *
     * @return list<string>
     */
    private function capabilities(EditorContext $ctx): array
    {
        $registry = app(OperationRegistry::class);
        $exposure = app(ToolExposure::class);
        $names = [];

        foreach ($exposure->setFor($ctx->site) as $name) {
            if (! $registry->has($name)) {
                continue;
            }

            if ($this->gate->enabledForUserAndOperation($ctx->actor, $ctx->channel, $registry->get($name))) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
