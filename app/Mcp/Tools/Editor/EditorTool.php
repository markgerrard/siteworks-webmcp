<?php

namespace App\Mcp\Tools\Editor;

use App\Models\Site;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\EditorState;
use App\Services\Site\Editor\ExpectedRevision;
use App\Services\Site\Editor\Operation;
use App\Services\Site\Editor\OperationRegistry;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\Editor\OperationSchemas;
use App\Services\Site\Editor\ResultReceipt;
use App\Services\Site\Editor\RevisionScopes;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

abstract class EditorTool extends Tool
{
    protected const OPERATION = '';

    public function __construct(private readonly OperationRegistry $registry) {}

    public function name(): string
    {
        return 'siteworks.'.static::OPERATION;
    }

    public function description(): string
    {
        return OperationSchemas::description($this->operation());
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        $operation = $this->operation();
        $inputSchema = ExpectedRevision::schema(
            $operation,
            JsonSchemaBridge::normalize($operation->inputSchema()),
        );
        $inputSchema['required'] = array_values(array_unique([
            ...$inputSchema['required'] ?? [],
            'site_id',
        ]));
        $inputSchema['properties'] = [
            'site_id' => [
                'type' => 'integer',
                'description' => 'Site ID to operate on.',
            ],
            ...$inputSchema['properties'] ?? [],
        ];

        if (! $operation->readOnly()) {
            $inputSchema['properties']['include_html'] = [
                'type' => 'boolean',
                'description' => 'Include rendered HTML in successful response data. Defaults to false.',
            ];
        }

        if ((bool) config('editor.agent_approval.enabled')) {
            $inputSchema['properties']['approval_request_id'] = [
                'type' => 'string',
                'format' => 'uuid',
                'description' => 'One-use human approval request id returned by approval_required.',
            ];
        }

        return JsonSchemaBridge::toBuilder($inputSchema, $schema);
    }

    public function handle(Request $request): Response
    {
        $operation = $this->operation();
        $declaredKeys = array_keys(JsonSchemaBridge::normalize($operation->inputSchema())['properties'] ?? []);
        $approvalKeys = (bool) config('editor.agent_approval.enabled') ? ['approval_request_id'] : [];
        $args = Arr::only($request->all(), [...$declaredKeys, ...$approvalKeys, 'site_id', 'include_html', 'expected_revision']);
        // Defensive: an unresolvable site_id must be a typed editor error, not a -32603/500 — and it must not
        // distinguish "no such site" from "not yours" (Layer 0 answers the latter as forbidden).
        $siteId = $args['site_id'] ?? null;
        $site = (is_int($siteId) || (is_string($siteId) && preg_match('/^[1-9][0-9]*$/', $siteId) === 1))
            ? Site::query()->find((int) $siteId)
            : null;

        if ($site === null) {
            return Response::json([
                'ok' => false,
                'error' => ['code' => 'not_found', 'message' => 'Site not found.'],
                'state' => ['site_id' => null, 'page_id' => null, 'draft_revision_id' => null, 'composition_revision' => 0, 'pending_publish' => false, 'structure_epoch' => null],
                'receipt' => ResultReceipt::neutral()->toArray(),
            ]);
        }

        // Exposure refusal (spec § 8) BEFORE the missing-base / schema preflight below:
        // an agent caller must not learn a tool existed but is unexposed for this site — the 422 it
        // would otherwise get here is exactly that oracle. Producing the byte-identical unknown-name
        // refusal here (same code, message, audit row) fixes ORDERING: run() still re-checks as the
        // real boundary. The ui channel never reaches a tool; every tool call is an agent channel.
        $refused = app(EditorOperations::class)->refuseIfUnexposed(
            new EditorContext(Auth::user(), $site, ActorChannel::Mcp, $this->grantPrincipal()),
            static::OPERATION,
            $request->all(),
        );
        if ($refused !== null) {
            return Response::json($refused->toArray());
        }

        // Normalise expected_revision alias before the schema filter — it runs here
        // because handle() drops everything not in declaredKeys at :95.
        $input = $args;
        $normalised = ExpectedRevision::normalise($operation, $input);
        if ($normalised instanceof \App\Services\Site\Editor\OperationResult) {
            return Response::json($normalised->toArray());
        }
        $input = $normalised;

        if (ExpectedRevision::missingBase($operation, $input)) {
            return Response::json(OperationResult::fail(
                'validation',
                RevisionScopes::inputKey($operation->address()).' is required.',
                new EditorState(
                    siteId: $site->id,
                    pageId: null,
                    draftRevisionId: null,
                    compositionRevision: 0,
                    pendingPublish: false,
                    structureEpoch: null,
                ),
            )->toArray());
        }

        $input = Arr::only($input, [...$declaredKeys, ...$approvalKeys]);

        $result = app(EditorOperations::class)->run(
            new EditorContext(Auth::user(), $site, ActorChannel::Mcp, $this->grantPrincipal()),
            static::OPERATION,
            $input,
        )->toArray();

        if (($args['include_html'] ?? false) !== true) {
            unset($result['data']['html']);
        }

        return Response::json($result);
    }

    private function grantPrincipal(): ?string
    {
        $id = Auth::id();

        return $id !== null ? (string) $id : null;
    }

    protected function operation(): Operation
    {
        return $this->registry->get(static::OPERATION);
    }
}
