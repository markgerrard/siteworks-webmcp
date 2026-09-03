<?php

namespace App\Http\Controllers\Site\Editor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Site\Editor\DraftProductRequest;
use App\Http\Requests\Site\Editor\EditorOperationRequest;
use App\Http\Requests\Site\Editor\GetBrandContextRequest;
use App\Http\Requests\Site\Editor\GetJobStatusRequest;
use App\Http\Requests\Site\Editor\GetPageStructureRequest;
use App\Http\Requests\Site\Editor\GetProductRequest;
use App\Http\Requests\Site\Editor\ListImageVersionsRequest;
use App\Http\Requests\Site\Editor\ListProductsRequest;
use App\Http\Requests\Site\Editor\PreviewUrlRequest;
use App\Http\Requests\Site\Editor\RestoreImageVersionRequest;
use App\Http\Requests\Site\Editor\RestoreMediaVersionRequest;
use App\Http\Requests\Site\Editor\SectionsRequest;
use App\Http\Requests\Site\Editor\SelectLogoRequest;
use App\Http\Requests\Site\Editor\SetProductImageRequest;
use App\Http\Requests\Site\Editor\UpdateDraftProductRequest;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\AgentToolsGate;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\ExpectedRevision;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\Editor\OperationRegistry;
use App\Services\Site\Editor\ResultReceipt;
use App\Support\EditorParentOrigin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;

final class EditorOperationController extends Controller
{
    /**
     * @var array<string, array{operation: string|null, request: class-string<EditorOperationRequest>, address: 'page'|'site'|'shop', write: bool}>
     */
    private const ROUTES = [
        'site.editor.preview-url' => ['operation' => null, 'request' => PreviewUrlRequest::class, 'address' => 'page', 'write' => false],
        'site.editor.structure' => ['operation' => 'get_page_structure', 'request' => GetPageStructureRequest::class, 'address' => 'page', 'write' => false],
        'site.editor.brand-context' => ['operation' => 'get_brand_context', 'request' => GetBrandContextRequest::class, 'address' => 'site', 'write' => false],
        'site.editor.image-versions' => ['operation' => 'list_image_versions', 'request' => ListImageVersionsRequest::class, 'address' => 'site', 'write' => false],
        'site.editor.job-status' => ['operation' => 'get_job_status', 'request' => GetJobStatusRequest::class, 'address' => 'site', 'write' => false],
        'site.editor.sections' => ['operation' => null, 'request' => SectionsRequest::class, 'address' => 'page', 'write' => true],
        'site.editor.select-logo' => ['operation' => 'select_logo', 'request' => SelectLogoRequest::class, 'address' => 'site', 'write' => true],
        'site.editor.restore-image-version' => ['operation' => 'restore_image_version', 'request' => RestoreImageVersionRequest::class, 'address' => 'site', 'write' => true],
        'site.editor.restore-media-version' => ['operation' => 'restore_media_version', 'request' => RestoreMediaVersionRequest::class, 'address' => 'page', 'write' => true],
        'site.editor.list-products' => ['operation' => 'list_products', 'request' => ListProductsRequest::class, 'address' => 'shop', 'write' => false],
        'site.editor.get-product' => ['operation' => 'get_product', 'request' => GetProductRequest::class, 'address' => 'shop', 'write' => false],
        'site.editor.draft-product' => ['operation' => 'draft_product', 'request' => DraftProductRequest::class, 'address' => 'shop', 'write' => true],
        'site.editor.update-draft-product' => ['operation' => 'update_draft_product', 'request' => UpdateDraftProductRequest::class, 'address' => 'shop', 'write' => true],
        'site.editor.set-product-image' => ['operation' => 'set_product_image', 'request' => SetProductImageRequest::class, 'address' => 'shop', 'write' => true],
    ];

    /**
     * A caller may DECLARE itself agent-driven with X-Editor-Channel: webmcp. That can only make the gate
     * stricter (webmcp additionally requires the actor's role) and makes the audit row honest — a browser
     * agent's write is no longer logged as a human UI edit. Anything else is the UI channel.
     */
    private static function channelFor(Request $request): ActorChannel
    {
        return trim((string) $request->header('X-Editor-Channel')) === 'webmcp'
            ? ActorChannel::Webmcp
            : ActorChannel::Ui;
    }

    private const NEUTRAL_STATE = ['site_id' => null, 'page_id' => null, 'draft_revision_id' => null, 'composition_revision' => 0, 'pending_publish' => false, 'structure_epoch' => null];

