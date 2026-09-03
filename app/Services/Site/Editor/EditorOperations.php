<?php

namespace App\Services\Site\Editor;

use App\Models\GeneratedPage;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\SiteMedia;
use App\Services\Site\CompositionService;
use App\Services\Site\HeroResolution;
use App\Services\Site\PageLayoutRegistry;
use App\Services\Site\ThemeResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class EditorOperations
{
    /**
     * Operations whose schema declares both composition_revision and
     * revision_base, because they support both site-level ingest/dispatch
     * and page-level assignment. A bare expected_revision on a mixed call
     * is rejected — the caller must name both concrete keys.
     *
     * @var list<string>
     */
    public const MIXED_ADDRESS = ['upload_image'];

    public function __construct(
        private readonly OperationRegistry $registry,
        private readonly AgentToolsGate $gate,
        private readonly EditorStateFactory $states,
        private readonly CompositionService $composition,
        private readonly EditorOperationRecorder $recorder,
        private readonly ApprovalStore $approvals,
        private readonly DraftDiffer $differ,
        private readonly DraftAssetSelections $draftSelections,
        private readonly SectionDescriber $sectionDescriber,
        private readonly SectionCatalog $sectionCatalog,
        private readonly PageLayoutRegistry $layouts,
        private readonly HeroResolution $heroResolution,
        private readonly ThemeResolver $themeResolver,
        private readonly ToolExposure $exposure,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function run(EditorContext $ctx, string $operation, array $input): OperationResult
    {
        $started = hrtime(true);
        // Nothing about the target site is read — let alone returned — before authorisation passes: a denial
        // must not leak composition_revision / pending_publish / draft ids, nor answer "does this page belong
        // to that site?". Both fronts inherit this, since every call lands here.
        $page = null;
        $state = new EditorState(
            siteId: $ctx->site->id,
            pageId: null,
            draftRevisionId: null,
            compositionRevision: 0,
            pendingPublish: false,
            structureEpoch: null,
        );

        try {
            if (! $this->registry->has($operation)) {
                // Unknown name: not_found — but only after a view check, so an actor outside SitePolicy
                // cannot use the registry as an existence oracle.
                $code = Gate::forUser($ctx->actor)->check('view', $ctx->site) ? 'not_found' : 'forbidden';
                $message = $code === 'not_found' ? 'Unknown operation.' : 'Not allowed on this site.';

                return $this->finish($ctx, $operation, $input, OperationResult::fail($code, $message, $state), $started, $page);
            }

            $op = $this->registry->get($operation);

            // Exposure sets (spec § 8, ruling R1): for AGENT channels only, an operation the site's
            // exposure set does not name is refused EXACTLY as an unknown name is — same code, same
            // message, decided by the same view check — so the set is not a tool-name existence
            // oracle. It runs before the single ability check for the same reason the unknown-name
            // branch does; AgentToolsGate stays after it (their order is deliberate — do not swap).
            // Delegated calls (BaseOperation::delegate) never re-enter run() and are deliberately
            // not re-gated: the control answers "may this agent call this tool", not "may this code
            // path execute". The ui channel is never exposure-gated. This is the REAL boundary: the
            // agent-reachable adapters ALSO call refuseIfUnexposed before their own preflight, but
            // that only fixes ordering, not enforcement — Layer 0 re-checks on every call that lands
            // here, exactly as the controller comment above the ability check states.
            if (($refused = $this->refuseIfUnexposed($ctx, $operation, $input)) !== null) {
                return $refused;
            }

            $ability = $op->readOnly() ? 'view' : 'update';

            if (! Gate::forUser($ctx->actor)->check($ability, $ctx->site)) {
                return $this->finish($ctx, $operation, $input, OperationResult::fail('forbidden', 'Not allowed on this site.', $state), $started, $page);
            }

            if (! $this->gate->enabledForUserAndOperation($ctx->actor, $ctx->channel, $op)) {
                return $this->finish($ctx, $operation, $input, OperationResult::fail('forbidden', 'Agent tools are disabled for this actor.', $state), $started, $page);
            }

            // Authorised from here: the real state (and the page lookup behind it) is safe to build.

            // Normalise expected_revision before the address check — runs in every
            // front, not only here; ExpectedRevision::normalise is the public entry
            // point all three fronts call.
            $normalised = ExpectedRevision::normalise($op, $input);
            if ($normalised instanceof OperationResult) {
                return $this->finish($ctx, $operation, $input, $normalised, $started, $page);
            }
            $input = $normalised;

            if (ExpectedRevision::missingBase($op, $input)) {
                $inputKey = RevisionScopes::inputKey($op->address());

                return $this->finish(
                    $ctx,
                    $operation,
                    $input,
                    OperationResult::fail('validation', "{$inputKey} is required.", $state, [
                        'fields' => [$inputKey => ['required integer']],
                    ]),
                    $started,
                    $page,
                );
            }

            $page = isset($input['page_id'])
                ? GeneratedPage::query()->where('site_id', $ctx->site->id)->find((int) $input['page_id'])
                : null;
            $state = $this->states->for($ctx->site, $page);
            $captureChanges = ! $op->readOnly() && ($ctx->channel->isAgent() || $ctx->includeChanges);
            $compositionBefore = null;
            $selectionsBefore = [];

            if ($captureChanges && $op->address() === 'site') {
                $compositionBefore = SiteDraft::query()
                    ->where('site_id', $ctx->site->id)
                    ->first()
                    ?->composition ?? [];
                $selectionsBefore = $this->draftSelections->all($ctx->site)
                    ->map(fn ($selection): array => $selection->toArray())
                    ->all();
            }

            $result = null;
            $approval = null;
            $approvalEnabled = (bool) config('editor.agent_approval.enabled') && $ctx->channel->isAgent();
            $continuationCovered = $approvalEnabled
                && ! $op->readOnly()
                && $this->continuationCovers($ctx, $operation, $input);

            if ($approvalEnabled && ! $op->readOnly() && ! $continuationCovered) {
                $principal = $ctx->grantPrincipal ?? $this->requestPrincipal($ctx);
                $requiresApproval = $this->registry->effectiveRequiresApproval($operation);

                if ($principal === null || $principal === '') {
                    return $this->finish(
                        $ctx,
                        $operation,
                        $input,
                        $this->missingPrincipal($operation, $op->sideEffects(), $state, ! $requiresApproval),
                        $started,
                        $page,
                    );
                }

                if ($requiresApproval) {
                    $requestId = is_string($input['approval_request_id'] ?? null)
                        ? $input['approval_request_id']
                        : null;

                    if ($requestId !== null) {
                        $approval = $this->approvals->verify($ctx, $principal, $operation, $input, $requestId);
                    }

                    if ($approval === null) {
                        if ($requestId !== null) {
                            Log::channel(config('logging.auth_channel', 'stack'))->warning('editor_agent_approval_rejected', [
                                'reason' => 'not_spendable_or_binding_mismatch',
                                'request_id' => $requestId,
                            ]);
                        }

                        return $this->finish(
                            $ctx,
                            $operation,
                            $input,
                            $this->mintRequired($ctx, $principal, $operation, $input, $op->sideEffects(), $state),
                            $started,
                            $page,
                        );
                    }

                    $ctx = new EditorContext(
                        $ctx->actor,
                        $ctx->site,
                        $ctx->channel,
                        $principal,
                        $approval->id,
                        $ctx->includeChanges,
                        $ctx->warnings,
                        $ctx->changes,
                    );
                } elseif ($this->approvals->activeGrant($ctx, $principal) === null) {
                    return $this->finish(
                        $ctx,
                        $operation,
                        $input,
                        OperationResult::fail('approval_required', 'A human write grant is required.', $state, [
                            'operation' => $operation,
                            'side_effects' => $op->sideEffects(),
                            'grant' => true,
                        ]),
                        $started,
                        $page,
                    );
                }
            }

            $revisionScope = $op->address();
            $revisionInputKey = RevisionScopes::inputKey($revisionScope);
            $expectedRevision = self::intOrNull($input[$revisionInputKey] ?? null);
            $checkRevision = fn (): ?OperationResult => ExpectedRevision::requiresBase($op)
                ? RevisionScopes::check(
                    $revisionScope,
                    $ctx,
                    $expectedRevision,
                    $state,
                )
                : null;

            $consumeApproval = function () use ($approval, $ctx, $operation, $input, $op, $state): void {
                if ($approval !== null && ! $this->approvals->consume($approval)) {
                    throw new OperationFailed($this->mintRequired(
                        $ctx,
                        (string) $ctx->grantPrincipal,
                        $operation,
                        $input,
                        $op->sideEffects(),
                        $state,
                    ));
                }
            };

            if ($op->readOnly()) {
                $result = $op->handle($ctx, $input);
            } elseif (! $op->wrapInAdminChange()) {
                try {
                    DB::transaction(function () use (&$result, $op, $ctx, $input, $checkRevision, $consumeApproval): void {
                        if (($stale = $checkRevision()) !== null) {
                            throw new OperationFailed($stale); // roll back the seeded draft row too — a failed call leaves no trace
                        }

                        $consumeApproval();

                        $result = $op->handle($ctx, $input);

                        if (! $result->ok) {
                            throw new OperationFailed($result);
                        }
                    });
                } catch (OperationFailed $exception) {
                    $result = $exception->result;
                }

                if ($result->ok && $result->deferred !== null) {
                    $result = ($result->deferred)();
                }
            } else {
                try {
                    $this->composition->applyAdminChange(
                        $ctx->site,
                        function () use (&$result, $op, $ctx, $input, $checkRevision, $consumeApproval): void {
                            if (($stale = $checkRevision()) !== null) {
                                throw new OperationFailed($stale);
                            }

                            $consumeApproval();

                            $result = $op->handle($ctx, $input);

                            if (! $result->ok) {
                                throw new OperationFailed($result);
                            }
                        },
                        $ctx->actor->id,
                        invalidatePublicCache: false,
                    );
                } catch (OperationFailed $exception) {
                    $result = $exception->result;
                }
            }

            if ($result->ok && ! $op->readOnly()) {
                $freshPage = $page?->fresh();
                $result = $result->withState($this->states->for($ctx->site, $freshPage));

                if ($result->receipt === null) {
                    if (array_key_exists('job_ref', $result->data) && ! $ctx->warnings->has('async_pending')) {
                        $ctx->warnings->add(
                            'async_pending',
                            'The asynchronous operation is pending; poll get_job_status for its final receipt.',
                            severity: 'info',
                        );
                    }

                    [$changed, $effective] = $captureChanges && ! array_key_exists('job_ref', $result->data)
                        ? $this->writeChanges(
                            $ctx,
                            $operation,
                            $op->address(),
                            $input,
                            $freshPage,
                            $result,
                            $compositionBefore,
                            $selectionsBefore,
                        )
                        : [[], null];

                    $result->receipt = ResultReceipt::forWrite(
                        $result->state,
                        $op->address(),
                        $changed,
                        $effective,
                        $ctx->warnings->all(),
                    );
                }
            }

            return $this->finish($ctx, $operation, $input, $result, $started, $page);
        } catch (\Throwable $exception) {
            report($exception);

            return $this->finish(
                $ctx,
                $operation,
                $input,
                OperationResult::fail('internal', 'Unexpected error; reference '.Str::uuid(), $state),
                $started,
                $page,
            );
        }
    }

    /**
     * Refuse an operation that an AGENT channel caller may not reach because the site's exposure
     * set (spec § 8, ruling R1) does not name it — EXACTLY as an unknown name is refused: same
     * code, same message, decided by the same view check, recorded as the same audit row. This is
     * the single implementation the adapter-level preflights call, so the refusal is byte-identical
     * to the unknown-name one on every agent-reachable front. Returns null when the call must
     * proceed (the ui channel is never exposure-gated, and an exposed operation is not refused).
     *
     * Adapters invoke this in the same place they already invoke Gate::check, BEFORE any preflight
     * or validation answer, so the exposure refusal is reachable before a 409/422 could leak it.
     * run() keeps its own check below; this fixes ORDERING, not enforcement — Layer 0 re-checks.
     *
     * @param  array<string, mixed>  $input
     */
    public function refuseIfUnexposed(EditorContext $ctx, string $operation, array $input): ?OperationResult
    {
        if (! $ctx->channel->isAgent() || $this->exposure->exposes($ctx->site, $operation)) {
            return null;
        }

        $code = Gate::forUser($ctx->actor)->check('view', $ctx->site) ? 'not_found' : 'forbidden';
        $message = $code === 'not_found' ? 'Unknown operation.' : 'Not allowed on this site.';

        return $this->finish(
            $ctx,
            $operation,
            $input,
            OperationResult::fail($code, $message, new EditorState(
                siteId: $ctx->site->id,
                pageId: null,
                draftRevisionId: null,
                compositionRevision: 0,
                pendingPublish: false,
            )),
            hrtime(true),
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function mintRequired(
        EditorContext $ctx,
        string $principal,
        string $operation,
        array $input,
        string $sideEffects,
        EditorState $state,
    ): OperationResult {
        $minted = $this->approvals->mint($ctx, $principal, $operation, $input);
        $extra = [
            'operation' => $operation,
            'side_effects' => $sideEffects,
        ];

        if ($minted instanceof MintRefused) {
            $extra[$minted->reason === 'pending_limit' ? 'pending_limit' : 'denied'] = true;

            return OperationResult::fail('approval_required', 'Human approval is required.', $state, $extra);
        }

        return OperationResult::fail('approval_required', 'Human approval is required.', $state, [
            'request_id' => $minted->id,
            'expires_at' => $minted->expires_at->toIso8601String(),
            ...$extra,
        ]);
    }

    private function missingPrincipal(
        string $operation,
        string $sideEffects,
        EditorState $state,
        bool $grant,
    ): OperationResult {
        return OperationResult::fail('approval_required', 'A recognised agent principal is required.', $state, [
            'operation' => $operation,
            'side_effects' => $sideEffects,
            ...($grant ? ['grant' => true] : []),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function continuationCovers(EditorContext $ctx, string $operation, array $input): bool
    {
        return false;
    }

    private function requestPrincipal(EditorContext $ctx): ?string
    {
        if ($ctx->channel !== ActorChannel::Webmcp || ! app()->bound('request')) {
            return null;
        }

        $principal = trim((string) request()->header('X-Editor-Agent-Session'));
        $issued = $principal === '' ? null : Cache::get("editor:agent-session:{$principal}");

        return is_array($issued)
            && ($issued['user_id'] ?? null) === $ctx->actor->getKey()
            && ($issued['site_id'] ?? null) === $ctx->site->getKey()
                ? $principal
                : null;
    }

    /**
     * Accepts ints and canonical integer strings (Front 1 form bodies arrive as strings); rejects bools/floats/other.
     */
    private static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(0|-?[1-9][0-9]*)$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $compositionBefore
     * @param  list<array<string, mixed>>  $selectionsBefore
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>|null}
     */
    private function writeChanges(
        EditorContext $ctx,
        string $operation,
        string $address,
        array $input,
        ?GeneratedPage $page,
        OperationResult $result,
        ?array $compositionBefore,
        array $selectionsBefore,
    ): array {
        $changed = [];
        $newContent = null;
        $compositionAfter = null;
        $baseRevisionId = self::intOrNull($input['revision_base'] ?? null);

        try {
            if ($page !== null && $baseRevisionId !== null && $result->state->draftRevisionId !== null) {
                $before = PageRevision::query()
                    ->where('page_id', $page->id)
                    ->find($baseRevisionId)
                    ?->content_data;
                $newContent = PageRevision::query()
                    ->where('page_id', $page->id)
                    ->find($result->state->draftRevisionId)
                    ?->content_data;

                if (is_array($before) && is_array($newContent)) {
                    $changed = [
                        ...$changed,
                        ...$this->differ->diffContent($before, $newContent, $page->id),
                    ];
                }
            }

            if ($address === 'site') {
                $compositionAfter = SiteDraft::query()
                    ->where('site_id', $ctx->site->id)
                    ->first()
                    ?->composition ?? [];
                $selectionsAfter = $this->draftSelections->all($ctx->site)
                    ->map(fn ($selection): array => $selection->toArray())
                    ->all();

                $changed = [
                    ...$changed,
                    ...$this->differ->diffComposition($compositionBefore ?? [], $compositionAfter),
                    ...$this->differ->diffSelections($selectionsBefore, $selectionsAfter),
                ];
            }

            $changed = [...$changed, ...$ctx->changes->all()];
        } catch (\Throwable $receiptError) {
            // Never silent. Degrading is correct - the write committed and the receipt is only a
            // report on it - but an unreported degrade makes a receipt-assembly bug indistinguishable
            // from "the write genuinely changed nothing", which is the more alarming of the two.
            report($receiptError);
            $changed = [];
        }

        try {
            $effective = $this->effectiveFor(
                $ctx,
                $operation,
                $input,
                $page,
                $result,
                $changed,
                $newContent,
                $compositionAfter,
            );
        } catch (\Throwable $receiptError) {
            // Never silent. Degrading is correct - the write committed and the receipt is only a
            // report on it - but an unreported degrade makes a receipt-assembly bug indistinguishable
            // from "the write genuinely changed nothing", which is the more alarming of the two.
            report($receiptError);
            $effective = null;
        }

        return [$changed, $effective];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<array<string, mixed>>  $changed
     * @param  array<string, mixed>|null  $newContent
     * @param  array<string, mixed>|null  $composition
     * @return array<string, mixed>|null
     */
    private function effectiveFor(
        EditorContext $ctx,
        string $operation,
        array $input,
        ?GeneratedPage $page,
        OperationResult $result,
        array $changed,
        ?array $newContent,
        ?array $composition,
    ): ?array {
        $pageIndexes = [];
        foreach ($changed as $entry) {
            if (($entry['scope'] ?? null) === 'page' && is_int($entry['stored_index'] ?? null)) {
                $pageIndexes[] = $entry['stored_index'];
            }
        }
        $pageIndexes = array_values(array_unique($pageIndexes));

        if ($page !== null && $newContent !== null && $pageIndexes !== []) {
            if (count($pageIndexes) > 5) {
                $ctx->warnings->add(
                    'effective_truncated',
                    'Effective section output was omitted because more than five sections changed.',
                    severity: 'info',
                );

                return null;
            }

            $sections = is_array($newContent['sections'] ?? null) ? array_values($newContent['sections']) : [];
            $pageKind = $this->layouts->layoutKindForPage($page) ?? '';
            $effective = [];
            foreach ($pageIndexes as $storedIndex) {
                $section = $sections[$storedIndex] ?? null;
                if (! is_array($section) || ! is_string($section['type'] ?? null) || $section['type'] === '') {
                    continue;
                }

                $effective[] = $this->sectionDescriber->describe(
                    $section,
                    $pageKind,
                    $storedIndex,
                    array_key_exists($section['type'], config('section_catalog', []))
                        && ! $this->sectionCatalog->isInjectedOnly($section['type']),
                );
            }

            return $effective;
        }

        if ($operation === 'update_brand_theme' && $composition !== null) {
            $ctx->site->loadMissing('businessProfile');
            $profile = $ctx->site->businessProfile?->profile_data ?? [];
            $resolved = $this->themeResolver->resolve(
                $ctx->site,
                is_array($profile) ? $profile : [],
                is_array($composition['theme'] ?? null) ? $composition['theme'] : null,
            );

            return $this->themeResolver->renderTokens($resolved);
        }

        if ($operation === 'set_nav_label' && $composition !== null) {
            return ['items' => array_values(is_array($composition['nav']['items'] ?? null) ? $composition['nav']['items'] : [])];
        }

        if (in_array($operation, ['set_hero_media', 'restore_image_version'], true)
            && ($result->data['scope'] ?? 'hero') === 'hero') {
            $heroPage = $page ?? GeneratedPage::query()
                ->where('site_id', $ctx->site->id)
                ->where('page_type', is_string($input['page_type'] ?? null) ? $input['page_type'] : 'home')
                ->first();

            if ($heroPage !== null) {
                return (array) $this->heroResolution->for($ctx->site->fresh(), $heroPage, true);
            }
        }

        if (in_array($operation, ['set_logo_media', 'restore_image_version', 'select_logo'], true)
            && ($result->data['scope'] ?? 'logo') === 'logo') {
            $concept = $this->draftSelections->logoFor($ctx->site);

            return $concept === null ? null : [
                'concept_id' => $concept->id,
                'url' => $concept->url(),
            ];
        }

        if ($operation === 'update_asset_metadata') {
            $mediaId = self::intOrNull($input['media_id'] ?? null);
            $media = $mediaId === null
                ? null
                : SiteMedia::query()->where('site_id', $ctx->site->id)->find($mediaId);
            $metadata = is_array($media?->metadata) ? $media->metadata : [];

            return $media === null ? null : [
                'media_id' => $media->id,
                'alt_text' => $media->alt_text,
                'caption' => $metadata['caption'] ?? null,
                'attribution' => $metadata['attribution'] ?? null,
                'role' => $metadata['role'] ?? null,
                'focal_point' => $metadata['focal_point'] ?? null,
            ];
        }

        if ($operation === 'upload_image') {
            foreach (array_reverse($changed) as $entry) {
                if (str_starts_with((string) ($entry['path'] ?? ''), 'site_media.')) {
                    return is_array($entry['after'] ?? null) ? $entry['after'] : null;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function finish(
        EditorContext $ctx,
        string $operation,
        array $input,
        OperationResult $result,
        int $started,
        ?GeneratedPage $page = null,
    ): OperationResult {
        if (! $result->ok) {
            $warnings = $result->receipt?->warnings ?? $ctx->warnings->all();
            $result->receipt = ResultReceipt::forRead($warnings);
        } elseif ($result->receipt === null) {
            $result->receipt = ResultReceipt::forRead($ctx->warnings->all());
        }

        $this->recorder->record(
            siteId: $ctx->site->id,
            // Logged from the SITE-SCOPED lookup, not the raw input. Taking `(int) $input['page_id']`
            // meant a forbidden/not_found row could record another tenant's page id (and a non-scalar
            // coerced to 0/1) — an audit row asserting a page association that was never verified.
            pageId: $page?->id,
            actorUserId: $ctx->actor->id,
            channel: $ctx->channel,
            operation: $operation,
            resultCode: $result->ok ? 'ok' : $result->error['code'],
            durationMs: (int) ((hrtime(true) - $started) / 1_000_000),
        );

        return $result;
    }
}
