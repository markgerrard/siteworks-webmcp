import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { installWebMCP } from '../index.js';
import schemaArtifact from '../schemas.json';

const originalFetch = globalThis.fetch;
const originalModelContext = Object.getOwnPropertyDescriptor(Document.prototype, 'modelContext')
    ?? Object.getOwnPropertyDescriptor(document, 'modelContext');

function makeConfig(overrides = {}) {
    return {
        siteId: 9,
        pageId: 5,
        csrfToken: 'csrf-token',
        capabilities: ['edit', 'publish', 'media', 'agent_tools'],
        compositionRevision: 7,
        currentRevisionIds: { 5: 20 },
        structureEpochs: { 5: 2 },
        fieldUpdateUrl: '/sites/9/pages/0/fields',
        structureUrl: '/sites/9/pages/0/structure',
        sectionsUrl: '/sites/9/pages/0/sections',
        selectLogoUrl: '/sites/9/logo/select',
        brandContextUrl: '/sites/9/brand-context',
        ...overrides,
    };
}

function makeCoordinator(overrides = {}) {
    return {
        currentRevision: vi.fn(() => 20),
        currentEpoch: vi.fn(() => 2),
        compositionRevision: vi.fn(() => 7),
        setRevision: vi.fn(),
        setEpoch: vi.fn(),
        setCompositionRevision: vi.fn(),
        dropPendingSaves: vi.fn(),
        navigateTo: vi.fn(async (pageId) => ({ pageId, revisionId: 20 })),
        runExternal: vi.fn(async ({ fn }) => ({ result: await fn(), preview: 'applied' })),
        ...overrides,
    };
}

function makeBridge() {
    const handlers = new Map();

    return {
        on: vi.fn((type, handler) => {
            const list = handlers.get(type) ?? [];
            list.push(handler);
            handlers.set(type, list);
        }),
        emit(type, payload, meta = {}) {
            for (const handler of handlers.get(type) ?? []) {
                handler(payload, meta);
            }
        },
    };
}

function installFakeModelContext() {
    const tools = new Map();
    const aborted = [];
    const modelContext = {
        registerTool(def, options = {}) {
            tools.set(def.name, def);
            options.signal?.addEventListener('abort', () => {
                aborted.push(def.name);
                tools.delete(def.name);
            });
        },
    };

    Object.defineProperty(document, 'modelContext', {
        configurable: true,
        writable: true,
        value: modelContext,
    });

    return { tools, aborted, modelContext };
}

function parseEnvelope(response) {
    expect(response).toEqual({
        content: [{ type: 'text', text: expect.any(String) }],
    });

    return JSON.parse(response.content[0].text);
}

let fetchMock;
let bridge;
let config;
let coordinator;
let tools;
let aborted;

beforeEach(() => {
    document.body.replaceChildren();
    fetchMock = vi.fn(async () => ({
        ok: true,
        status: 200,
        json: async () => ({
            ok: true,
            data: {},
            state: {
                site_id: 9,
                page_id: 5,
                draft_revision_id: 21,
                composition_revision: 8,
                pending_publish: true,
                structure_epoch: 2,
            },
        }),
    }));
    globalThis.fetch = fetchMock;
    bridge = makeBridge();
    config = makeConfig();
    coordinator = makeCoordinator();
    ({ tools, aborted } = installFakeModelContext());
});

afterEach(() => {
    delete schemaArtifact.operations.future_front_probe;
    delete schemaArtifact.operations.future_write_probe;
    globalThis.fetch = originalFetch;
    if (originalModelContext) {
        Object.defineProperty(document, 'modelContext', originalModelContext);
    } else {
        delete document.modelContext;
    }
});

test('an operation without a v1 URL mapping resolves through the generic POST endpoint', async () => {
    schemaArtifact.operations.future_front_probe = {
        readOnly: true,
        address: 'site',
        sideEffects: 'Returns a fixed probe payload.',
        inputSchema: {
            type: 'object',
            additionalProperties: false,
            properties: {
                marker: { type: 'string' },
            },
            required: ['marker'],
        },
    };
    config = makeConfig({ operationUrl: '/sites/9/operations/__operation__' });
    installWebMCP({ bridge, config, coordinator });
    await window.__siteworks_webmcp__.sync();

    const response = await tools.get('siteworks.future_front_probe').execute({
        marker: 'front-2-input-619',
    });

    expect(parseEnvelope(response).ok).toBe(true);
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock.mock.calls[0][0]).toBe('/sites/9/operations/future_front_probe');
    expect(fetchMock.mock.calls[0][1]).toEqual({
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': 'csrf-token',
            'X-Editor-Channel': 'webmcp',
        },
        body: JSON.stringify({ marker: 'front-2-input-619' }),
    });
});

