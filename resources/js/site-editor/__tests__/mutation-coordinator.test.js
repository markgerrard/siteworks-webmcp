import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { ParentBridge, BridgeTimeoutError } from '../bridge-parent.js';
import { IframeBridge } from '../bridge-iframe.js';
import { createMutationCoordinator, EditorBusyError } from '../mutation-coordinator.js';
import { getJson, postFieldChange, postOperation } from '../editor-api.js';

vi.mock('../editor-api.js', async (importOriginal) => {
    const actual = await importOriginal();

    return {
        ...actual,
        postFieldChange: vi.fn(actual.postFieldChange),
        getJson: vi.fn(actual.getJson),
    };
});

const PROTOCOL = 'siteworks-editor-1';
const IFRAME_ORIGIN = 'https://preview.example';
const PARENT_ORIGIN = 'https://admin.example';
const PAGE = 5;

function okResult(overrides = {}) {
    return {
        ok: true,
        data: { html: '<section data-section-index="0"></section>', stored_index: 0, ...(overrides.data ?? {}) },
        state: {
            draft_revision_id: 11,
            structure_epoch: 2,
            composition_revision: 4,
            ...(overrides.state ?? {}),
        },
    };
}

function makeConfig(overrides = {}) {
    return {
        csrfToken: 'csrf-token',
        compositionRevision: 3,
        currentRevisionIds: { 1: 10, 5: 20 },
        structureEpochs: { 1: 1, 5: 2 },
        previewUrlUrl: '/sites/9/pages/0/preview-url',
        fieldUpdateUrl: '/sites/9/pages/0/fields',
        ...overrides,
    };
}

function createFakeBridge() {
    const handlers = new Map();

    const bridge = {
        request: vi.fn(),
        sendToIframe: vi.fn(),
        on: vi.fn((type, handler) => {
            const list = handlers.get(type) ?? [];
            list.push(handler);
            handlers.set(type, list);
        }),
        emit(type, payload, meta = {}) {
            const envelope = {
                protocol: PROTOCOL,
                id: meta.id ?? 'evt',
                type,
                payload,
                inReplyTo: meta.inReplyTo,
            };
            for (const handler of handlers.get(type) ?? []) {
                handler(payload, {
                    id: envelope.id,
                    type,
                    inReplyTo: envelope.inReplyTo,
                    raw: envelope,
                });
            }
        },
    };

    return bridge;
}

let bridge;
let coordinator;
const originalFetch = globalThis.fetch;

beforeEach(() => {
    document.body.replaceChildren();
    postFieldChange.mockReset();
    // postFieldChange resolves to {pageId, ...body} on a committed save; `undefined` is its
    // failure return, and flush() now treats that as a failed save (it must, or an agent write
    // proceeds over an uncommitted human edit).
    postFieldChange.mockResolvedValue({ pageId: '1', draft_revision_id: 11 });
    getJson.mockReset();
    getJson.mockResolvedValue({ url: 'https://preview.example/sites/9/pages/5' });
    bridge = createFakeBridge();
    coordinator = createMutationCoordinator({ bridge, config: makeConfig() });
});

afterEach(() => {
    vi.useRealTimers();
    globalThis.fetch = originalFetch;
});

test('two runExternal calls serialise', async () => {
    let releaseFirst;
    const firstHandshake = new Promise((resolve) => {
        releaseFirst = resolve;
    });
    let handshakeCount = 0;

    bridge.request.mockImplementation(async (type) => {
        if (type === 'prepare-external-write') {
            handshakeCount += 1;
            if (handshakeCount === 1) {
                return firstHandshake;
            }

            return { pageId: PAGE };
        }

        return { applied: true };
    });

    const order = [];
    const first = coordinator.runExternal({
        pageId: PAGE,
        structural: false,
        fn: async () => {
            order.push('fn1');

            return okResult();
        },
    });
    const second = coordinator.runExternal({
        pageId: PAGE,
        structural: false,
        fn: async () => {
            order.push('fn2');

            return okResult();
        },
    });

    await Promise.resolve();
    await Promise.resolve();

    expect(order).toEqual([]);
    expect(handshakeCount).toBe(1);

    releaseFirst({ pageId: PAGE });
    await first;
    await second;

    expect(order).toEqual(['fn1', 'fn2']);
    expect(handshakeCount).toBe(2);
});

