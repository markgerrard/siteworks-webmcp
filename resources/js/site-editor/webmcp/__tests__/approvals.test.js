import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { installWebMCP } from '../index.js';
import { renderAgentView } from '../agent-view.js';
import { createApprovalView } from '../approvals.js';

const originalFetch = globalThis.fetch;
const originalModelContext = Object.getOwnPropertyDescriptor(Document.prototype, 'modelContext')
    ?? Object.getOwnPropertyDescriptor(document, 'modelContext');

const APPROVAL_ID = '9f1c8a2e-4b3d-4e1f-9c0a-1b2c3d4e5f60';
const XSS_PAYLOAD = '<img src=x onerror="window.__pwned=true">';
const BIDI_PAYLOAD = 'page \u202E12 noitces';

const VERBATIM_SUMMARY = {
    site: 'Eden Landscapes',
    side_effects: 'Spends money…',
    image_model: 'draft-low',
    image_model_label: 'Draft — Low',
    page_id: '12',
    stored_index: '5',
    field_path: 'image',
};

const VERBATIM_ROW = {
    id: APPROVAL_ID,
    operation: 'upload_image',
    channel: 'webmcp',
    summary: { ...VERBATIM_SUMMARY },
    requested_at: '2026-08-29T06:00:00+01:00',
    expires_at: '2026-08-29T06:10:00+01:00',
};

const APPROVAL_ENVELOPE = {
    ok: false,
    error: {
        code: 'approval_required',
        message: 'This operation requires approval',
        request_id: APPROVAL_ID,
        expires_at: '2026-08-29T06:10:00+01:00',
        operation: 'select_logo',
        side_effects: 'Spends money…',
    },
    state: {
        site_id: 9,
        page_id: 5,
        draft_revision_id: 20,
        composition_revision: 7,
        pending_publish: false,
        structure_epoch: 2,
    },
};

const SUCCESS_ENVELOPE = {
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
};