test('an unmapped write resolves through the generic POST endpoint with its body and options', async () => {
    schemaArtifact.operations.future_write_probe = {
        readOnly: false,
        address: 'site',
        sideEffects: 'Records a fixed probe payload.',
        inputSchema: {
            type: 'object',
            additionalProperties: false,
            properties: {
                marker: { type: 'string' },
                composition_revision: { type: 'integer' },
            },
            required: ['marker'],
        },
    };
    config = makeConfig({ operationUrl: '/sites/9/operations/__operation__' });
    installWebMCP({ bridge, config, coordinator });
    await window.__siteworks_webmcp__.sync();

    const response = await tools.get('siteworks.future_write_probe').execute({
        marker: 'front-2-write-input-823',
    });

    expect(parseEnvelope(response).ok).toBe(true);
    expect(coordinator.runExternal).toHaveBeenCalledWith({
        pageId: 5,
        structural: false,
        fn: expect.any(Function),
    });
    expect(fetchMock).toHaveBeenCalledTimes(1);

    // Independent oracle: these literals are the generic route contract and postOperation wire format.
    // Wrong implementation caught: fallback exists only in dispatchRead, leaving writes on an undefined URL.
    expect(fetchMock.mock.calls[0]).toEqual([
        '/sites/9/operations/future_write_probe',
        {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': 'csrf-token',
                'X-Editor-Channel': 'webmcp',
            },
            body: JSON.stringify({
                marker: 'front-2-write-input-823',
                composition_revision: 7,
            }),
        },
    ]);
});

test('install exposes sync before modelContext exists so a post-load fake can register', async () => {
    delete document.modelContext;
    installWebMCP({ bridge, config, coordinator });

    expect(window.__siteworks_webmcp__).toEqual({
        sync: expect.any(Function),
        log: expect.any(Array),
    });
    expect(tools.size).toBe(0);

    ({ tools, aborted } = installFakeModelContext());
    await window.__siteworks_webmcp__.sync();

    expect(tools.has('siteworks.edit_field')).toBe(true);
});

test('registers siteworks.edit_field with readOnlyHint false', async () => {
    installWebMCP({ bridge, config, coordinator });
    await window.__siteworks_webmcp__.sync();

    const tool = tools.get('siteworks.edit_field');
    expect(tool).toBeTruthy();
    expect(tool.annotations.readOnlyHint).toBe(false);
    expect(tool.annotations.destructiveHint).toBe(false);
});

test('Front 2 advertises and honours an alias-only asserted revision', async () => {
    installWebMCP({ bridge, config, coordinator });
    await window.__siteworks_webmcp__.sync();

    const tool = tools.get('siteworks.edit_field');
    expect(tool.inputSchema.properties.expected_revision).toEqual({
        type: 'integer',
        description: 'Alias for revision_base. At least one of expected_revision and revision_base is required.',
    });
    expect(tool.inputSchema.required ?? []).not.toContain('revision_base');

    const response = await tool.execute({
        page_id: 5,
        stored_index: 0,
        field_path: 'title',
        value: 'Alias-only write',
        expected_revision: 19,
    });

    // Wrong implementation this catches: tools.js substitutes coordinator revision 20 or the
    // generated schema still requires revision_base, hiding alias-only calls from agents.
    expect(parseEnvelope(response).ok).toBe(true);
    expect(fetchMock.mock.calls[0][1].headers['X-Page-Revision-Base']).toBe('19');
    expect(JSON.parse(fetchMock.mock.calls[0][1].body).expected_revision).toBe(19);
});

test('registers get_page_structure with readOnlyHint true', async () => {
    installWebMCP({ bridge, config, coordinator });
    await window.__siteworks_webmcp__.sync();

    const tool = tools.get('siteworks.get_page_structure');
    expect(tool.annotations.readOnlyHint).toBe(true);
    expect(tool.annotations.destructiveHint).toBe(false);
});

test('registers remove_section with destructiveHint true', async () => {
    installWebMCP({ bridge, config, coordinator });
    await window.__siteworks_webmcp__.sync();

    const tool = tools.get('siteworks.remove_section');
    expect(tool.annotations.readOnlyHint).toBe(false);
    expect(tool.annotations.destructiveHint).toBe(true);
});

test('a page write with no resolvable base returns stale_revision and makes no fetch', async () => {
    coordinator.currentRevision.mockReturnValue(null);
    config = makeConfig({ structureUrl: undefined });
    installWebMCP({ bridge, config, coordinator });
    await window.__siteworks_webmcp__.sync();

    const response = await tools.get('siteworks.edit_field').execute({
        page_id: 5,
        stored_index: 0,
        field_path: 'title',
        value: 'Agent title',
    });
    const envelope = parseEnvelope(response);

    expect(envelope).toEqual({
        ok: false,
        error: { code: 'stale_revision', message: 'no revision base' },
        state: {
            site_id: 9,
            page_id: 5,
            draft_revision_id: null,
            composition_revision: 7,
            pending_publish: false,
            structure_epoch: 2,
        },
        receipt: {
            new_revision: null,
            effective: null,
            changed: [],
            warnings: [],
            publishable: false,
            preview: 'not_applicable',
        },
    });
    expect(fetchMock).not.toHaveBeenCalled();
    expect(coordinator.runExternal).not.toHaveBeenCalled();
});