test('handshake runs before flush', async () => {
    const events = [];
    postFieldChange.mockImplementation(async () => {
        events.push('flush');

        return { pageId: '1', draft_revision_id: 11 };
    });
    bridge.request.mockImplementation(async (type) => {
        if (type === 'prepare-external-write') {
            events.push('handshake');

            return { pageId: PAGE };
        }

        events.push('preview');

        return { applied: true };
    });

    coordinator.enqueueFieldSave('page.5.section.0.title', 'Hello', 20);

    await coordinator.runExternal({
        pageId: PAGE,
        structural: false,
        fn: async () => {
            events.push('fn');

            return okResult();
        },
    });

    expect(events[0]).toBe('handshake');
    expect(events.indexOf('handshake')).toBeLessThan(events.indexOf('flush'));
    expect(events.indexOf('flush')).toBeLessThan(events.indexOf('fn'));
});

test('handshake timeout rejects with EditorBusyError and fn is never called', async () => {
    bridge.request.mockImplementation(async (type) => {
        if (type === 'prepare-external-write') {
            throw new BridgeTimeoutError('timed out waiting for prepare-external-write');
        }

        return { applied: true };
    });

    const fn = vi.fn(async () => okResult());

    await expect(coordinator.runExternal({ pageId: PAGE, structural: false, fn }))
        .rejects.toBeInstanceOf(EditorBusyError);

    expect(fn).not.toHaveBeenCalled();
    expect(postFieldChange).not.toHaveBeenCalled();
});

test('page mismatch triggers navigateTo and a second handshake before fn', async () => {
    const events = [];
    let prepares = 0;

    bridge.request.mockImplementation(async (type) => {
        if (type === 'prepare-external-write') {
            prepares += 1;
            events.push(`handshake-${prepares}`);

            return prepares === 1 ? { pageId: 1 } : { pageId: PAGE };
        }

        events.push('preview');

        return { applied: true };
    });

    bridge.sendToIframe.mockImplementation((type, payload) => {
        if (type === 'nav-request') {
            queueMicrotask(() => {
                events.push('navigated');
                bridge.emit(
                    'ready',
                    { pageId: payload.to, revisionId: 8 },
                    { inReplyTo: payload.requestId },
                );
            });
        }
    });

    const fn = vi.fn(async () => {
        events.push('fn');

        return okResult();
    });

    await coordinator.runExternal({ pageId: PAGE, structural: false, fn });

    expect(events).toEqual(['handshake-1', 'navigated', 'handshake-2', 'fn', 'preview']);
    expect(fn).toHaveBeenCalledOnce();
    expect(getJson).toHaveBeenCalled();
});

test('failed navigation rejects with EditorBusyError before fn', async () => {
    bridge.request.mockImplementation(async (type) => {
        if (type === 'prepare-external-write') {
            return { pageId: 1 };
        }

        return { applied: true };
    });
    getJson.mockRejectedValue(new Error('preview url failed'));

    const fn = vi.fn(async () => okResult());

    await expect(coordinator.runExternal({ pageId: PAGE, structural: false, fn }))
        .rejects.toSatisfy((error) => (
            error instanceof EditorBusyError
            && error.message === 'preview navigation failed; retry after navigate_preview'
        ));

    expect(fn).not.toHaveBeenCalled();
});

