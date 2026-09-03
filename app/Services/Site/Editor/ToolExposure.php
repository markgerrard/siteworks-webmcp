<?php

namespace App\Services\Site\Editor;

use App\Models\Site;
use App\Services\Site\Editor\Shop\ShopEntityResolver;
use InvalidArgumentException;

/**
 * Exposure sets (spec § 8, ruling R1): build scope ≠ registration scope, as configuration.
 *
 * What exists on the branch and what a given tenant's agents may reach are separate decisions.
 * The set is enforced at EXECUTION for agent channels (EditorOperations::run) — not only at
 * registration — so a tenant's reachable surface equals its registered one whatever the caller
 * managed to discover. The ui channel is never exposure-gated, and exposure can only NARROW:
 * AgentToolsGate and SitePolicy still apply in full on a site whose set is `internal`.
 *
 * FAIL CLOSED by construction: an unlisted site gets the default set (sandbox, the narrowest),
 * so every site the configuration has not heard of — every new tenant — is narrow until an
 * operator affirmatively widens it via EDITOR_TOOL_INTERNAL_SITES. The class refuses to
 * construct on an unknown set name or an unparseable site list, so a mangled env cannot
 * silently widen: the first thing that consults exposure throws instead.
 */
final class ToolExposure
{
    private const WILDCARD = '*';

    /** The set every site in internal_sites maps onto. */
    private const INTERNAL_SET = 'internal';

    /** @var array<string, list<string>> */
    private array $sets;

    private string $defaultSet;

    /** @var list<int> */
    private array $internalSiteIds;

    public function __construct(
        private readonly OperationRegistry $registry,
        private readonly ShopEntityResolver $shops,
    )
    {
        $sets = config('editor.exposure.sets');

        if (! is_array($sets) || $sets === []) {
            throw new InvalidArgumentException('editor.exposure.sets must be a non-empty map of set name to operation names.');
        }

        foreach ($sets as $name => $operations) {
            if (! is_string($name) || $name === '') {
                throw new InvalidArgumentException('editor.exposure.sets keys must be non-empty set names.');
            }

            if (! is_array($operations) || $operations === []) {
                throw new InvalidArgumentException("editor.exposure.sets.{$name} must be a non-empty list of operation names (or ['*']).");
            }

            if ($operations !== [self::WILDCARD]) {
                foreach ($operations as $operation) {
                    if (! is_string($operation) || $operation === '' || $operation === self::WILDCARD) {
                        throw new InvalidArgumentException("editor.exposure.sets.{$name} must list operation name strings; '*' is only valid as the sole entry.");
                    }
                }
            }
        }

        $default = config('editor.exposure.default');
        if (! is_string($default) || ! array_key_exists($default, $sets)) {
            throw new InvalidArgumentException('editor.exposure.default ['.var_export($default, true).'] is not a configured exposure set name.');
        }

        if (! array_key_exists(self::INTERNAL_SET, $sets)) {
            throw new InvalidArgumentException("editor.exposure.sets must define the '".self::INTERNAL_SET."' set: editor.exposure.internal_sites maps listed sites onto it.");
        }

        $this->sets = $sets;
        $this->defaultSet = $default;
        $this->internalSiteIds = self::parseSiteIds((string) config('editor.exposure.internal_sites', ''));
    }

    /**
     * The operation names exposed for this site: the configured list for its set, with a
     * `['*']` set expanded against the registry. Names the branch has not built yet may
     * appear; an unbuilt operation is uncallable anyway.
     *
     * @return list<string>
     */
    public function setFor(Site $site): array
    {
        $operations = $this->named($this->nameFor($site));

        if ($this->shops->hasShop($site)) {
            return $operations;
        }

        return array_values(array_filter(
            $operations,
            function (string $name): bool {
                if (! $this->registry->has($name)) {
                    return ! CommerceOperations::isCommerce($name);
                }

                return $this->registry->get($name)->address() !== 'shop';
            },
        ));
    }

    /**
     * The set name that applies to this site — 'internal' for a listed site, the default
     * (sandbox, fail closed) for every other site including new tenants.
     */
    public function nameFor(Site $site): string
    {
        return in_array($site->id, $this->internalSiteIds, true)
            ? self::INTERNAL_SET
            : $this->defaultSet;
    }

    /**
     * May an agent CALL this operation on this site? Execution-side check for agent channels.
     */
    public function exposes(Site $site, string $operation): bool
    {
        return in_array($operation, $this->setFor($site), true);
    }

    /**
     * A configured set by name. Front 3 (EditorMcpServer) has no site at registration time —
     * site_id arrives per call — so it registers this, the internal set: /mcp/editor is an
     * internal surface by construction, and the client sandbox is a Front 2 browser tenant.
     *
     * @return list<string>
     */
    public function named(string $name): array
    {
        $operations = $this->sets[$name]
            ?? throw new InvalidArgumentException("Unknown exposure set [{$name}].");

        if ($operations === [self::WILDCARD]) {
            return array_keys($this->registry->all());
        }

        return array_values($operations);
    }

    /**
     * @return list<int>
     */
    private static function parseSiteIds(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return [];
        }

        $ids = [];
        foreach (explode(',', $raw) as $piece) {
            $piece = trim($piece);

            if ($piece === '' || ! ctype_digit($piece)) {
                throw new InvalidArgumentException("editor.exposure.internal_sites [{$raw}] is not a parseable comma-separated site id list.");
            }

            $ids[] = (int) $piece;
        }

        return $ids;
    }
}