test('select_logo sends composition_revision from the coordinator and none of the page keys', async () => {
    installWebMCP({ bridge, config, coordinator });
    await window.__siteworks_webmcp__.sync();

    const response = await tools.get('siteworks.select_logo').execute({ concept_id: 42 });
    const envelope = parseEnvelope(response);

    expect(envelope.ok).toBe(true);
    expect(envelope.receipt.preview).toBe('applied');
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock.mock.calls[0][0]).toBe('/sites/9/logo/select');

    const init = fetchMock.mock.calls[0][1];
    expect(init.headers['X-Page-Revision-Base']).toBeUndefined();
    expect(JSON.parse(init.body)).toEqual({
        concept_id: 42,
        composition_revision: 7,
    });
    expect(coordinator.runExternal).toHaveBeenCalledWith({
        pageId: 5,
        structural: false,
        fn: expect.any(Function),
    });
});

test('select_logo with no resolvable composition revision returns stale_revision with no fetch', async () => {
    coordinator.compositionRevision.mockReturnValue(null);
    installWebMCP({ bridge, config, coordinator });
    await window.__siteworks_webmcp__.sync();

    const response = await tools.get('siteworks.select_logo').execute({ concept_id: 42 });
    const envelope = parseEnvelope(response);

    expect(envelope).toEqual({
        ok: false,
        error: { code: 'stale_revision', message: 'no revision base' },
        state: {
            site_id: 9,
            page_id: 5,
            draft_revision_id: 20,
            composition_revision: 0,
            pending_publish: false,
            structure_epoch: 2,
        },
        receipt: {
            new_revision: null,
            effective: null,
            changed: [],
            warnings: [],
            publishable: false,
            preview: 'not_applicable',
        },
    });
    expect(fetchMock).not.toHaveBeenCalled();
    expect(coordinator.runExternal).not.toHaveBeenCalled();
});

test('flag removal aborts every registration', async () => {
    installWebMCP({ bridge, config, coordinator });
    await window.__siteworks_webmcp__.sync();

    expect(tools.has('siteworks.edit_field')).toBe(true);
    expect(tools.has('siteworks.navigate_preview')).toBe(true);
    const names = [...tools.keys()];
    expect(names.length).toBeGreaterThan(1);

    config.capabilities = ['edit', 'publish', 'media'];
    await window.__siteworks_webmcp__.sync();

    expect(tools.size).toBe(0);
    for (const name of names) {
        expect(aborted).toContain(name);
    }
});

/*
 * Spec § 8 / ruling R1: the shell seed carries a per-site allowlist, and Front 2 registers only
 * those operations — the registered surface equals the reachable surface for the tenant. The fake
 * modelContext registry is the oracle; a wrong implementation registers every schemas.json entry.
 */
test('an operation outside the seeded exposure allowlist is never registered', async () => {
    config = makeConfig({
        exposureSet: 'sandbox',
        agentTools: ['edit_field', 'get_page_structure', 'select_logo'],
    });
    installWebMCP({ bridge, config, coordinator });
    await window.__siteworks_webmcp__.sync();

    expect(tools.has('siteworks.edit_field')).toBe(true);
    expect(tools.has('siteworks.select_logo')).toBe(true);
    expect(tools.has('siteworks.generate_logo_concepts')).toBe(false);
    expect(aborted).not.toContain('siteworks.generate_logo_concepts');

    // navigate_preview is a shell capability, not an operation — never exposure-filtered.
    expect(tools.has('siteworks.navigate_preview')).toBe(true);

    // The Agent's View reports the effective set.
    expect(document.getElementById('webmcp-agent-exposure')?.textContent).toContain('sandbox');
});

test('execute always returns an MCP text envelope around the operation result', async () => {
    installWebMCP({ bridge, config, coordinator });
    await window.__siteworks_webmcp__.sync();

    const response = await tools.get('siteworks.select_logo').execute({ concept_id: 1 });

    expect(response.content).toHaveLength(1);
    expect(response.content[0].type).toBe('text');
    const envelope = JSON.parse(response.content[0].text);
    expect(envelope).toEqual({
        ok: true,
        data: expect.any(Object),
        state: expect.any(Object),
        receipt: expect.any(Object),
    });
    expect(envelope.receipt).toHaveProperty('preview');
});

test('a non-integer stored_index can never reach the URL (endpoint escape) and makes no request', async () => {
    installWebMCP({ bridge, config, coordinator });
    await window.__siteworks_webmcp__.sync();

    const envelope = parseEnvelope(await tools.get('siteworks.update_form').execute({
        page_id: 5,
        stored_index: '0/../../../../discard-all',
        fields: [],
        revision_base: 42,
    }));

    expect(envelope).toEqual({
        ok: false,
        error: { code: 'validation', message: 'stored_index must be a non-negative integer.' },
        state: {
            site_id: 9,
            page_id: 5,
            draft_revision_id: 20,
            composition_revision: 7,
            pending_publish: false,
            structure_epoch: 2,
        },
        receipt: {
            new_revision: null,
            effective: null,
            changed: [],
            warnings: [],
            publishable: false,
            preview: 'not_applicable',
        },
    });
    expect(fetchMock).not.toHaveBeenCalled();
});

/*
 * A write's rendered page html carries one 8-hour signed `editor-preview` URL per
 * nav item, and that signature is the only proof of authorization the preview route asks for. The tool
 * envelope is transmitted to the model provider by design, so the html must stop at the coordinator — which
 * has already consumed it for the section swap by the time dispatchWrite returns. Both keys matter: the
 * legacy field-update route answers with a top-level `html` AND the operation envelope's `data.html`.
 */
