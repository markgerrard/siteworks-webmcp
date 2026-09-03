<?php

namespace App\Services\Site\Editor;

use App\Models\Site;
use App\Models\Site\EditorAgentApproval;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ApprovalStore
{
    private const HASH_EXCLUDED_KEYS = [
        'approval_request_id',
        'parent_origin',
        'revision_base',
        'structure_epoch',
        'composition_revision',
    ];

    private const ASSIGNMENT_TARGET_KEYS = [
        'page_id',
        'stored_index',
        'field_path',
    ];

    public function __construct(private readonly ApprovalPresentation $presentation) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function argsHash(string $operation, array $input): string
    {
        $excludedKeys = self::HASH_EXCLUDED_KEYS;
        if ($this->bindsAssignmentStructureEpoch($operation, $input)) {
            $excludedKeys = array_diff($excludedKeys, ['structure_epoch']);
        }

        foreach ($excludedKeys as $key) {
            unset($input[$key]);
        }

        $canonical = $this->canonicalise([
            'operation' => $operation,
            'input' => $input,
        ]);

        return hash('sha256', json_encode($canonical, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function mint(
        EditorContext $ctx,
        string $principal,
        string $operation,
        array $input,
    ): EditorAgentApproval|MintRefused {
        return DB::transaction(function () use ($ctx, $principal, $operation, $input): EditorAgentApproval|MintRefused {
            $connection = DB::connection()->getName();

            Site::on($connection)->whereKey($ctx->site->getKey())->lockForUpdate()->firstOrFail();

            $principalRows = EditorAgentApproval::on($connection)
                ->where('grant_principal', $principal)
                ->where('site_id', $ctx->site->getKey())
                ->where('channel', $ctx->channel->value);
            $cooldownMinutes = (int) config('editor.agent_approval.denied_cooldown_minutes', 30);
            $denialCutoff = now()->subMinutes($cooldownMinutes);

            // The whole mint decision is one transaction: lock this principal's relevant rows before
            // dedupe, cooldown, cap, and insert. The site lock also serialises the empty-row case.
            (clone $principalRows)
                ->where('kind', 'operation')
                ->where(function (Builder $query) use ($denialCutoff): void {
                    $query->livePending()
                        ->orWhere(function (Builder $query) use ($denialCutoff): void {
                            $query->whereNotNull('denied_at')
                                ->where('denied_at', '>', $denialCutoff);
                        });
                })
                ->lockForUpdate()
                ->get();

            $argsHash = $this->argsHash($operation, $input);
            $sameRequest = (clone $principalRows)
                ->where('kind', 'operation')
                ->where('operation', $operation)
                ->where('args_hash', $argsHash);

            $pending = (clone $sameRequest)->livePending()->first();
            if ($pending !== null) {
                return $pending;
            }

            $recentDenial = (clone $sameRequest)
                ->whereNotNull('denied_at')
                ->where('denied_at', '>', $denialCutoff)
                ->exists();

            if ($recentDenial) {
                return new MintRefused('denied_cooldown');
            }

            $pendingLimit = (int) config('editor.agent_approval.pending_limit', 25);
            if ((clone $principalRows)->where('kind', 'operation')->livePending()->count() >= $pendingLimit) {
                return new MintRefused('pending_limit');
            }

            return EditorAgentApproval::on($connection)->create([
                'kind' => 'operation',
                'site_id' => $ctx->site->getKey(),
                'requested_by_user_id' => $ctx->actor->getKey(),
                'requested_by_identifier' => $this->identifier($ctx->actor),
                'approved_by_user_id' => null,
                'approved_by_identifier' => null,
                'channel' => $ctx->channel->value,
                'grant_principal' => $principal,
                'operation' => $operation,
                'args_hash' => $argsHash,
                'summary' => $this->presentation->for($ctx, $operation, $input),
                'requested_at' => now(),
                'approved_at' => null,
                'denied_at' => null,
                'consumed_at' => null,
                'expires_at' => now()->addMinutes((int) config('editor.agent_approval.ttl_minutes', 5)),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function verify(
        EditorContext $ctx,
        string $principal,
        string $operation,
        array $input,
        string $requestId,
    ): ?EditorAgentApproval {
        if (! Str::isUuid($requestId)) {
            return null;
        }

        return EditorAgentApproval::query()
            ->whereKey($requestId)
            ->where('kind', 'operation')
            ->where('site_id', $ctx->site->getKey())
            ->where('requested_by_user_id', $ctx->actor->getKey())
            ->where('channel', $ctx->channel->value)
            ->where('grant_principal', $principal)
            ->where('operation', $operation)
            ->where('args_hash', $this->argsHash($operation, $input))
            ->spendable()
            ->first();
    }

    public function consume(EditorAgentApproval $approval): bool
    {
        return $approval->newQuery()
            ->whereKey($approval->getKey())
            ->where('kind', 'operation')
            ->whereNull('consumed_at')
            ->whereNull('denied_at')
            ->where('expires_at', '>', now())
            ->update(['consumed_at' => now()]) === 1;
    }

    public function grant(
        Site $site,
        User $human,
        string $principal,
        ActorChannel $channel,
    ): EditorAgentApproval {
        $now = now();

        return EditorAgentApproval::query()->create([
            'kind' => 'grant',
            'site_id' => $site->getKey(),
            'requested_by_user_id' => $human->getKey(),
            'requested_by_identifier' => $this->identifier($human),
            'approved_by_user_id' => $human->getKey(),
            'approved_by_identifier' => $this->identifier($human),
            'channel' => $channel->value,
            'grant_principal' => $principal,
            'operation' => '*',
            'args_hash' => '',
            'summary' => ['site' => $this->presentation->for(
                new EditorContext($human, $site, $channel),
                'get_brand_context',
                [],
            )['site']],
            'requested_at' => $now,
            'approved_at' => $now,
            'denied_at' => null,
            'consumed_at' => null,
            'expires_at' => $now->clone()->addMinutes((int) config('editor.agent_approval.grant_ttl_minutes', 60)),
        ]);
    }

    public function activeGrant(EditorContext $ctx, string $principal): ?EditorAgentApproval
    {
        return EditorAgentApproval::query()
            ->where('kind', 'grant')
            ->where('site_id', $ctx->site->getKey())
            ->where('requested_by_user_id', $ctx->actor->getKey())
            ->where('channel', $ctx->channel->value)
            ->where('grant_principal', $principal)
            ->where('operation', '*')
            ->where('args_hash', '')
            ->spendable()
            ->latest('approved_at')
            ->first();
    }

    public function approve(EditorAgentApproval $approval, User $human): bool
    {
        return $approval->newQuery()
            ->whereKey($approval->getKey())
            ->livePending()
            ->update([
                'approved_at' => now(),
                'approved_by_user_id' => $human->getKey(),
                'approved_by_identifier' => $this->identifier($human),
            ]) === 1;
    }

    public function deny(EditorAgentApproval $approval, ?User $human = null): bool
    {
        $human ??= Auth::user();
        if (! $human instanceof User) {
            return false;
        }

        return $approval->newQuery()
            ->whereKey($approval->getKey())
            ->livePending()
            ->update([
                'denied_at' => now(),
                'approved_by_user_id' => $human->getKey(),
                'approved_by_identifier' => $this->identifier($human),
            ]) === 1;
    }

    public function revoke(EditorAgentApproval $approval, ?User $human = null): bool
    {
        $human ??= Auth::user();
        if (! $human instanceof User) {
            return false;
        }

        return $approval->newQuery()
            ->whereKey($approval->getKey())
            ->where('kind', 'grant')
            ->spendable()
            ->update([
                'denied_at' => now(),
                'approved_by_user_id' => $human->getKey(),
                'approved_by_identifier' => $this->identifier($human),
            ]) === 1;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function bindsAssignmentStructureEpoch(string $operation, array $input): bool
    {
        if ($operation !== 'upload_image') {
            return false;
        }

        return collect(self::ASSIGNMENT_TARGET_KEYS)
            ->every(fn (string $key): bool => array_key_exists($key, $input));
    }

    private function identifier(User $user): string
    {
        if (is_string($user->microsoft_id) && $user->microsoft_id !== '') {
            return $user->microsoft_id;
        }

        return hash('sha256', Str::lower((string) $user->email));
    }

    private function canonicalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $nestedValue) {
            $value[$key] = $this->canonicalise($nestedValue);
        }

        return $value;
    }
}
