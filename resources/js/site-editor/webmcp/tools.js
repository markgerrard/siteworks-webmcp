import { getJson, postOperation } from '../editor-api.js';
import { renderAgentView } from './agent-view.js';
import { createApprovalView } from './approvals.js';
import { showImportSummary } from './import-summary.js';
import schemaArtifact from './schemas.json';

const schemas = schemaArtifact.operations;

// Front-2 dispatch maps — how this front addresses an operation (which URL, which
// body shape), not the operation's own nature. Approval and destructiveHint are
// read from the schema artefact; do not re-introduce name lists for those.
const STRUCTURAL_OPS = new Set(['add_section', 'remove_section', 'move_section', 'set_variant']);
const ASSIGNMENT_OPS = new Set(['upload_image']);
const STRUCTURE_OP = {
    add_section: 'add',
    remove_section: 'remove',
    move_section: 'move',
    set_variant: 'set_variant',
};
const URL_KEYS = {
    add_section: 'sectionsUrl',
    edit_field: 'fieldUpdateUrl',
    get_brand_context: 'brandContextUrl',
    get_job_status: 'jobStatusUrl',
    get_page_structure: 'structureUrl',
    list_image_versions: 'imageVersionsUrl',
    move_section: 'sectionsUrl',
    publish_summary: 'publishSummaryUrl',
    remove_section: 'sectionsUrl',
    restore_image_version: 'restoreImageVersionUrl',
    restore_media_version: 'restoreMediaVersionUrl',
    select_logo: 'selectLogoUrl',
    set_variant: 'sectionsUrl',
    update_form: 'formUpdateUrl',
    upload_image: 'mediaUploadUrl',
};
const PAGE_URL_OPS = new Set([
    'add_section',
    'edit_field',
    'get_page_structure',
    'move_section',
    'remove_section',
    'restore_media_version',
    'set_variant',
]);
const WRITE_PREVIEW_SENTENCE = 'Result `receipt.preview` of `unconfirmed` means the edit is saved but the preview may be stale — do not retry; `deferred` means the preview is on another page or being edited.';
const POSITIONAL_APPROVAL_GAP = 'Approval binding for positionally-addressed operations awaits stable section identifiers; this operation is not covered by the approval boundary.';
const ONE_USE_APPROVAL = 'This operation requires a one-use human approval.';

function asInt(value) {
    if (typeof value === 'number' && Number.isInteger(value)) {
        return value;
    }
    if (typeof value === 'string' && /^-?\d+$/.test(value)) {
        return Number(value);
    }

    return null;
}

function urlForPage(templateUrl, pageId) {
    return String(templateUrl ?? '').replace(/\/pages\/\d+\//, `/pages/${pageId}/`);
}

function urlForForm(templateUrl, pageId, sectionIndex) {
    // Both segments MUST be integers: this value is agent-supplied, and a string like
    // "0/../../../../discard-all" would otherwise retarget the POST at another same-origin
    // write endpoint. Callers validate first; this is the second line of defence.
    const index = asInt(sectionIndex);
    const page = asInt(pageId);
    if (index === null || page === null) {
        return null;
    }

    return String(templateUrl ?? '').replace(
        /\/pages\/\d+\/form\/\d+$/,
        `/pages/${page}/form/${index}`,
    );
}

function jobStatusUrlFor(template, jobRef) {
    const encodedRef = encodeURIComponent(String(jobRef ?? ''));

    return String(template ?? '').replace(/\/jobs\/[^/?]+(?=\?|$)/, `/jobs/${encodedRef}`);
}

function operationUrlFor(template, operation) {
    return String(template ?? '').replace('__operation__', encodeURIComponent(operation));
}

function withQuery(url, params) {
    const entries = Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== '');
    if (entries.length === 0) {
        return url;
    }

    const search = new URLSearchParams();
    for (const [key, value] of entries) {
        search.set(key, String(value));
    }

    return `${url}${String(url).includes('?') ? '&' : '?'}${search.toString()}`;
}

function hasAgentTools(config) {
    return Array.isArray(config?.capabilities) && config.capabilities.includes('agent_tools');
}

/**
 * The per-site exposure allowlist from the shell seed (spec § 8 / ruling R1): Front 2 registers
 * only the operations the site's set names. A seed without an allowlist narrows nothing here —
 * the backend exposure check still binds at execution for every call either way.
 */
function exposureAllowlist(config) {
    if (! Array.isArray(config?.agentTools)) {
        return null;
    }

    return new Set(config.agentTools.filter((name) => typeof name === 'string' && name !== ''));
}