test('a write result strips rendered html from the tool envelope but keeps preview', async () => {
    fetchMock.mockImplementation(async () => ({
        ok: true,
        status: 200,
        json: async () => ({
            html: '<html><a href="/editor-preview/9/5?signature=deadbeef">Home</a></html>',
            page_id: 5,
            draft_revision_id: 21,
            ok: true,
            data: {
                stored_index: 0,
                draft_revision_id: 21,
                html: '<html><a href="/editor-preview/9/5?signature=deadbeef">Home</a></html>',
            },
            state: {
                site_id: 9,
                page_id: 5,
                draft_revision_id: 21,
                composition_revision: 8,
                pending_publish: true,
                structure_epoch: 2,
            },
        }),
    }));
    installWebMCP({ bridge, config, coordinator });
    await window.__siteworks_webmcp__.sync();

    const response = await tools.get('siteworks.edit_field').execute({
        page_id: 5,
        stored_index: 0,
        field_path: 'title',
        value: 'Agent title',
    });
    const envelope = parseEnvelope(response);

    expect(envelope.ok).toBe(true);
    expect(envelope.receipt.preview).toBe('applied');
    expect(envelope.data.stored_index).toBe(0);
    expect(envelope.data.draft_revision_id).toBe(21);
    expect(envelope.data.html).toBeUndefined();
    expect(envelope.html).toBeUndefined();
    expect(response.content[0].text).not.toContain('signature=');

    // the coordinator still receives the untouched response — the section swap depends on it
    const delivered = await coordinator.runExternal.mock.calls[0][0].fn();
    expect(delivered.data.html).toContain('signature=');
});

/*
 * Receipt key must not leak the signed URL either. Asserting envelope.data.html === undefined
 * passes against a receipt that leaks; stringify the whole envelope and demand the signed URL
 * substring is absent from every key, including receipt.
 *
 * Wrong implementation: dispatchWrite copies html onto receipt (or leaves receipt.html in place)
 * after stripping the top-level and data.html keys.
 */
test('a write envelope never reintroduces rendered html or a signed editor-preview URL, including under receipt', async () => {
    const leakedPreviewUrl = 'https://preview.example/editor-preview/9/5?expires=4070908800&signature=t11-pin-7f3c9e21deadbeef';
    const html = `<html><a href="${leakedPreviewUrl}">Home</a></html>`;
    fetchMock.mockImplementation(async () => ({
        ok: true,
        status: 200,
        json: async () => ({
            html,
            page_id: 5,
            draft_revision_id: 21,
            ok: true,
            data: {
                stored_index: 0,
                draft_revision_id: 21,
                html,
            },
            receipt: {
                new_revision: 21,
                effective: null,
                changed: [],
                warnings: [],
                publishable: false,
                preview: 'not_applicable',
                html,
            },
            state: {
                site_id: 9,
                page_id: 5,
                draft_revision_id: 21,
                composition_revision: 8,
                pending_publish: true,
                structure_epoch: 2,
            },
        }),
    }));
    installWebMCP({ bridge, config, coordinator });
    await window.__siteworks_webmcp__.sync();

    const envelope = parseEnvelope(await tools.get('siteworks.edit_field').execute({
        page_id: 5,
        stored_index: 0,
        field_path: 'title',
        value: 'Agent title',
    }));

    expect(JSON.stringify(envelope)).not.toContain(leakedPreviewUrl);
    expect(JSON.stringify(envelope)).not.toContain('"html"');
});

/*
 * Wrong implementation: Agent's view renders `${entry.name} ${status}` and silently drops
 * receipt.warnings, so a two-warning call looks identical to a zero-warning call.
 */
test("Agent's view lists receipt.warnings in order and omits a warning node on a zero-warning call", async () => {
    const firstWarning = 'Pending hero video is drafted off; publish will stop the clip.';
    const secondWarning = 'Live scene still overrides the drafted image until a human publishes.';
    const state = {
        site_id: 9,
        page_id: 5,
        draft_revision_id: 21,
        composition_revision: 8,
        pending_publish: true,
        structure_epoch: 2,
    };

    fetchMock
        .mockImplementationOnce(async () => ({
            ok: true,
            status: 200,
            json: async () => ({
                ok: true,
                data: {},
                state,
                receipt: {
                    new_revision: 21,
                    effective: null,
                    changed: [],
                    warnings: [
                        { code: 'hero_video_off', message: firstWarning, severity: 'warn' },
                        { code: 'scene_live', message: secondWarning, severity: 'info' },
                    ],
                    publishable: false,
                    preview: 'not_applicable',
                },
            }),
        }))
        .mockImplementationOnce(async () => ({
            ok: true,
            status: 200,
            json: async () => ({
                ok: true,
                data: {},
                state,
                receipt: {
                    new_revision: 22,
                    effective: null,
                    changed: [],
                    warnings: [],
                    publishable: false,
                    preview: 'not_applicable',
                },
            }),
        }));

    installWebMCP({ bridge, config, coordinator });
    await window.__siteworks_webmcp__.sync();

    await tools.get('siteworks.select_logo').execute({ concept_id: 11 });
    await tools.get('siteworks.select_logo').execute({ concept_id: 12 });

    const items = [...document.querySelectorAll('#webmcp-agent-log > li')];
    expect(items).toHaveLength(2);

    // The view renders the newest entry first.
    const zeroWarningItem = items[0];
    const twoWarningItem = items[1];

    expect([...zeroWarningItem.querySelectorAll('[data-agent-warning]')].map((node) => node.textContent))
        .toEqual([]);
    expect([...twoWarningItem.querySelectorAll('[data-agent-warning]')].map((node) => node.textContent))
        .toEqual([firstWarning, secondWarning]);
});