    public function __construct(
        private readonly EditorOperations $operations,
        private readonly AgentToolsGate $gate,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $routeName = (string) $request->route()?->getName();
        $route = self::ROUTES[$routeName] ?? abort(404);
        $siteModel = Site::query()->findOrFail((int) $request->route('site'));

        // Exposure refusal (spec § 8, ruling R1) BEFORE the ability check and BEFORE any preflight:
        // an agent caller must not learn that an operation exists but is not exposed for this site —
        // a 409 (missing base) or 422 (bad body) returned to such a caller would be exactly that
        // oracle, and it would also hide the exposure set. Producing the byte-identical unknown-name
        // refusal here (same code, message, and audit row) fixes ORDERING, not enforcement: run()
        // re-checks on every call that lands in it. The ability check is unaffected for exposed ops.
        $channel = self::channelFor($request);
        $operation = $route['operation'];
        if ($operation === null && $routeName === 'site.editor.sections') {
            $operation = match ($request->input('op')) {
                'add' => 'add_section',
                'remove' => 'remove_section',
                'move' => 'move_section',
                'set_variant' => 'set_variant',
                default => null,
            };
        }

        if ($operation !== null && $channel !== ActorChannel::Ui) {
            $refused = $this->operations->refuseIfUnexposed(
                new EditorContext($request->user(), $siteModel, $channel, $this->grantPrincipal($request, $siteModel)),
                $operation,
                $request->all(),
            );
            if ($refused !== null) {
                return response()->json($refused->toArray(), self::statusFor($refused));
            }
        }

        // Authorise BEFORE any preflight/validation answer: a 409 (missing base) or 422 (bad body) returned
        // to an unauthorised caller is a site-existence oracle. Layer 0 re-checks; this only fixes ordering.
        if (! Gate::forUser($request->user())->check($route['write'] ? 'update' : 'view', $siteModel)) {
            return response()->json([
                'ok' => false,
                'error' => ['code' => 'forbidden', 'message' => 'Not allowed on this site.'],
                'state' => ['site_id' => $siteModel->id] + self::NEUTRAL_STATE,
                'receipt' => ResultReceipt::neutral()->toArray(),
            ], 403);
        }

        if ($route['operation'] === null && $routeName === 'site.editor.preview-url') {
            return $this->previewUrl($request, $siteModel, (int) $request->route('page'), $route['request']);
        }

        $revisionKey = match ($route['address']) {
            'site' => 'composition_revision',
            'shop' => 'catalogue_revision',
            default => 'revision_base',
        };

        if (
            $route['write']
            && ! $request->exists('expected_revision')
            && ! $request->exists($revisionKey)
            && ($route['address'] !== 'page' || self::positiveIntOrNull($request->header('X-Page-Revision-Base')) === null)
        ) {
            return self::revisionBaseRequired();
        }

        $validatedRequest = $this->validatedRequest($request, $route['request']);
        $input = $validatedRequest->validated();
        $operation = $route['operation'];

        if ($routeName === 'site.editor.sections') {
            $operation = match ($input['op']) {
                'add' => 'add_section',
                'remove' => 'remove_section',
                'move' => 'move_section',
                'set_variant' => 'set_variant',
            };
            unset($input['op']);
        }

        $operationInstance = app(OperationRegistry::class)->get($operation);

        if ($route['write'] && $route['address'] === 'page') {
            $headerRevision = self::positiveIntOrNull($request->header('X-Page-Revision-Base'));
            if ($headerRevision !== null) {
                $input['revision_base'] = $headerRevision;
            }
        }

        $normalised = ExpectedRevision::normalise($operationInstance, $input);
        if ($normalised instanceof OperationResult) {
            return response()->json($normalised->toArray(), 422);
        }
        $input = $normalised;

        if ($route['write'] && ExpectedRevision::missingBase($operationInstance, $input)) {
            return self::revisionBaseRequired();
        }

        $input['parent_origin'] = EditorParentOrigin::fromRequest($request);

        $result = $this->operations->run(
            new EditorContext(
                $request->user(),
                $siteModel,
                self::channelFor($request),
                $this->grantPrincipal($request, $siteModel),
            ),
            $operation,
            $input,
        );

        return response()->json($result->toArray(), self::statusFor($result));
    }

    public function operation(Request $request, Site $site, string $operation): JsonResponse
    {
        $input = $request->except('include_changes');
        $input['parent_origin'] = EditorParentOrigin::fromRequest($request);

        $result = $this->operations->run(
            new EditorContext(
                $request->user(),
                $site,
                self::channelFor($request),
                $this->grantPrincipal($request, $site),
                includeChanges: $request->boolean('include_changes'),
            ),
            $operation,
            $input,
        );

        return response()->json($result->toArray(), self::statusFor($result));
    }

    public static function statusFor(OperationResult $result): int
    {
        if ($result->ok) {
            return 200;
        }

        return match ($result->error['code']) {
            'stale_revision', 'revision_conflict', 'plan_stale', 'job_running', 'editor_busy' => 409,
            'forbidden', 'approval_required' => 403,
            'not_found' => 404,
            'validation', 'unsupported_field' => 422,
            'quota_exceeded' => 429,
            'internal' => 500,
        };
    }

    private function grantPrincipal(Request $request, Site $site): ?string
    {
        if (self::channelFor($request) !== ActorChannel::Webmcp) {
            return null;
        }

        $principal = trim((string) $request->header('X-Editor-Agent-Session'));
        $issued = $principal === '' ? null : Cache::get("editor:agent-session:{$principal}");

        return is_array($issued)
            && ($issued['user_id'] ?? null) === $request->user()?->getKey()
            && ($issued['site_id'] ?? null) === $site->getKey()
                ? $principal
                : null;
    }

    public static function revisionBaseRequired(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => [
                'code' => 'stale_revision',
                'message' => 'revision base required',
            ],
        ], 409);
    }

    /**
     * @param  class-string<EditorOperationRequest>  $requestClass
     */
    private function previewUrl(Request $request, Site $site, int $page, string $requestClass): JsonResponse
    {
        $this->authorize('view', $site);

        if (! $this->gate->enabledFor($request->user(), self::channelFor($request))) {
            abort(403);
        }

        $this->validatedRequest($request, $requestClass);
        $pageModel = GeneratedPage::query()
            ->where('site_id', $site->id)
            ->whereNull('archived_at')
            ->findOrFail($page);

        return response()->json([
            'url' => URL::temporarySignedRoute(
                'editor-preview.show',
                now()->addHours(8),
                [
                    'site' => $site->id,
                    'page' => $pageModel->id,
                    'parent_origin' => EditorParentOrigin::fromRequest($request),
                ],
            ),
        ]);
    }

    /**
     * @param  class-string<EditorOperationRequest>  $requestClass
     */
    private function validatedRequest(Request $request, string $requestClass): EditorOperationRequest
    {
        return $requestClass::fromRequest($request);
    }

    private static function positiveIntOrNull(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