test('a ready whose inReplyTo does not match the pending nav request is ignored', async () => {
    let prepares = 0;

    bridge.request.mockImplementation(async (type) => {
        if (type === 'prepare-external-write') {
            prepares += 1;

            return prepares === 1 ? { pageId: 1 } : { pageId: PAGE };
        }

        return { applied: true };
    });

    const fn = vi.fn(async () => okResult());
    const pending = coordinator.runExternal({ pageId: PAGE, structural: false, fn });

    await vi.waitFor(() => {
        expect(bridge.sendToIframe).toHaveBeenCalledWith(
            'nav-request',
            expect.objectContaining({ to: PAGE }),
        );
    });

    const navPayload = bridge.sendToIframe.mock.calls.find(([type]) => type === 'nav-request')[1];

    bridge.emit('ready', { pageId: PAGE, revisionId: 1 }, { inReplyTo: 'not-the-request' });
    await Promise.resolve();
    await Promise.resolve();
    expect(fn).not.toHaveBeenCalled();

    bridge.emit('ready', { pageId: PAGE, revisionId: 1 }, { inReplyTo: navPayload.requestId });
    await pending;
    expect(fn).toHaveBeenCalledOnce();
});

test('a successful fn whose preview times out resolves with preview unconfirmed', async () => {
    bridge.request.mockImplementation(async (type) => {
        if (type === 'prepare-external-write') {
            return { pageId: PAGE };
        }

        if (type === 'replace-section') {
            throw new BridgeTimeoutError('timed out waiting for replace-section');
        }

        return { applied: true };
    });

    const envelope = okResult();
    const { result, preview } = await coordinator.runExternal({
        pageId: PAGE,
        structural: false,
        fn: async () => envelope,
    });

    expect(result).toEqual(envelope);
    expect(preview).toBe('unconfirmed');
});

test('409 refreshes the stored base', async () => {
    bridge.request.mockImplementation(async (type) => {
        if (type === 'prepare-external-write') {
            return { pageId: PAGE };
        }

        return { applied: true };
    });

    expect(coordinator.currentRevision(PAGE)).toBe(20);

    const { result, preview } = await coordinator.runExternal({
        pageId: PAGE,
        structural: false,
        fn: async () => ({
            ok: false,
            error: { code: 'stale_revision', message: 'stale', current_revision_id: 77 },
            state: { draft_revision_id: 77, structure_epoch: 6, composition_revision: 9 },
        }),
    });

    expect(coordinator.currentRevision(PAGE)).toBe(77);
    expect(coordinator.currentEpoch(PAGE)).toBe(6);
    expect(coordinator.compositionRevision()).toBe(9);
    expect(result.error.current_revision_id).toBe(77);
    expect(preview).not.toBeUndefined();
});

test('on() multi-handler keeps both handlers', () => {
    const iframe = document.createElement('iframe');
    iframe.id = 'editor-preview-iframe';
    document.body.append(iframe);

    const parent = new ParentBridge({ iframeOrigin: IFRAME_ORIGIN });
    const first = vi.fn();
    const second = vi.fn();
    parent.on('ready', first);
    parent.on('ready', second);

    const payload = { pageId: 1 };
    const data = { protocol: PROTOCOL, id: 'm1', type: 'ready', payload };

    // R5: the bridge now pins event.source to its own iframe, as a real browser always sets it.
    window.dispatchEvent(new MessageEvent('message', { origin: IFRAME_ORIGIN, data, source: iframe.contentWindow }));

    expect(first).toHaveBeenCalledTimes(1);
    expect(second).toHaveBeenCalledTimes(1);
    expect(first.mock.calls[0][0]).toEqual(payload);
    expect(second.mock.calls[0][0]).toEqual(payload);
    expect(first.mock.calls[0][1]).toMatchObject({
        id: 'm1',
        type: 'ready',
        raw: data,
    });
});