const COMMERCE_SANDBOX = [
    'list_products',
    'get_product',
    'draft_product',
    'update_draft_product',
    'set_product_image',
    'upload_image',
];

test('feature-detects the commerce exposure set and does not register brochure writes', async () => {
    config = makeConfig({
        surface: 'shop-admin',
        exposureSet: 'sandbox',
        agentTools: COMMERCE_SANDBOX,
        operationUrl: '/sites/9/operations/__operation__',
        catalogueRevision: 3,
    });
    coordinator.catalogueRevision = vi.fn(() => 3);
    installWebMCP({ bridge, config, coordinator });
    await window.__siteworks_webmcp__.sync();

    expect(tools.has('siteworks.draft_product')).toBe(true);
    expect(tools.has('siteworks.list_products')).toBe(true);
    expect(tools.has('siteworks.upload_image')).toBe(true);
    expect(tools.has('siteworks.edit_field')).toBe(false);
    expect(document.modelContext).toBeTruthy();
});

test('does not advertise upload_image to portal-shop clients', async () => {
    config = makeConfig({
        surface: 'portal-shop',
        exposureSet: 'sandbox',
        agentTools: COMMERCE_SANDBOX,
        operationUrl: '/sites/9/operations/__operation__',
        catalogueRevision: 3,
    });
    coordinator.catalogueRevision = vi.fn(() => 3);
    installWebMCP({ bridge, config, coordinator });
    await window.__siteworks_webmcp__.sync();

    expect(tools.has('siteworks.draft_product')).toBe(true);
    expect(tools.has('siteworks.list_products')).toBe(true);
    expect(tools.has('siteworks.upload_image')).toBe(false);
    expect(tools.has('siteworks.edit_field')).toBe(false);
});

test('abort-on-unregister drops commerce tools when the shop seed is cleared', async () => {
    config = makeConfig({
        surface: 'shop-admin',
        exposureSet: 'sandbox',
        agentTools: COMMERCE_SANDBOX,
        operationUrl: '/sites/9/operations/__operation__',
    });
    installWebMCP({ bridge, config, coordinator });
    await window.__siteworks_webmcp__.sync();

    expect(tools.has('siteworks.draft_product')).toBe(true);
    const names = [...tools.keys()];

    config.capabilities = ['edit', 'media'];
    await window.__siteworks_webmcp__.sync();

    expect(tools.size).toBe(0);
    expect(aborted).toContain('siteworks.draft_product');
    for (const name of names) {
        expect(aborted).toContain(name);
    }
});

test('a second shop write in the same page posts the revision returned by the first', async () => {
    config = makeConfig({
        surface: 'shop-admin',
        exposureSet: 'sandbox',
        agentTools: COMMERCE_SANDBOX,
        operationUrl: '/sites/9/operations/__operation__',
        catalogueRevision: 3,
    });
    let catalogueRevision = 3;
    coordinator.catalogueRevision = vi.fn(() => catalogueRevision);
    coordinator.setCatalogueRevision = vi.fn((value) => { catalogueRevision = value; });
    fetchMock.mockImplementation(async () => ({
        ok: true,
        status: 200,
        json: async () => ({ ok: true, data: { slug: 'candles', catalogue_revision: 4 }, state: {}, receipt: {} }),
    }));
    installWebMCP({ bridge, config, coordinator });
    await window.__siteworks_webmcp__.sync();

    await tools.get('siteworks.draft_product').execute({ name: 'Candle', category_slug: 'candles', variants: [] });
    await tools.get('siteworks.draft_product').execute({ name: 'Candle II', category_slug: 'candles', variants: [] });

    expect(coordinator.setCatalogueRevision).toHaveBeenCalledWith(4);
    expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toMatchObject({ catalogue_revision: 3 });
    expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toMatchObject({ catalogue_revision: 4 });
});

test('a stale shop write carries the current catalogue revision forward for the retry', async () => {
    config = makeConfig({
        surface: 'shop-admin',
        exposureSet: 'sandbox',
        agentTools: COMMERCE_SANDBOX,
        operationUrl: '/sites/9/operations/__operation__',
        catalogueRevision: 3,
    });
    let catalogueRevision = 3;
    coordinator.catalogueRevision = vi.fn(() => catalogueRevision);
    coordinator.setCatalogueRevision = vi.fn((value) => { catalogueRevision = value; });
    fetchMock.mockImplementationOnce(async () => ({
        ok: false,
        status: 409,
        json: async () => ({
            ok: false,
            error: { code: 'stale_revision', message: 'Shop catalogue has moved.', current_catalogue_revision: 7 },
            state: {},
            receipt: {},
        }),
    }));
    installWebMCP({ bridge, config, coordinator });
    await window.__siteworks_webmcp__.sync();

    const response = await tools.get('siteworks.draft_product').execute({ name: 'Candle', category_slug: 'candles', variants: [] });

    expect(parseEnvelope(response).error.code).toBe('stale_revision');
    expect(coordinator.setCatalogueRevision).toHaveBeenCalledWith(7);
    expect(coordinator.catalogueRevision()).toBe(7);
});