function makeConfig(overrides = {}) {
    return {
        siteId: 9,
        pageId: 5,
        csrfToken: 'csrf-token',
        capabilities: ['edit', 'publish', 'media', 'agent_tools', 'agent_approval'],
        agentSessionId: 'agent-session-1',
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

function jsonResponse(status, body) {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => structuredClone(body),
    };
}

function listUrl(siteId) {
    return `/sites/${siteId}/agent-approvals`;
}

function decideUrl(siteId, id, verb) {
    return `${listUrl(siteId)}/${encodeURIComponent(id)}/${verb}`;
}

function isListGet(url, init) {
    return (init?.method ?? 'GET') === 'GET' && String(url) === listUrl(9);
}

function header(init, name) {
    const headers = init?.headers;
    if (! headers) {
        return undefined;
    }
    if (typeof headers.get === 'function') {
        return headers.get(name) ?? undefined;
    }

    return headers[name];
}

function summaryValueTexts(card) {
    return [...card.querySelectorAll('[dir="ltr"]')].map((el) => el.textContent);
}

async function flushPromises() {
    for (let i = 0; i < 8; i++) {
        await Promise.resolve();
    }
}

function installFetchRouter({
    operationBody = APPROVAL_ENVELOPE,
    listBody = { approvals: [VERBATIM_ROW] },
} = {}) {
    let list = listBody;
    const fetchMock = vi.fn(async (url, init) => {
        const path = String(url);
        const method = (init?.method ?? 'GET').toUpperCase();
        if (method === 'GET' && path === listUrl(9)) {
            const body = typeof list === 'function' ? list() : list;

            return jsonResponse(200, body);
        }
        if (method === 'POST' && (path.endsWith('/approve') || path.endsWith('/deny'))) {
            if (typeof list !== 'function') {
                list = { approvals: [] };
            }

            return jsonResponse(200, { ok: true });
        }

        return jsonResponse(operationBody.ok === false ? 403 : 200, operationBody);
    });
    globalThis.fetch = fetchMock;

    return {
        fetchMock,
        setList(next) {
            list = next;
        },
    };
}

async function installTools(configOverrides = {}) {
    const config = makeConfig(configOverrides);
    const coordinator = makeCoordinator();
    const { tools } = installFakeModelContext();
    installWebMCP({ bridge: makeBridge(), config, coordinator });
    await window.__siteworks_webmcp__.sync();

    return { config, coordinator, tools };
}

let fetchMock;

beforeEach(() => {
    document.body.replaceChildren();
    delete window.__pwned;
    vi.useFakeTimers();
    ({ fetchMock } = installFetchRouter());
});

afterEach(() => {
    vi.clearAllTimers();
    vi.useRealTimers();
    globalThis.fetch = originalFetch;
    if (originalModelContext) {
        Object.defineProperty(document, 'modelContext', originalModelContext);
    } else {
        delete document.modelContext;
    }
});

test('an approval_required envelope from a tool call starts the poll at the site-built URL', async () => {
    const { config, tools } = await installTools();
    await tools.get('siteworks.select_logo').execute({ concept_id: 42 });
    await flushPromises();

    const gets = fetchMock.mock.calls.filter(([url, init]) => isListGet(url, init));
    expect(gets.length).toBeGreaterThanOrEqual(1);
    expect(gets[0][0]).toBe(listUrl(config.siteId));
});

test('a successful envelope or any other error code starts no approval poll', async () => {
    ({ fetchMock } = installFetchRouter({ operationBody: SUCCESS_ENVELOPE }));
    const { tools } = await installTools();
    await tools.get('siteworks.select_logo').execute({ concept_id: 42 });
    await flushPromises();
    expect(fetchMock.mock.calls.filter(([url, init]) => isListGet(url, init))).toHaveLength(0);

    document.body.replaceChildren();
    const otherError = {
        ...APPROVAL_ENVELOPE,
        error: { ...APPROVAL_ENVELOPE.error, code: 'forbidden' },
    };
    ({ fetchMock } = installFetchRouter({ operationBody: otherError }));
    const second = await installTools();
    await second.tools.get('siteworks.select_logo').execute({ concept_id: 42 });
    await flushPromises();
    expect(fetchMock.mock.calls.filter(([url, init]) => isListGet(url, init))).toHaveLength(0);
});

test('an approval_required envelope for an operation absent from every front-end list still starts the poll and renders that operation\'s card', async () => {
    renderAgentView();
    const absentName = 'undo_revision';
    const envelope = {
        ...APPROVAL_ENVELOPE,
        error: { ...APPROVAL_ENVELOPE.error, operation: absentName },
    };
    const row = { ...VERBATIM_ROW, operation: absentName };
    ({ fetchMock } = installFetchRouter({ listBody: { approvals: [row] } }));
    const view = createApprovalView({ config: makeConfig() });
    view.noticeEnvelope(structuredClone(envelope));
    await flushPromises();

    const gets = fetchMock.mock.calls.filter(([url, init]) => isListGet(url, init));
    expect(gets.length).toBeGreaterThanOrEqual(1);

    const card = document.querySelector('#webmcp-approval-list article');
    expect(card).toBeTruthy();

    const operationLabel = [...card.querySelectorAll('span')]
        .find((el) => el.textContent === 'operation: ');
    expect(operationLabel).toBeTruthy();
    expect(operationLabel.nextElementSibling.textContent).toBe(absentName);

    view.stop();
});

test('every summary key renders in its own value element, never concatenated', async () => {
    renderAgentView();
    const summary = { ...VERBATIM_SUMMARY, field_path: 'image · page_id 999' };
    const row = { ...VERBATIM_ROW, summary };
    ({ fetchMock } = installFetchRouter({ listBody: { approvals: [row] } }));
    const view = createApprovalView({ config: makeConfig() });
    view.noticeEnvelope(structuredClone(APPROVAL_ENVELOPE));
    await flushPromises();

    const card = document.querySelector('#webmcp-approval-list article');
    expect(card).toBeTruthy();
    expect(summaryValueTexts(card)).toEqual(Object.values(summary));

    const valueEls = [...card.querySelectorAll('[dir="ltr"]')];
    for (const valueEl of valueEls) {
        const field = valueEl.parentElement;
        const label = valueEl.previousElementSibling;
        expect(label.textContent.endsWith(': ')).toBe(true);
        expect(field.className.split(/\s+/)).toContain('gap-2');
    }

    const fieldPathValue = valueEls.find((el) => el.textContent.includes(' · '));
    expect(fieldPathValue.textContent).toBe('image · page_id 999');
    expect(fieldPathValue.previousElementSibling.textContent).toBe('field_path: ');

    const labelTexts = [...card.querySelectorAll('span')]
        .map((el) => el.textContent)
        .filter((text) => text.endsWith(': '));
    expect(labelTexts).toEqual(expect.arrayContaining([
        'operation: ',
        'channel: ',
        'expires: ',
    ]));
    view.stop();
});


test('an HTML summary value renders as text and produces no live element', async () => {
    renderAgentView();
    const row = {
        ...VERBATIM_ROW,
        summary: { site: XSS_PAYLOAD },
    };
    ({ fetchMock } = installFetchRouter({ listBody: { approvals: [row] } }));
    const view = createApprovalView({ config: makeConfig() });
    view.noticeEnvelope(structuredClone(APPROVAL_ENVELOPE));
    await flushPromises();

    const card = document.querySelector('#webmcp-approval-list article');
    expect(card).toBeTruthy();
    expect(card.querySelectorAll('img')).toHaveLength(0);
    expect(window.__pwned).toBeUndefined();
    expect(summaryValueTexts(card)).toEqual([XSS_PAYLOAD]);
    view.stop();
});

test('a bidi override is stripped and value-element order matches the supplied map', async () => {
    renderAgentView();
    const summary = {
        site: 'Eden Landscapes',
        side_effects: BIDI_PAYLOAD,
        page_id: '12',
    };
    const row = { ...VERBATIM_ROW, summary };
    ({ fetchMock } = installFetchRouter({ listBody: { approvals: [row] } }));
    const view = createApprovalView({ config: makeConfig() });
    view.noticeEnvelope(structuredClone(APPROVAL_ENVELOPE));
    await flushPromises();

    const card = document.querySelector('#webmcp-approval-list article');
    expect(card).toBeTruthy();
    const values = summaryValueTexts(card);
    expect(values.join('')).not.toContain('\u202E');
    for (const value of values) {
        expect(value).not.toContain('\u202E');
    }
    expect(values).toEqual(['Eden Landscapes', 'page 12 noitces', '12']);
    view.stop();
});

test('wrap() is byte-identical whether or not the confirmation view rendered', async () => {
    const live = await installTools();
    const liveResponse = await live.tools.get('siteworks.select_logo').execute({ concept_id: 42 });
    await flushPromises();
    const liveHadCard = Boolean(document.querySelector('#webmcp-approval-list article'));

    document.body.replaceChildren();
    ({ fetchMock } = installFetchRouter());
    const inert = await installTools({
        capabilities: ['edit', 'publish', 'media', 'agent_tools'],
    });
    const inertResponse = await inert.tools.get('siteworks.select_logo').execute({ concept_id: 42 });
    await flushPromises();

    expect(JSON.parse(liveResponse.content[0].text)).toEqual(APPROVAL_ENVELOPE);
    expect(JSON.parse(inertResponse.content[0].text)).toEqual(APPROVAL_ENVELOPE);
    expect(JSON.stringify(liveResponse)).toBe(JSON.stringify(inertResponse));
    expect(liveHadCard).toBe(true);
    expect(document.querySelector('#webmcp-approval-list article')).toBeNull();
});

test('Approve and Deny POST the row URL with CSRF and no editor channel, then stop on empty', async () => {
    renderAgentView();
    const view = createApprovalView({ config: makeConfig() });
    view.noticeEnvelope(structuredClone(APPROVAL_ENVELOPE));
    await flushPromises();

    const approve = [...document.querySelectorAll('button')]
        .find((button) => button.textContent === 'Approve');
    expect(approve).toBeTruthy();
    approve.click();
    await flushPromises();

    const approveCall = fetchMock.mock.calls.find(([url, init]) => (
        String(url) === decideUrl(9, APPROVAL_ID, 'approve') && init?.method === 'POST'
    ));
    expect(approveCall).toBeTruthy();
    expect(header(approveCall[1], 'X-CSRF-TOKEN')).toBe('csrf-token');
    expect(header(approveCall[1], 'X-Editor-Channel')).toBeUndefined();
    expect(approveCall[1].credentials).toBe('same-origin');

    const getsAfterApprove = fetchMock.mock.calls.filter(([url, init]) => isListGet(url, init)).length;
    await vi.advanceTimersByTimeAsync(10_000);
    expect(fetchMock.mock.calls.filter(([url, init]) => isListGet(url, init))).toHaveLength(getsAfterApprove);
    view.stop();

    document.body.replaceChildren();
    renderAgentView();
    ({ fetchMock } = installFetchRouter());
    const denyView = createApprovalView({ config: makeConfig() });
    denyView.noticeEnvelope(structuredClone(APPROVAL_ENVELOPE));
    await flushPromises();

    const deny = [...document.querySelectorAll('button')]
        .find((button) => button.textContent === 'Deny');
    expect(deny).toBeTruthy();
    deny.click();
    await flushPromises();

    const denyCall = fetchMock.mock.calls.find(([url, init]) => (
        String(url) === decideUrl(9, APPROVAL_ID, 'deny') && init?.method === 'POST'
    ));
    expect(denyCall).toBeTruthy();
    expect(header(denyCall[1], 'X-CSRF-TOKEN')).toBe('csrf-token');
    expect(header(denyCall[1], 'X-Editor-Channel')).toBeUndefined();

    const getsAfterDeny = fetchMock.mock.calls.filter(([url, init]) => isListGet(url, init)).length;
    await vi.advanceTimersByTimeAsync(10_000);
    expect(fetchMock.mock.calls.filter(([url, init]) => isListGet(url, init))).toHaveLength(getsAfterDeny);
    denyView.stop();
});

test('a list that stays non-empty keeps polling past five minutes', async () => {
    renderAgentView();
    const view = createApprovalView({ config: makeConfig() });
    view.noticeEnvelope(structuredClone(APPROVAL_ENVELOPE));
    await flushPromises();

    await vi.advanceTimersByTimeAsync(5 * 60 * 1000);
    const atFive = fetchMock.mock.calls.filter(([url, init]) => isListGet(url, init)).length;
    expect(atFive).toBeGreaterThan(1);

    await vi.advanceTimersByTimeAsync(10 * 1000);
    expect(fetchMock.mock.calls.filter(([url, init]) => isListGet(url, init)).length).toBeGreaterThan(atFive);
    view.stop();
});

test('an empty list stops polling immediately', async () => {
    renderAgentView();
    ({ fetchMock } = installFetchRouter({ listBody: { approvals: [] } }));
    const view = createApprovalView({ config: makeConfig() });
    view.noticeEnvelope(structuredClone(APPROVAL_ENVELOPE));
    await flushPromises();

    const afterEmpty = fetchMock.mock.calls.filter(([url, init]) => isListGet(url, init)).length;
    expect(afterEmpty).toBeGreaterThanOrEqual(1);

    await vi.advanceTimersByTimeAsync(10 * 1000);
    expect(fetchMock.mock.calls.filter(([url, init]) => isListGet(url, init))).toHaveLength(afterEmpty);
    view.stop();
});

test('failed polls after the last success still stop at the ceiling from that success', async () => {
    renderAgentView();
    let succeed = true;
    const fetchSpy = vi.fn(async (url, init) => {
        if (isListGet(url, init)) {
            if (! succeed) {
                return jsonResponse(500, {});
            }

            return jsonResponse(200, { approvals: [VERBATIM_ROW] });
        }

        return jsonResponse(200, {});
    });
    globalThis.fetch = fetchSpy;
    fetchMock = fetchSpy;

    const view = createApprovalView({ config: makeConfig() });
    view.noticeEnvelope(structuredClone(APPROVAL_ENVELOPE));
    await flushPromises();

    await vi.advanceTimersByTimeAsync(4 * 60 * 1000);
    succeed = false;
    await vi.advanceTimersByTimeAsync(90 * 1000);
    const midFail = fetchSpy.mock.calls.filter(([url, init]) => isListGet(url, init)).length;

    await vi.advanceTimersByTimeAsync(90 * 1000);
    expect(fetchSpy.mock.calls.filter(([url, init]) => isListGet(url, init)).length).toBeGreaterThan(midFail);

    await vi.advanceTimersByTimeAsync(3 * 60 * 1000);
    const atCeiling = fetchSpy.mock.calls.filter(([url, init]) => isListGet(url, init)).length;
    await vi.advanceTimersByTimeAsync(30 * 1000);
    expect(fetchSpy.mock.calls.filter(([url, init]) => isListGet(url, init))).toHaveLength(atCeiling);
    view.stop();
});


test('the list GET carries neither X-Editor-Channel nor X-Editor-Agent-Session', async () => {
    renderAgentView();
    const view = createApprovalView({
        config: makeConfig({ agentSessionId: 'agent-session-1' }),
    });
    view.noticeEnvelope(structuredClone(APPROVAL_ENVELOPE));
    await flushPromises();

    const getCall = fetchMock.mock.calls.find(([url, init]) => isListGet(url, init));
    expect(getCall).toBeTruthy();
    expect(header(getCall[1], 'X-Editor-Channel')).toBeUndefined();
    expect(header(getCall[1], 'X-Editor-Agent-Session')).toBeUndefined();
    expect(header(getCall[1], 'Accept')).toBe('application/json');
    expect(getCall[1].credentials).toBe('same-origin');
    view.stop();
});

test('Approve does not re-issue the operation POST', async () => {
    const { tools } = await installTools();
    await tools.get('siteworks.select_logo').execute({ concept_id: 42 });
    await flushPromises();

    const operationPosts = () => fetchMock.mock.calls.filter(([url, init]) => (
        init?.method === 'POST' && String(url) === '/sites/9/logo/select'
    ));
    expect(operationPosts()).toHaveLength(1);

    const approve = [...document.querySelectorAll('button')]
        .find((button) => button.textContent === 'Approve');
    expect(approve).toBeTruthy();
    approve.click();
    await flushPromises();

    expect(operationPosts()).toHaveLength(1);
    const approvePosts = fetchMock.mock.calls.filter(([url, init]) => (
        init?.method === 'POST' && String(url) === decideUrl(9, APPROVAL_ID, 'approve')
    ));
    expect(approvePosts).toHaveLength(1);
    expect(fetchMock.mock.calls.filter(([url, init]) => isListGet(url, init)).length).toBeGreaterThanOrEqual(2);
});

test('a summary value longer than 120 characters is truncated', async () => {
    renderAgentView();
    const row = { ...VERBATIM_ROW, summary: { site: 'a'.repeat(160) } };
    ({ fetchMock } = installFetchRouter({ listBody: { approvals: [row] } }));
    const view = createApprovalView({ config: makeConfig() });
    view.noticeEnvelope(structuredClone(APPROVAL_ENVELOPE));
    await flushPromises();

    expect(summaryValueTexts(document.querySelector('#webmcp-approval-list article'))).toEqual(['a'.repeat(120)]);
    view.stop();
});

test('a decomposed summary value is rendered NFC-composed', async () => {
    renderAgentView();
    const nfd = 'Cafe\u0301';
    const row = { ...VERBATIM_ROW, summary: { site: nfd } };
    ({ fetchMock } = installFetchRouter({ listBody: { approvals: [row] } }));
    const view = createApprovalView({ config: makeConfig() });
    view.noticeEnvelope(structuredClone(APPROVAL_ENVELOPE));
    await flushPromises();

    expect(summaryValueTexts(document.querySelector('#webmcp-approval-list article'))).toEqual([nfd.normalize('NFC')]);
    expect(summaryValueTexts(document.querySelector('#webmcp-approval-list article'))[0]).toBe('Caf\u00e9');
    view.stop();
});

test('a second approval_required envelope does not stack poll timers', async () => {
    renderAgentView();
    const view = createApprovalView({ config: makeConfig() });
    view.noticeEnvelope(structuredClone(APPROVAL_ENVELOPE));
    await flushPromises();
    const afterFirst = fetchMock.mock.calls.filter(([url, init]) => isListGet(url, init)).length;

    view.noticeEnvelope(structuredClone(APPROVAL_ENVELOPE));
    await flushPromises();
    expect(fetchMock.mock.calls.filter(([url, init]) => isListGet(url, init))).toHaveLength(afterFirst);

    await vi.advanceTimersByTimeAsync(2000);
    expect(fetchMock.mock.calls.filter(([url, init]) => isListGet(url, init))).toHaveLength(afterFirst + 1);
    view.stop();
});

test('the approval host is absent after renderAgentView when the flag is off', () => {
    renderAgentView({ live: true });
    createApprovalView({
        config: makeConfig({ capabilities: ['agent_tools'] }),
    });
    expect(document.getElementById('webmcp-approval-list')).toBeNull();
});

test('a pending approval un-hides the agent panel and puts the count on the pill', async () => {
    renderAgentView({ live: true });
    const panel = document.getElementById('webmcp-agent-view');
    const pill = document.getElementById('webmcp-agent-pill');
    expect(panel.hidden).toBe(true);
    expect(pill.textContent).toBe('Site tools live');

    const view = createApprovalView({ config: makeConfig() });
    view.noticeEnvelope(structuredClone(APPROVAL_ENVELOPE));
    await flushPromises();

    expect(document.querySelector('#webmcp-approval-list article')).toBeTruthy();
    expect(panel.hidden).toBe(false);
    expect(pill.textContent).toMatch(/1/);
    expect(pill.textContent).not.toBe('Site tools live');
    view.stop();
});

test('closing the review is not undone by a later poll of the same pending rows', async () => {
    renderAgentView({ live: true });
    const panel = document.getElementById('webmcp-agent-view');
    const view = createApprovalView({ config: makeConfig() });
    view.noticeEnvelope(structuredClone(APPROVAL_ENVELOPE));
    await flushPromises();
    expect(panel.hidden).toBe(false);

    panel.hidden = true;
    await vi.advanceTimersByTimeAsync(2000);
    await flushPromises();

    expect(panel.hidden).toBe(true);
    expect(document.querySelector('#webmcp-approval-list article')).toBeTruthy();
    view.stop();
});

test('a new pending row after the human closed the review re-opens it', async () => {
    renderAgentView({ live: true });
    const panel = document.getElementById('webmcp-agent-view');
    const view = createApprovalView({ config: makeConfig() });
    view.noticeEnvelope(structuredClone(APPROVAL_ENVELOPE));
    await flushPromises();
    panel.hidden = true;

    const secondRow = {
        ...VERBATIM_ROW,
        id: 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        operation: 'select_logo',
    };
    fetchMock.mockClear();
    ({ fetchMock } = installFetchRouter({
        listBody: { approvals: [VERBATIM_ROW, secondRow] },
    }));
    await vi.advanceTimersByTimeAsync(2000);
    await flushPromises();

    expect(panel.hidden).toBe(false);
    expect(document.querySelectorAll('#webmcp-approval-list article')).toHaveLength(2);
    expect(document.getElementById('webmcp-agent-pill').textContent).toMatch(/2/);
    view.stop();
});

test('a 409 decision renders a visible failure and keeps the row', async () => {
    renderAgentView();
    const fetchSpy = vi.fn(async (url, init) => {
        if (isListGet(url, init)) {
            return jsonResponse(200, { approvals: [VERBATIM_ROW] });
        }
        if (init?.method === 'POST' && String(url).endsWith('/approve')) {
            return jsonResponse(409, { ok: false });
        }

        return jsonResponse(200, {});
    });
    globalThis.fetch = fetchSpy;
    fetchMock = fetchSpy;

    const view = createApprovalView({ config: makeConfig() });
    view.noticeEnvelope(structuredClone(APPROVAL_ENVELOPE));
    await flushPromises();

    const card = document.querySelector('#webmcp-approval-list article');
    const approve = [...card.querySelectorAll('button')]
        .find((button) => button.textContent === 'Approve');
    approve.click();
    await flushPromises();

    const still = document.querySelector('#webmcp-approval-list article');
    expect(still).toBeTruthy();
    expect(still.textContent).toMatch(/no longer available|could not apply|not applied/i);
    expect(still.querySelector('[dir="ltr"]')).toBeTruthy();
    expect(fetchSpy.mock.calls.filter(([url, init]) => (
        init?.method === 'POST' && String(url) === decideUrl(9, APPROVAL_ID, 'approve')
    ))).toHaveLength(1);
    view.stop();
});

test('a second Approve click while the first is in flight sends no second POST', async () => {
    renderAgentView();
    let releasePost;
    const heldPost = new Promise((resolve) => {
        releasePost = resolve;
    });
    const fetchSpy = vi.fn((url, init) => {
        if (isListGet(url, init)) {
            return Promise.resolve(jsonResponse(200, { approvals: [VERBATIM_ROW] }));
        }
        if (init?.method === 'POST' && String(url).endsWith('/approve')) {
            return heldPost.then(() => jsonResponse(200, { ok: true }));
        }

        return Promise.resolve(jsonResponse(200, {}));
    });
    globalThis.fetch = fetchSpy;
    fetchMock = fetchSpy;

    const view = createApprovalView({ config: makeConfig() });
    view.noticeEnvelope(structuredClone(APPROVAL_ENVELOPE));
    await flushPromises();

    const card = document.querySelector('#webmcp-approval-list article');
    const approve = [...card.querySelectorAll('button')]
        .find((button) => button.textContent === 'Approve');
    const deny = [...card.querySelectorAll('button')]
        .find((button) => button.textContent === 'Deny');
    approve.click();
    approve.click();
    deny.click();
    await flushPromises();

    expect(approve.disabled).toBe(true);
    expect(deny.disabled).toBe(true);
    expect(fetchSpy.mock.calls.filter(([url, init]) => init?.method === 'POST')).toHaveLength(1);

    releasePost();
    await flushPromises();
    view.stop();
});

test('a never-resolving list GET issues one request across the ceiling and stop() aborts it', async () => {
    renderAgentView({ live: true });
    const hung = vi.fn(() => new Promise(() => {}));
    globalThis.fetch = hung;

    const view = createApprovalView({ config: makeConfig() });
    view.noticeEnvelope(structuredClone(APPROVAL_ENVELOPE));
    await flushPromises();

    await vi.advanceTimersByTimeAsync(5 * 60 * 1000);
    expect(hung).toHaveBeenCalledTimes(1);
    const signal = hung.mock.calls[0][1]?.signal;
    expect(signal).toBeInstanceOf(AbortSignal);

    view.stop();
    expect(signal.aborted).toBe(true);
});

test('an empty pending list leaves the review hidden state untouched', async () => {
    renderAgentView({ live: true });
    const panel = document.getElementById('webmcp-agent-view');
    expect(panel.hidden).toBe(true);

    ({ fetchMock } = installFetchRouter({ listBody: { approvals: [] } }));
    const view = createApprovalView({ config: makeConfig() });
    view.noticeEnvelope(structuredClone(APPROVAL_ENVELOPE));
    await flushPromises();

    expect(panel.hidden).toBe(true);
    expect(document.querySelector('#webmcp-approval-list article')).toBeNull();
    view.stop();

    panel.hidden = false;
    const stillEmpty = createApprovalView({ config: makeConfig() });
    stillEmpty.noticeEnvelope(structuredClone(APPROVAL_ENVELOPE));
    await flushPromises();
    expect(panel.hidden).toBe(false);
    stillEmpty.stop();
});

test('the agent panel class string carries a max-height bound and overflow-y-auto', async () => {
    // jsdom proxy: this pins the class-string mechanism (`max-h-*` +
    // `overflow-y-auto`) that makes `#webmcp-agent-view` self-scroll inside its
    // `position: fixed` toolbar ancestor. jsdom does no layout, so this cannot
    // prove Approve is geometrically reachable in a real viewport — that proof
    // is the browser evidence lane. Do not assert getBoundingClientRect here;
    // a geometry assertion in jsdom would pass or fail for the wrong reason.
    renderAgentView();
    const view = createApprovalView({ config: makeConfig() });
    view.noticeEnvelope(structuredClone(APPROVAL_ENVELOPE));
    await flushPromises();

    expect(document.querySelector('#webmcp-approval-list article')).toBeTruthy();
    const panel = document.getElementById('webmcp-agent-view');
    const classes = panel.className.split(/\s+/);
    expect(classes.some((token) => token.startsWith('max-h-'))).toBe(true);
    expect(classes).toContain('overflow-y-auto');
    view.stop();
});

test('the approval list precedes the tool list in the agent panel', async () => {
    renderAgentView();
    const view = createApprovalView({ config: makeConfig() });
    view.noticeEnvelope(structuredClone(APPROVAL_ENVELOPE));
    await flushPromises();

    const panel = document.getElementById('webmcp-agent-view');
    const approvals = document.getElementById('webmcp-approval-list');
    const tools = document.getElementById('webmcp-agent-tool-list');
    expect(approvals).toBeTruthy();
    expect(tools).toBeTruthy();
    expect(approvals.parentElement).toBe(panel);
    expect(tools.parentElement).toBe(panel);

    const children = [...panel.children];
    expect(children.indexOf(approvals)).toBeLessThan(children.indexOf(tools));
    expect(approvals.compareDocumentPosition(tools) & Node.DOCUMENT_POSITION_FOLLOWING)
        .toBe(Node.DOCUMENT_POSITION_FOLLOWING);
    view.stop();
});

test('a second poll does not create a second host and does not re-order the review', async () => {
    renderAgentView();
    const view = createApprovalView({ config: makeConfig() });
    view.noticeEnvelope(structuredClone(APPROVAL_ENVELOPE));
    await flushPromises();

    const panel = document.getElementById('webmcp-agent-view');
    const orderAfterFirst = [...panel.children].map((el) => el.id);
    expect(document.querySelectorAll('#webmcp-approval-list')).toHaveLength(1);
    expect(document.querySelector('#webmcp-approval-list article')).toBeTruthy();

    await vi.advanceTimersByTimeAsync(2000);
    await flushPromises();

    expect(document.querySelectorAll('#webmcp-approval-list')).toHaveLength(1);
    expect([...panel.children].map((el) => el.id)).toEqual(orderAfterFirst);
    view.stop();
});