function hasAgentApproval(config) {
    return Array.isArray(config?.capabilities) && config.capabilities.includes('agent_approval');
}

function registeredInputSchema(config, schema, inputSchema) {
    if (! hasAgentApproval(config) || schema.requiresApproval !== true) {
        return inputSchema;
    }

    return {
        ...inputSchema,
        properties: {
            ...inputSchema.properties,
            approval_request_id: { type: 'string', format: 'uuid' },
        },
    };
}

function hasAssignmentQuartet(input) {
    return asInt(input?.page_id) !== null
        && input?.stored_index != null
        && typeof input?.field_path === 'string'
        && input.field_path !== '';
}

function receiptWarnings(envelope) {
    return Array.isArray(envelope?.receipt?.warnings) ? envelope.receipt.warnings : [];
}

/**
 * A committed shop write is visible on the page as soon as the tool returns: the products list
 * refreshes on the Livewire event, and the window event lets any other same-page listener react.
 * A committed import also gets its summary panel, rendered from the tool result alone. Nothing here
 * may fail the tool call — the agent has already been told the write succeeded.
 */
function announceCatalogueChange(op, input, data) {
    try {
        window.dispatchEvent(new CustomEvent('siteworks:shop-catalogue-changed', { detail: { op, data } }));
        if (typeof window.Livewire?.dispatch === 'function') {
            window.Livewire.dispatch('shop-catalogue-changed');
        }
    } catch {
        // page-side presentation must never fail the tool call
    }

    if (op !== 'import_products' || input?.dry_run === true || ! Array.isArray(data?.results)) {
        return;
    }
    try {
        showImportSummary({ data });
    } catch {
        // page-side presentation must never fail the tool call
    }
}