test('a successful import_products carries its new_revision forward as the catalogue base', async () => {
    config = makeConfig({
        surface: 'shop-admin',
        exposureSet: 'sandbox',
        agentTools: [...COMMERCE_SANDBOX, 'import_products'],
        operationUrl: '/sites/9/operations/__operation__',
        catalogueRevision: 3,
    });
    let catalogueRevision = 3;
    coordinator.catalogueRevision = vi.fn(() => catalogueRevision);
    coordinator.setCatalogueRevision = vi.fn((value) => { catalogueRevision = value; });
    fetchMock.mockImplementationOnce(async () => ({
        ok: true,
        status: 200,
        json: async () => ({ ok: true, data: { schema_version: 1, created: 2, failed: 0, new_revision: 5, results: [] }, state: {}, receipt: {} }),
    }));
    fetchMock.mockImplementationOnce(async () => ({
        ok: true,
        status: 200,
        json: async () => ({ ok: true, data: { slug: 'candles', catalogue_revision: 6 }, state: {}, receipt: {} }),
    }));
    installWebMCP({ bridge, config, coordinator });
    await window.__siteworks_webmcp__.sync();

    await tools.get('siteworks.import_products').execute({ schema_version: 1, format: 'json', data: '[]', dry_run: false, idempotency_key: 'k1' });
    await tools.get('siteworks.draft_product').execute({ name: 'Candle', category_slug: 'candles', variants: [] });

    expect(coordinator.setCatalogueRevision).toHaveBeenCalledWith(5);
    expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toMatchObject({ catalogue_revision: 5 });
});

test('an import_products replay or dry run never moves the catalogue base backwards', async () => {
    config = makeConfig({
        surface: 'shop-admin',
        exposureSet: 'sandbox',
        agentTools: [...COMMERCE_SANDBOX, 'import_products'],
        operationUrl: '/sites/9/operations/__operation__',
        catalogueRevision: 3,
    });
    let catalogueRevision = 3;
    coordinator.catalogueRevision = vi.fn(() => catalogueRevision);
    coordinator.setCatalogueRevision = vi.fn((value) => { catalogueRevision = value; });
    // Idempotency replay: the server returns the earlier commit's receipt, whose revision is old.
    fetchMock.mockImplementationOnce(async () => ({
        ok: true,
        status: 200,
        json: async () => ({ ok: true, data: { schema_version: 1, created: 2, failed: 0, new_revision: 1, results: [] }, state: {}, receipt: {} }),
    }));
    // Dry run: the server echoes the revision the client sent.
    fetchMock.mockImplementationOnce(async () => ({
        ok: true,
        status: 200,
        json: async () => ({ ok: true, data: { schema_version: 1, created: 0, failed: 0, new_revision: 3, results: [] }, state: {}, receipt: {} }),
    }));
    fetchMock.mockImplementationOnce(async () => ({
        ok: true,
        status: 200,
        json: async () => ({ ok: true, data: { slug: 'candles', catalogue_revision: 4 }, state: {}, receipt: {} }),
    }));
    installWebMCP({ bridge, config, coordinator });
    await window.__siteworks_webmcp__.sync();

    await tools.get('siteworks.import_products').execute({ schema_version: 1, format: 'json', data: '[]', dry_run: false, idempotency_key: 'k1' });
    await tools.get('siteworks.import_products').execute({ schema_version: 1, format: 'json', data: '[]', dry_run: true });
    await tools.get('siteworks.draft_product').execute({ name: 'Candle', category_slug: 'candles', variants: [] });

    expect(coordinator.setCatalogueRevision).not.toHaveBeenCalledWith(1);
    expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toMatchObject({ catalogue_revision: 3 });
    expect(JSON.parse(fetchMock.mock.calls[2][1].body)).toMatchObject({ catalogue_revision: 3 });
    expect(coordinator.catalogueRevision()).toBe(4);
});