test('request resolves the reply payload when inReplyTo matches the envelope id', async () => {
    const iframe = document.createElement('iframe');
    iframe.id = 'editor-preview-iframe';
    document.body.append(iframe);
    const parent = new ParentBridge({ iframeOrigin: IFRAME_ORIGIN });
    const postMessage = vi.spyOn(iframe.contentWindow, 'postMessage');

    const pending = parent.request('prepare-external-write', { pageId: PAGE }, { timeoutMs: 1000 });
    expect(postMessage).toHaveBeenCalled();

    const sent = postMessage.mock.calls[0][0];
    expect(sent).toMatchObject({
        protocol: PROTOCOL,
        type: 'prepare-external-write',
        payload: { pageId: PAGE },
    });
    expect(sent).not.toHaveProperty('inReplyTo');
    expect(sent.payload).not.toHaveProperty('inReplyTo');

    window.dispatchEvent(new MessageEvent('message', {
        origin: IFRAME_ORIGIN,
        source: iframe.contentWindow,
        data: {
            protocol: PROTOCOL,
            id: 'reply-1',
            type: 'external-write-ready',
            payload: { pageId: PAGE, revisionId: 20 },
            inReplyTo: sent.id,
        },
    }));

    await expect(pending).resolves.toEqual({ pageId: PAGE, revisionId: 20 });
});

test('request rejects with BridgeTimeoutError when no matching inReplyTo arrives', async () => {
    vi.useFakeTimers();
    const iframe = document.createElement('iframe');
    iframe.id = 'editor-preview-iframe';
    document.body.append(iframe);
    const parent = new ParentBridge({ iframeOrigin: IFRAME_ORIGIN });

    const pending = parent.request('prepare-external-write', { pageId: PAGE }, { timeoutMs: 2000 });
    const expectation = expect(pending).rejects.toBeInstanceOf(BridgeTimeoutError);

    await vi.advanceTimersByTimeAsync(2000);
    await expectation;
});

test('reply and iframe emit put inReplyTo on the envelope, never inside payload', () => {
    const iframe = document.createElement('iframe');
    iframe.id = 'editor-preview-iframe';
    document.body.append(iframe);
    const parent = new ParentBridge({ iframeOrigin: IFRAME_ORIGIN });
    const parentPost = vi.spyOn(iframe.contentWindow, 'postMessage');

    parent.reply({ id: 'orig-1' }, 'external-write-ready', { pageId: PAGE });
    const parentMessage = parentPost.mock.calls[0][0];
    expect(parentMessage.inReplyTo).toBe('orig-1');
    expect(parentMessage.type).toBe('external-write-ready');
    expect(parentMessage.payload).toEqual({ pageId: PAGE });
    expect(parentMessage.payload).not.toHaveProperty('inReplyTo');

    const child = new IframeBridge({
        parentOrigin: PARENT_ORIGIN,
        siteId: 9,
        pageId: PAGE,
        currentRevisionId: 20,
    });
    const childPost = vi.spyOn(window.parent, 'postMessage');
    child.emit('ready', { siteId: 9, pageId: PAGE }, { inReplyTo: 'nav-ack' });
    const childMessage = childPost.mock.calls[0][0];
    expect(childMessage.inReplyTo).toBe('nav-ack');
    expect(childMessage.payload).toEqual({ siteId: 9, pageId: PAGE });
    expect(childMessage.payload).not.toHaveProperty('inReplyTo');
});

test('postOperation merges supplied revision keys, sets CSRF, and resolves 409/429 envelopes', async () => {
    const fetchMock = vi.fn(async () => ({
        status: 409,
        ok: false,
        json: async () => ({
            ok: false,
            error: { code: 'stale_revision', current_revision_id: 12 },
            state: { draft_revision_id: 12 },
        }),
    }));
    globalThis.fetch = fetchMock;

    const envelope = await postOperation(
        { csrfToken: 'csrf-token' },
        '/sites/9/pages/5/sections',
        { op: 'remove', stored_index: 0 },
        { revisionBase: 10, structureEpoch: 2 },
    );

    expect(envelope.error.current_revision_id).toBe(12);
    expect(fetchMock).toHaveBeenCalledWith('/sites/9/pages/5/sections', expect.objectContaining({
        method: 'POST',
        credentials: 'same-origin',
    }));
    const init = fetchMock.mock.calls[0][1];
    expect(init.headers['X-CSRF-TOKEN']).toBe('csrf-token');
    expect(String(init.headers['X-Page-Revision-Base'])).toBe('10');
    expect(JSON.parse(init.body)).toEqual({
        op: 'remove',
        stored_index: 0,
        revision_base: 10,
        structure_epoch: 2,
    });

    fetchMock.mockImplementationOnce(async () => ({
        status: 429,
        ok: false,
        json: async () => ({ ok: false, error: { code: 'quota_exceeded' } }),
    }));

    const quota = await postOperation(
        { csrfToken: 'csrf-token' },
        '/sites/9/generate-logo',
        { page_type: 'home' },
        { compositionRevision: 3 },
    );
    expect(quota.error.code).toBe('quota_exceeded');
    expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual({
        page_type: 'home',
        composition_revision: 3,
    });
    expect(fetchMock.mock.calls[1][1].headers['X-Page-Revision-Base']).toBeUndefined();
});