export function installWebMCP({ bridge, config, coordinator }) {
    const log = [];
    const registrations = new Map();
    const approvalView = createApprovalView({ config });
    let syncChain = Promise.resolve();
    let lastPageId = config?.pageId ?? null;
    let lastRevision = coordinator?.currentRevision?.(config?.pageId) ?? null;

    function pushLog(entry) {
        log.push(entry);
        if (log.length > 50) {
            log.splice(0, log.length - 50);
        }
    }

    function abortAll() {
        for (const controller of registrations.values()) {
            controller.abort();
        }
        registrations.clear();
    }

    function snapshot() {
        return {
            live: hasAgentTools(config) && registrations.size > 0,
            exposureSet: typeof config?.exposureSet === 'string' ? config.exposureSet : null,
            tools: [...registrations.keys()].map((name) => {
                const op = name.slice('siteworks.'.length);
                const schema = schemas[op];

                return {
                    name,
                    readOnly: schema?.readOnly === true || op === 'navigate_preview',
                };
            }),
            log: log.slice(),
        };
    }

    function refreshView() {
        renderAgentView(snapshot());
    }

    function fail(code, message, pageId = null, extra = {}) {
        return {
            ok: false,
            error: { code, message, ...extra },
            state: {
                site_id: config.siteId ?? null,
                page_id: pageId,
                draft_revision_id: asInt(coordinator.currentRevision?.(pageId)),
                composition_revision: asInt(coordinator.compositionRevision?.()) ?? 0,
                pending_publish: false,
                structure_epoch: asInt(coordinator.currentEpoch?.(pageId)),
            },
            receipt: {
                new_revision: null,
                effective: null,
                changed: [],
                warnings: [],
                publishable: false,
                preview: 'not_applicable',
            },
        };
    }

    function wrap(envelope) {
        try {
            return { content: [{ type: 'text', text: JSON.stringify(envelope) }] };
        } catch {
            return {
                content: [{
                    type: 'text',
                    text: JSON.stringify(fail('internal', 'failed to serialise result')),
                }],
            };
        }
    }

    async function refreshRevisionViaStructure(pageId) {
        if (! config.structureUrl || asInt(pageId) === null) {
            return null;
        }

        try {
            const result = await getJson(config, urlForPage(config.structureUrl, pageId), 'webmcp');
            const revision = asInt(result?.data?.draft_revision_id ?? result?.state?.draft_revision_id);
            const epoch = asInt(result?.data?.structure_epoch ?? result?.state?.structure_epoch);
            const composition = asInt(result?.state?.composition_revision);
            if (revision !== null) {
                coordinator.setRevision?.(pageId, revision);
            }
            if (epoch !== null) {
                coordinator.setEpoch?.(pageId, epoch);
            }
            if (composition !== null) {
                coordinator.setCompositionRevision?.(composition);
            }

            return revision;
        } catch {
            return null;
        }
    }

    function urlFor(op, input, pageId) {
        const urlKey = URL_KEYS[op];
        const template = urlKey === undefined ? config.operationUrl : config[urlKey];
        if (urlKey === undefined) {
            return operationUrlFor(template, op);
        }
        if (op === 'update_form') {
            return urlForForm(template, pageId, input.stored_index);
        }
        if (op === 'get_job_status') {
            return jobStatusUrlFor(template, input.job_ref);
        }
        if (PAGE_URL_OPS.has(op)) {
            return urlForPage(template, pageId);
        }
        if (op === 'list_image_versions') {
            return withQuery(template, {
                scope: input.scope,
                stored_index: input.stored_index,
                page_type: input.page_type,
                slot: input.slot,
                page_id: input.page_id,
                field_path: input.field_path,
            });
        }

        return template;
    }

    function writeBody(op, input) {
        const body = { ...input };
        delete body.revision_base;
        delete body.structure_epoch;
        delete body.composition_revision;
        delete body.catalogue_revision;
        if (STRUCTURAL_OPS.has(op)) {
            body.op = STRUCTURE_OP[op];
        }
        if (op === 'edit_field' && body.stored_index != null && body.section_index == null) {
            body.section_index = body.stored_index;
        }

        return body;
    }

    function writeOptions(address, { revisionBase, structureEpoch, compositionRevision, catalogueRevision }, assignment) {
        if (assignment) {
            const options = { compositionRevision };
            if (revisionBase !== null) {
                options.revisionBase = revisionBase;
            }
            if (structureEpoch !== null) {
                options.structureEpoch = structureEpoch;
            }

            return options;
        }
        if (address === 'site') {
            return { compositionRevision };
        }
        if (address === 'shop') {
            return { catalogueRevision };
        }

        const options = { revisionBase };
        if (structureEpoch !== null) {
            options.structureEpoch = structureEpoch;
        }

        return options;
    }

    /**
     * Strips the marker flag and, when the base was coordinator-derived rather than agent-asserted,
     * re-reads it at dispatch time (post-flush). Falls back to the original if the coordinator has none.
     */
    function dispatchOptions(options, pageId) {
        const { resolveBaseAtDispatch, ...rest } = options;
        if (! resolveBaseAtDispatch || rest.revisionBase === undefined) {
            return rest;
        }

        const current = asInt(coordinator.currentRevision?.(pageId ?? config.pageId));
        const epoch = asInt(coordinator.currentEpoch?.(pageId ?? config.pageId));

        return {
            ...rest,
            revisionBase: current ?? rest.revisionBase,
            ...(rest.structureEpoch !== undefined && epoch !== null ? { structureEpoch: epoch } : {}),
        };
    }

    async function dispatchWrite(op, input, pageId, options) {
        const structural = STRUCTURAL_OPS.has(op);
        if (structural) {
            // Now drains the queue to the server rather than discarding it; a false return means a queued
            // human edit could not be saved, and a structural write would reload the preview over it.
            const drained = await coordinator.dropPendingSaves?.();
            if (drained === false) {
                return fail('editor_busy', 'A pending edit could not be saved; retry once it is resolved', pageId);
            }
        }

        const url = urlFor(op, input, pageId);
        if (url === null) {
            return fail('validation', 'stored_index must be a non-negative integer.', pageId);
        }

        const body = writeBody(op, input);
        const { result, preview } = await coordinator.runExternal({
            pageId: pageId ?? config.pageId,
            structural,
            // runExternal flushes a focused editor's pending save BEFORE calling this, and that commit
            // advances the page revision. A base resolved in executeOperation is therefore pre-flush and
            // 409s every time an agent writes while a human has an uncommitted editor open. Re-read it
            // here, inside the closure, so the base is the post-flush one. An agent that asserted its own
            // revision_base keeps it (options.resolveBaseAtDispatch is false) — a stale explicit base
            // SHOULD 409; that is what optimistic concurrency is for.
            fn: () => postOperation(config, url, body, {
                ...dispatchOptions(options, pageId),
                channel: 'webmcp',
            }),
        });

        // Shop writes bump the catalogue revision and return it (on success in data, on a stale
        // conflict as the current value; import_products names it new_revision). Carry it forward
        // so the next write in this page uses the post-write base instead of the page-load one.
        // A stale conflict's current value is the server's authoritative revision: always adopt it,
        // even downwards, or a bad base would wedge the page. A success value is trusted only when
        // it is above the base: an import dry run merely echoes whatever revision the caller sent
        // (never a post-write value, so it is skipped outright) and an idempotency replay returns
        // the earlier receipt.
        const serverCurrent = asInt(result?.error?.current_catalogue_revision);
        const returned = asInt(result?.data?.catalogue_revision)
            ?? (input?.dry_run === true ? null : asInt(result?.data?.new_revision));
        const currentBase = asInt(coordinator.catalogueRevision?.()) ?? -1;
        if (serverCurrent !== null) {
            coordinator.setCatalogueRevision?.(serverCurrent);
        } else if (returned !== null && returned > currentBase) {
            coordinator.setCatalogueRevision?.(returned);
        }

        if (! result || result.ok === false) {
            return result ?? fail('internal', 'empty result', pageId);
        }

        // The rendered page html stops here. The coordinator has already consumed it for the section swap
        // (runExternal, above), and the tool envelope from this point on is transmitted to the model
        // provider by design — while that html carries one 8-hour signed `editor-preview` URL per nav item,
        // and the signature is the ONLY proof of authorization that route asks for. Both keys are stripped:
        // the legacy field-update route answers with a top-level `html` as well as the envelope's data.html.
        // receipt.html is stripped too — a new envelope key is exactly the shape of change that would
        // quietly undo the no-html-in-envelope pin.
        const { html: _renderedHtml, data: resultData, receipt: resultReceipt, ...envelope } = result;
        const { html: _envelopeHtml, ...data } = resultData ?? {};
        const { html: _receiptHtml, ...receipt } = resultReceipt ?? {};

        if (schemas[op]?.address === 'shop') {
            announceCatalogueChange(op, input, data);
        }

        return {
            ...envelope,
            data,
            receipt: {
                ...receipt,
                preview,
            },
        };
    }

    async function dispatchRead(op, input, pageId) {
        try {
            const url = urlFor(op, input, pageId);
            if (url === null) {
                return fail('validation', 'stored_index must be a non-negative integer.', pageId);
            }

            if (URL_KEYS[op] === undefined) {
                return await postOperation(config, url, input, { channel: 'webmcp' });
            }

            return await getJson(config, url, 'webmcp');
        } catch (error) {
            return fail('internal', error?.message || 'read failed', pageId);
        }
    }

    /**
     * A person's publish from the products list moves the catalogue revision without a tool call,
     * so the base the bridge carries falls behind and the agent's next write would be refused as
     * stale once before a retry landed. The list announces every catalogue change it makes on the
     * same Livewire event an agent write announces; on that event the bridge re-reads the revision
     * so the next write carries the current base. A read announces nothing, so this cannot loop.
     */
    async function refreshCatalogueRevision() {
        if (typeof coordinator.setCatalogueRevision !== 'function' || ! schemas.get_site_context) {
            return null;
        }
        const result = await dispatchRead('get_site_context', {}, null);
        const revision = asInt(result?.data?.catalogue_revision);
        if (revision !== null) {
            coordinator.setCatalogueRevision(revision);
        }

        return revision;
    }

    function listenForCatalogueChanges() {
        const subscribe = () => {
            window.Livewire?.on?.('shop-catalogue-changed', () => {
                refreshCatalogueRevision().catch(() => null);
            });
        };
        if (typeof window.Livewire?.on === 'function') {
            subscribe();
        } else {
            document.addEventListener('livewire:init', subscribe, { once: true });
        }
    }

    async function executeOperation(op, schema, input = {}) {
        const pageId = asInt(input.page_id) ?? asInt(config.pageId);
        const address = schema.address ?? 'page';
        const readOnly = schema.readOnly === true;

        if (! readOnly && address === 'page') {
            const assertedBase = asInt(input.revision_base)
                ?? (input.expected_revision !== undefined ? asInt(input.expected_revision) : null);
            const revisionBase = assertedBase
                ?? asInt(coordinator.currentRevision?.(pageId))
                ?? await refreshRevisionViaStructure(pageId);
            if (revisionBase === null) {
                return fail('stale_revision', 'no revision base', pageId);
            }

            return dispatchWrite(op, input, pageId, {
                ...writeOptions(address, {
                    revisionBase,
                    structureEpoch: asInt(input.structure_epoch) ?? asInt(coordinator.currentEpoch?.(pageId)),
                    compositionRevision: null,
                }, false),
                resolveBaseAtDispatch: assertedBase === null,
            });
        }

        if (! readOnly && address === 'site') {
            const compositionRevision = asInt(input.composition_revision)
                ?? (input.expected_revision !== undefined ? asInt(input.expected_revision) : null)
                ?? asInt(coordinator.compositionRevision?.());
            if (compositionRevision === null) {
                return fail('stale_revision', 'no revision base', pageId);
            }

            const assignment = ASSIGNMENT_OPS.has(op) && hasAssignmentQuartet(input);
            const assertedBase = asInt(input.revision_base);
            let revisionBase = assertedBase;
            let structureEpoch = asInt(input.structure_epoch);
            if (assignment) {
                revisionBase = revisionBase
                    ?? asInt(coordinator.currentRevision?.(pageId))
                    ?? await refreshRevisionViaStructure(pageId);
                structureEpoch = structureEpoch ?? asInt(coordinator.currentEpoch?.(pageId));
            }

            return dispatchWrite(op, input, pageId, {
                ...writeOptions(address, {
                    revisionBase,
                    structureEpoch,
                    compositionRevision,
                }, assignment),
                resolveBaseAtDispatch: assignment && assertedBase === null,
            });
        }

        if (! readOnly && address === 'shop') {
            const catalogueRevision = asInt(input.catalogue_revision)
                ?? (input.expected_revision !== undefined ? asInt(input.expected_revision) : null)
                ?? asInt(coordinator.catalogueRevision?.());
            if (catalogueRevision === null) {
                return fail('stale_revision', 'no revision base', pageId);
            }

            return dispatchWrite(op, input, pageId, {
                ...writeOptions(address, { catalogueRevision }, false),
                resolveBaseAtDispatch: false,
            });
        }

        return dispatchRead(op, input, pageId);
    }

    async function executeNavigate(input = {}) {
        const pageId = asInt(input.page_id);
        if (pageId === null) {
            return fail('validation', 'page_id is required');
        }

        try {
            const nav = await coordinator.navigateTo(pageId, 'webmcp');
            const revisionId = asInt(nav?.revisionId);
            if (revisionId !== null) {
                coordinator.setRevision?.(pageId, revisionId);
            }
            const resolvedPageId = asInt(nav?.pageId) ?? pageId;

            return {
                ok: true,
                data: {
                    page_id: resolvedPageId,
                    draft_revision_id: revisionId,
                },
                state: {
                    site_id: config.siteId ?? null,
                    page_id: resolvedPageId,
                    draft_revision_id: revisionId,
                    composition_revision: asInt(coordinator.compositionRevision?.()) ?? 0,
                    pending_publish: false,
                    structure_epoch: asInt(coordinator.currentEpoch?.(resolvedPageId)),
                },
            };
        } catch (error) {
            const code = error?.name === 'EditorBusyError' ? 'editor_busy' : 'internal';

            return fail(code, error?.message || 'preview navigation failed; retry after navigate_preview', pageId);
        }
    }

    function toolDescription(schema) {
        let description;
        if (typeof schema.description === 'string' && schema.description !== '') {
            description = schema.description;
        } else {
            const sideEffects = schema.sideEffects ?? '';
            description = schema.readOnly || sideEffects.includes(WRITE_PREVIEW_SENTENCE)
                ? sideEffects
                : `${sideEffects} ${WRITE_PREVIEW_SENTENCE}`.trim();
        }

        if (! hasAgentApproval(config)) {
            return description;
        }

        const boundarySentence = schema.positionalApprovalGap === true
            ? POSITIONAL_APPROVAL_GAP
            : schema.requiresApproval === true ? ONE_USE_APPROVAL : null;

        return boundarySentence === null || description.includes(boundarySentence)
            ? description
            : `${description} ${boundarySentence}`;
    }

    function register(mc, def) {
        const controller = new AbortController();
        registrations.set(def.name, controller);
        // The WebMCP entry point: tools are registered on the page's model context so an
        // agent in the browser can call them. navigator.modelContext is the fallback host.
        if (typeof document !== 'undefined' && document.modelContext && mc === document.modelContext) {
            document.modelContext.registerTool(def, { signal: controller.signal });
        } else {
            mc.registerTool(def, { signal: controller.signal });
        }
    }

    async function syncTools() {
        if (! hasAgentTools(config)) {
            abortAll();
            refreshView();

            return;
        }

        const mc = document.modelContext ?? navigator.modelContext;
        if (! mc?.registerTool) {
            return;
        }

        abortAll();

        const allowed = exposureAllowlist(config);
        for (const [op, schema] of Object.entries(schemas)) {
            if (allowed !== null && ! allowed.has(op)) {
                continue;
            }
            // Conservative: portal-shop has no mediaUploadUrl and the client
            // upload gate is a separate residual. Do not advertise a tool a
            // client cannot safely use yet. Shop-admin is unchanged.
            if (config.surface === 'portal-shop' && op === 'upload_image') {
                continue;
            }

            const name = `siteworks.${op}`;
            register(mc, {
                name,
                description: toolDescription(schema),
                inputSchema: registeredInputSchema(config, schema, schema.inputSchema),
                annotations: {
                    readOnlyHint: schema.readOnly === true,
                    destructiveHint: schema.destructive === true,
                },
                execute: async (input) => {
                    try {
                        const envelope = await executeOperation(op, schema, input ?? {});
                        pushLog({
                            name,
                            ok: envelope?.ok === true,
                            code: envelope?.error?.code ?? null,
                            warnings: receiptWarnings(envelope),
                        });
                        try {
                            approvalView.noticeEnvelope(envelope);
                        } catch {
                            // view failure must never fail the tool call
                        }
                        refreshView();

                        return wrap(envelope);
                    } catch (error) {
                        // spec § 3.3: a pre-dispatch busy editor is retryable, not an internal fault.
                        const envelope = fail(
                            error?.name === 'EditorBusyError' ? 'editor_busy' : 'internal',
                            error?.message || 'operation failed',
                        );
                        pushLog({
                            name,
                            ok: false,
                            code: envelope.error.code,
                            warnings: receiptWarnings(envelope),
                        });

                        try {
                            approvalView.noticeEnvelope(envelope);
                        } catch {
                            // view failure must never fail the tool call
                        }
                        refreshView();

                        return wrap(envelope);
                    }
                },
            });
        }

        register(mc, {
            name: 'siteworks.navigate_preview',
            description: 'Navigates the editor preview to another page of this site. Returns page_id and draft_revision_id. Retryable if the preview is busy.',
            inputSchema: {
                type: 'object',
                required: ['page_id'],
                properties: {
                    page_id: { type: 'integer' },
                },
            },
            annotations: {
                readOnlyHint: true,
                destructiveHint: false,
            },
            execute: async (input) => {
                try {
                    const envelope = await executeNavigate(input ?? {});
                    pushLog({
                        name: 'siteworks.navigate_preview',
                        ok: envelope?.ok === true,
                        code: envelope?.error?.code ?? null,
                        warnings: receiptWarnings(envelope),
                    });
                    try {
                        approvalView.noticeEnvelope(envelope);
                    } catch {
                        // view failure must never fail the tool call
                    }
                    refreshView();

                    return wrap(envelope);
                } catch (error) {
                    const envelope = fail(
                        error?.name === 'EditorBusyError' ? 'editor_busy' : 'internal',
                        error?.message || 'operation failed',
                    );
                    pushLog({
                        name: 'siteworks.navigate_preview',
                        ok: false,
                        code: envelope.error.code,
                        warnings: receiptWarnings(envelope),
                    });

                    try {
                        approvalView.noticeEnvelope(envelope);
                    } catch {
                        // view failure must never fail the tool call
                    }
                    refreshView();

                    return wrap(envelope);
                }
            },
        });

        refreshView();
    }

    function sync() {
        const run = syncChain.then(() => syncTools(), () => syncTools());
        syncChain = run.then(() => undefined, () => undefined);

        return run;
    }

    window.__siteworks_webmcp__ = { sync, log };

    if (config?.surface === 'shop-admin' || config?.surface === 'portal-shop') {
        listenForCatalogueChanges();
    }

    bridge?.on?.('ready', (payload) => {
        const pageId = payload?.pageId ?? null;
        const revisionId = payload?.revisionId ?? null;
        const pageChanged = pageId != null && String(pageId) !== String(lastPageId);
        const revisionChanged = revisionId != null && String(revisionId) !== String(lastRevision);
        if (pageChanged) {
            lastPageId = pageId;
        }
        if (revisionChanged) {
            lastRevision = revisionId;
        }
        if (pageChanged || revisionChanged) {
            sync();
        }
    });

    refreshView();
    sync();

    return { sync, log, registrations };
}