test('a dry-run import echoing a model-supplied future revision does not move the catalogue base', async () => {
    config = makeConfig({
        surface: 'shop-admin',
        exposureSet: 'sandbox',
        agentTools: [...COMMERCE_SANDBOX, 'import_products'],
        operationUrl: '/sites/9/operations/__operation__',
        catalogueRevision: 3,
    });
    let catalogueRevision = 3;
    coordinator.catalogueRevision = vi.fn(() => catalogueRevision);
    coordinator.setCatalogueRevision = vi.fn((value) => { catalogueRevision = value; });
    fetchMock.mockImplementationOnce(async () => ({
        ok: true,
        status: 200,
        json: async () => ({ ok: true, data: { schema_version: 1, created: 0, failed: 0, new_revision: 999, results: [] }, state: {}, receipt: {} }),
    }));
    fetchMock.mockImplementationOnce(async () => ({
        ok: true,
        status: 200,
        json: async () => ({ ok: true, data: { slug: 'candles', catalogue_revision: 4 }, state: {}, receipt: {} }),
    }));
    installWebMCP({ bridge, config, coordinator });
    await window.__siteworks_webmcp__.sync();

    await tools.get('siteworks.import_products').execute({ schema_version: 1, format: 'json', data: '[]', dry_run: true, catalogue_revision: 999 });
    await tools.get('siteworks.draft_product').execute({ name: 'Candle', category_slug: 'candles', variants: [] });

    expect(coordinator.setCatalogueRevision).not.toHaveBeenCalledWith(999);
    expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toMatchObject({ catalogue_revision: 3 });
});

test('a stale conflict adopts the server current even when it is below the page base', async () => {
    config = makeConfig({
        surface: 'shop-admin',
        exposureSet: 'sandbox',
        agentTools: COMMERCE_SANDBOX,
        operationUrl: '/sites/9/operations/__operation__',
        catalogueRevision: 999,
    });
    let catalogueRevision = 999;
    coordinator.catalogueRevision = vi.fn(() => catalogueRevision);
    coordinator.setCatalogueRevision = vi.fn((value) => { catalogueRevision = value; });
    fetchMock.mockImplementationOnce(async () => ({
        ok: false,
        status: 409,
        json: async () => ({
            ok: false,
            error: { code: 'stale_revision', message: 'Shop catalogue has moved.', current_catalogue_revision: 5 },
            state: {},
            receipt: {},
        }),
    }));
    installWebMCP({ bridge, config, coordinator });
    await window.__siteworks_webmcp__.sync();

    await tools.get('siteworks.draft_product').execute({ name: 'Candle', category_slug: 'candles', variants: [] });

    expect(coordinator.catalogueRevision()).toBe(5);
});

test('draft_product execute returns an MCP envelope and posts catalogue_revision', async () => {
    config = makeConfig({
        surface: 'shop-admin',
        exposureSet: 'sandbox',
        agentTools: COMMERCE_SANDBOX,
        operationUrl: '/sites/9/operations/__operation__',
        catalogueRevision: 3,
    });
    coordinator.catalogueRevision = vi.fn(() => 3);
    installWebMCP({ bridge, config, coordinator });
    await window.__siteworks_webmcp__.sync();

    const response = await tools.get('siteworks.draft_product').execute({
        name: 'Hand-poured Candle',
        category_slug: 'candles',
        variants: [{ sku: 'CNDL-DEF', price_pence: 1299 }],
    });
    const envelope = parseEnvelope(response);

    expect(envelope).toEqual({
        ok: true,
        data: expect.any(Object),
        state: expect.any(Object),
        receipt: expect.any(Object),
    });
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock.mock.calls[0][0]).toBe('/sites/9/operations/draft_product');
    expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toMatchObject({
        name: 'Hand-poured Candle',
        category_slug: 'candles',
        catalogue_revision: 3,
    });
});


test('a successful shop write announces the catalogue change on the window and to Livewire', async () => {
    config = makeConfig({
        surface: 'shop-admin',
        exposureSet: 'sandbox',
        agentTools: COMMERCE_SANDBOX,
        operationUrl: '/sites/9/operations/__operation__',
        catalogueRevision: 3,
    });
    coordinator.catalogueRevision = vi.fn(() => 3);
    fetchMock.mockImplementationOnce(async () => ({
        ok: true,
        status: 200,
        json: async () => ({ ok: true, data: { slug: 'candle', catalogue_revision: 4, html: '<p>secret</p>' }, state: {}, receipt: {} }),
    }));
    const listener = vi.fn();
    window.addEventListener('siteworks:shop-catalogue-changed', listener);
    window.Livewire = { dispatch: vi.fn() };
    installWebMCP({ bridge, config, coordinator });
    await window.__siteworks_webmcp__.sync();

    try {
        await tools.get('siteworks.draft_product').execute({ name: 'Candle', category_slug: 'candles', variants: [] });

        expect(listener).toHaveBeenCalledTimes(1);
        expect(listener.mock.calls[0][0].detail).toEqual({ op: 'draft_product', data: { slug: 'candle', catalogue_revision: 4 } });
        expect(window.Livewire.dispatch).toHaveBeenCalledWith('shop-catalogue-changed');
    } finally {
        window.removeEventListener('siteworks:shop-catalogue-changed', listener);
        delete window.Livewire;
    }
});