test('a deferred preview is announced on the bridge so the parent banner is reachable', async () => {
    let announced = null;
    bridge.on('preview-deferred', (payload) => { announced = payload; });
    bridge.request.mockImplementation(async (type) => {
        if (type === 'prepare-external-write') {
            return { pageId: PAGE };
        }

        if (type === 'replace-section') {
            return { deferred: true, reason: 'editing' };
        }

        return { applied: true };
    });

    const envelope = okResult();
    const { result, preview } = await coordinator.runExternal({
        pageId: PAGE,
        structural: false,
        fn: async () => envelope,
    });

    expect(result).toEqual(envelope);
    expect(preview).toBe('deferred');
    expect(announced).toEqual(expect.objectContaining({ reason: 'editing' }));
});

/*
 * Regression findings on the coordinator. All three were real and all three could lose a
 * human's work or break a primary path, so they are pinned here rather than left to the browser suite.
 */

test('a second field edited in the same debounce window uses the post-save base, not the captured one', async () => {
    // The first save advances the page revision; the second entry still carried the base captured at
    // enqueue time, so every multi-field edit 409'd on its second field.
    const bases = [];
    postFieldChange.mockImplementation(async (config, fieldKey, value, revisionId) => {
        bases.push(revisionId);

        return { pageId: String(PAGE), draft_revision_id: 30 + bases.length };
    });

    coordinator.enqueueFieldSave(`page.${PAGE}.section.0.title`, 'One', 20);
    coordinator.enqueueFieldSave(`page.${PAGE}.section.0.subtitle`, 'Two', 20);

    await coordinator.dropPendingSaves(); // drains the queue through flush()

    expect(bases[0]).toBe(20);
    // the second save must NOT reuse 20 — it takes the revision the first save returned
    expect(bases[1]).toBe(31);
});

test('a failed human save aborts the agent write instead of letting it overwrite', async () => {
    bridge.request.mockImplementation(async (type) => (
        type === 'prepare-external-write' ? { pageId: PAGE } : { applied: true }
    ));
    postFieldChange.mockResolvedValue(null); // the failure return
    coordinator.enqueueFieldSave(`page.${PAGE}.section.0.title`, 'Uncommitted', 20);

    let dispatched = false;

    await expect(coordinator.runExternal({
        pageId: PAGE,
        structural: false,
        fn: async () => { dispatched = true; return okResult(); },
    })).rejects.toBeInstanceOf(EditorBusyError);

    expect(dispatched).toBe(false);
});

test('dropPendingSaves drains the queue to the server instead of discarding it', async () => {
    const sent = [];
    postFieldChange.mockImplementation(async (config, fieldKey, value) => {
        sent.push(value);

        return { pageId: String(PAGE), draft_revision_id: 41 };
    });

    coordinator.enqueueFieldSave(`page.${PAGE}.section.0.title`, 'Typed just now', 20);

    const drained = await coordinator.dropPendingSaves();

    expect(drained).toBe(true);
    expect(sent).toEqual(['Typed just now']); // previously the queue was cleared unsent
});

test('dropPendingSaves reports failure so a structural write can refuse to proceed', async () => {
    postFieldChange.mockResolvedValue(null);
    coordinator.enqueueFieldSave(`page.${PAGE}.section.0.title`, 'Typed just now', 20);

    expect(await coordinator.dropPendingSaves()).toBe(false);
});