test('a failed shop write announces nothing', async () => {
    config = makeConfig({
        surface: 'shop-admin',
        exposureSet: 'sandbox',
        agentTools: COMMERCE_SANDBOX,
        operationUrl: '/sites/9/operations/__operation__',
        catalogueRevision: 3,
    });
    coordinator.catalogueRevision = vi.fn(() => 3);
    fetchMock.mockImplementationOnce(async () => ({
        ok: false,
        status: 409,
        json: async () => ({
            ok: false,
            error: { code: 'stale_revision', message: 'Shop catalogue has moved.', current_catalogue_revision: 7 },
            state: {},
            receipt: {},
        }),
    }));
    const listener = vi.fn();
    window.addEventListener('siteworks:shop-catalogue-changed', listener);
    window.Livewire = { dispatch: vi.fn() };
    installWebMCP({ bridge, config, coordinator });
    await window.__siteworks_webmcp__.sync();

    try {
        await tools.get('siteworks.draft_product').execute({ name: 'Candle', category_slug: 'candles', variants: [] });

        expect(listener).not.toHaveBeenCalled();
        expect(window.Livewire.dispatch).not.toHaveBeenCalled();
    } finally {
        window.removeEventListener('siteworks:shop-catalogue-changed', listener);
        delete window.Livewire;
    }
});

test('a page write announces no catalogue change', async () => {
    const listener = vi.fn();
    window.addEventListener('siteworks:shop-catalogue-changed', listener);
    installWebMCP({ bridge, config, coordinator });
    await window.__siteworks_webmcp__.sync();

    try {
        await tools.get('siteworks.edit_field').execute({ page_id: 5, section_index: 0, field_path: 'title', value: 'Hello' });

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(listener).not.toHaveBeenCalled();
    } finally {
        window.removeEventListener('siteworks:shop-catalogue-changed', listener);
    }
});

test('a committed import_products mounts the summary panel and a dry run does not', async () => {
    config = makeConfig({
        surface: 'shop-admin',
        exposureSet: 'sandbox',
        agentTools: [...COMMERCE_SANDBOX, 'import_products'],
        operationUrl: '/sites/9/operations/__operation__',
        catalogueRevision: 3,
    });
    let catalogueRevision = 3;
    coordinator.catalogueRevision = vi.fn(() => catalogueRevision);
    coordinator.setCatalogueRevision = vi.fn((value) => { catalogueRevision = value; });
    const results = [
        { source_row: 1, status: 'created', name: 'Almond Croissant', slug: 'almond-croissant', category: 'pastries', price_pence: 800, warnings: ['missing_description'] },
        { source_row: 2, status: 'created', name: 'Pain au Chocolat', slug: 'pain-au-chocolat', category: 'pastries', price_pence: 900, warnings: [] },
    ];
    fetchMock.mockImplementation(async () => ({
        ok: true,
        status: 200,
        json: async () => ({ ok: true, data: { schema_version: 1, created: 2, failed: 0, new_revision: 4, results }, state: {}, receipt: {} }),
    }));
    installWebMCP({ bridge, config, coordinator });
    await window.__siteworks_webmcp__.sync();

    await tools.get('siteworks.import_products').execute({ schema_version: 1, format: 'json', data: '[]', dry_run: true });

    expect(document.getElementById('webmcp-import-summary')).toBeNull();

    await tools.get('siteworks.import_products').execute({ schema_version: 1, format: 'json', data: '[]', dry_run: false, idempotency_key: 'k1' });

    const panel = document.getElementById('webmcp-import-summary');
    expect(panel).not.toBeNull();
    expect(panel.querySelector('h2').textContent).toBe('Imported 2 draft products');
    expect(panel.querySelectorAll('[data-import-row]')).toHaveLength(2);
});

test('a catalogue change announced by the page refreshes the revision the next shop write posts', async () => {
    config = makeConfig({
        surface: 'shop-admin',
        exposureSet: 'sandbox',
        agentTools: COMMERCE_SANDBOX,
        operationUrl: '/sites/9/operations/__operation__',
        catalogueRevision: 3,
    });
    let catalogueRevision = 3;
    coordinator.catalogueRevision = vi.fn(() => catalogueRevision);
    coordinator.setCatalogueRevision = vi.fn((value) => { catalogueRevision = value; });
    const livewireHandlers = new Map();
    window.Livewire = {
        on: vi.fn((name, handler) => livewireHandlers.set(name, handler)),
        dispatch: vi.fn(),
    };
    fetchMock.mockImplementation(async (url) => ({
        ok: true,
        status: 200,
        json: async () => (String(url).endsWith('/get_site_context')
            ? { ok: true, data: { site_id: 9, catalogue_revision: 8 }, state: {}, receipt: {} }
            : { ok: true, data: { slug: 'candles', catalogue_revision: 9 }, state: {}, receipt: {} }),
    }));

    try {
        installWebMCP({ bridge, config, coordinator });
        await window.__siteworks_webmcp__.sync();

        expect(window.Livewire.on).toHaveBeenCalledWith('shop-catalogue-changed', expect.any(Function));

        // The page publishes something itself and announces it.
        livewireHandlers.get('shop-catalogue-changed')();
        await vi.waitFor(() => {
            expect(coordinator.setCatalogueRevision).toHaveBeenCalledWith(8);
        });
        expect(fetchMock.mock.calls[0][0]).toBe('/sites/9/operations/get_site_context');

        await tools.get('siteworks.draft_product').execute({ name: 'Candle', category_slug: 'candles', variants: [] });

        expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toMatchObject({ catalogue_revision: 8 });
        expect(coordinator.setCatalogueRevision).toHaveBeenCalledWith(9);
    } finally {
        delete window.Livewire;
    }
});
