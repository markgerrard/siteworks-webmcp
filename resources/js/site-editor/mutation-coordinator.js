import { getJson, parseFieldKey, postFieldChange } from './editor-api.js';

export class EditorBusyError extends Error {
    constructor(message = 'Editor is busy') {
        super(message);
        this.name = 'EditorBusyError';
    }
}

function uuid() {
    if (crypto.randomUUID) {
        return crypto.randomUUID();
    }
    const b = crypto.getRandomValues(new Uint8Array(16));
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    const h = [...b].map((x) => x.toString(16).padStart(2, '0')).join('');

    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

function asKey(pageId) {
    return String(pageId);
}

function urlForPage(templateUrl, pageId) {
    return String(templateUrl ?? '').replace(/\/pages\/\d+\//, `/pages/${pageId}/`);
}

function revisionIdFrom(result) {
    return result?.state?.draft_revision_id ?? result?.data?.draft_revision_id ?? result?.revision_id ?? null;
}

export function createMutationCoordinator({ bridge, config }) {
    const revisions = Object.fromEntries(
        Object.entries(config.currentRevisionIds ?? {}).map(([pageId, id]) => [asKey(pageId), id]),
    );
    const epochs = Object.fromEntries(
        Object.entries(config.structureEpochs ?? {}).map(([pageId, n]) => [asKey(pageId), n]),
    );
    let compositionRev = Number.isFinite(Number(config.compositionRevision))
        ? Number(config.compositionRevision)
        : 0;

    const queue = new Map();
    let debounceTimer = null;
    let chain = Promise.resolve();
    let pendingAck = null;
    let loadGeneration = 0;

    function currentRevision(pageId) {
        const value = revisions[asKey(pageId)];

        return value == null ? null : value;
    }

    function setRevision(pageId, id) {
        revisions[asKey(pageId)] = id;
    }

    function currentEpoch(pageId) {
        const value = epochs[asKey(pageId)];

        return value == null ? 0 : value;
    }

    function setEpoch(pageId, n) {
        epochs[asKey(pageId)] = n;
    }

    function compositionRevision() {
        return compositionRev;
    }

    function setCompositionRevision(n) {
        compositionRev = n;
    }

    function ingestEnvelope(pageId, envelope) {
        if (! envelope || typeof envelope !== 'object') {
            return;
        }

        const state = envelope.state;
        if (state && typeof state === 'object') {
            if (state.draft_revision_id != null) {
                setRevision(pageId, state.draft_revision_id);
            }
            if (state.structure_epoch != null) {
                setEpoch(pageId, state.structure_epoch);
            }
            if (state.composition_revision != null) {
                setCompositionRevision(state.composition_revision);
            }
        }

        const staleId = envelope.error?.current_revision_id;
        if (staleId != null) {
            setRevision(pageId, staleId);
        }
    }

    function settlePending(handler) {
        if (! pendingAck) {
            return;
        }
        const current = pendingAck;
        pendingAck = null;
        if (current.timer) {
            clearTimeout(current.timer);
        }
        handler(current);
    }

    function waitForAck(requestId, pageId, timeoutMs, extra = {}) {
        settlePending((current) => {
            current.reject(Object.assign(new Error('superseded'), { code: 'superseded' }));
        });

        return new Promise((resolve, reject) => {
            const timer = setTimeout(() => {
                if (pendingAck?.requestId === requestId) {
                    pendingAck = null;
                    const err = Object.assign(new Error('ack-timeout'), { code: 'ack-timeout' });
                    reject(err);
                }
            }, timeoutMs);

            pendingAck = {
                requestId,
                pageId: asKey(pageId),
                expectedRevisionId: extra.expectedRevisionId,
                timer,
                expectedLoadGeneration: loadGeneration + 1,
                resolve,
                reject,
            };
        });
    }

    bridge.on('ready', (payload, meta) => {
        if (! pendingAck) {
            return;
        }
        if (meta?.inReplyTo !== pendingAck.requestId) {
            return;
        }
        if (asKey(payload?.pageId) !== pendingAck.pageId) {
            return;
        }

        const current = pendingAck;
        pendingAck = null;
        if (current.timer) {
            clearTimeout(current.timer);
        }
        current.resolve(payload);
    });

    const iframe = typeof document !== 'undefined'
        ? document.getElementById('editor-preview-iframe')
        : null;
    iframe?.addEventListener('load', () => {
        loadGeneration += 1;
        if (pendingAck && pendingAck.expectedLoadGeneration !== loadGeneration) {
            settlePending((current) => {
                current.reject(Object.assign(new Error('superseded'), { code: 'superseded' }));
            });
        }
    });

    function enqueueFieldSave(fieldKey, value, revisionId) {
        queue.set(fieldKey, { value, revisionId });
        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }
        debounceTimer = setTimeout(() => {
            debounceTimer = null;
            flush();
        }, 800);
    }

    /**
     * Returns true when every queued save committed. Callers that are about to let an EXTERNAL agent write
     * must check it: proceeding after a failed human save leaves that edit uncommitted while the agent's
     * response replaces or reloads the DOM it was sitting in, which loses the human's work silently.
     */
    async function flush() {
        if (debounceTimer) {
            clearTimeout(debounceTimer);
            debounceTimer = null;
        }
        const batch = Array.from(queue.entries());
        queue.clear();

        let allSaved = true;

        for (const [fieldKey, { value, revisionId }] of batch) {
            // The base is re-read per save rather than using the one captured at enqueue time. The first
            // save in a batch advances the page's revision, so a second field edited inside the same 800ms
            // debounce window would send the now-stale captured base and 409 — every multi-field edit
            // failed on its second field. The coordinator's map is the fresher source once a save lands;
            // the captured value is the fallback for the first save of a page.
            const pageId = parseFieldKey(fieldKey)?.pageId ?? null;
            const base = (pageId !== null ? currentRevision(pageId) : null) ?? revisionId;

            const saved = await postFieldChange(config, fieldKey, value, base);

            // A committed human edit advances that page's draft revision. The response used to be
            // discarded, so `revisions[pageId]` stayed pre-flush and the very next external write —
            // runExternalOnce flushes before dispatching — sent a stale base and 409'd. That made every
            // agent edit fail whenever someone had an uncommitted editor focused (WebMcpEditorTest's two
            // focused-editor cases). On a 409 the server's current_revision_id is the authoritative base.
            if (saved?.pageId != null) {
                const revision = saved.draft_revision_id ?? saved.current_revision_id;
                if (revision != null) {
                    setRevision(saved.pageId, revision);
                }
            }

            // postFieldChange returns null when the save failed outright, and a 409 body carries
            // current_revision_id instead of draft_revision_id.
            if (! saved || saved.draft_revision_id == null) {
                allSaved = false;
            }
        }

        return allSaved;
    }

    /**
     * Flushes queued human saves instead of discarding them. This used to `queue.clear()` outright, so a
     * structural agent write (add/remove/move/set_variant) silently destroyed whatever the human had typed
     * in the preceding 800ms — the edit was dropped unsent and then the preview reloaded over it. The
     * queue is drained to the server first; the boolean says whether it is safe to continue.
     */
    async function dropPendingSaves() {
        if (queue.size === 0) {
            if (debounceTimer) {
                clearTimeout(debounceTimer);
                debounceTimer = null;
            }

            return true;
        }

        return await flush();
    }

    async function handshake(pageId) {
        try {
            return await bridge.request('prepare-external-write', { pageId }, { timeoutMs: 2000 });
        } catch {
            throw new EditorBusyError('Editor is busy');
        }
    }

    // `channel` is threaded rather than hardcoded: this is called both by the human page switcher
    // (no channel) and by the agent's navigate_preview tool (webmcp).
    async function navigateTo(pageId, channel = null) {
        let url;
        try {
            const preview = await getJson(config, urlForPage(config.previewUrlUrl, pageId), channel);
            url = preview?.url;
            if (! url) {
                throw new Error('missing preview url');
            }
        } catch (error) {
            if (error instanceof EditorBusyError) {
                throw error;
            }
            throw new EditorBusyError('preview navigation failed; retry after navigate_preview');
        }

        const requestId = uuid();
        const ack = waitForAck(requestId, pageId, 10_000);
        bridge.sendToIframe('nav-request', { to: pageId, url, requestId });

        try {
            const ready = await ack;
            if (ready?.revisionId != null) {
                setRevision(pageId, ready.revisionId);
            }

            return { pageId: ready.pageId ?? pageId, revisionId: ready.revisionId };
        } catch {
            throw new EditorBusyError('preview navigation failed; retry after navigate_preview');
        }
    }

    async function reloadPreview(pageId, expectedRevisionId) {
        const requestId = uuid();
        const ack = waitForAck(requestId, pageId, 10_000, { expectedRevisionId });
        bridge.sendToIframe('reload-preview', { requestId, pageId });

        try {
            const ready = await ack;

            return ready ? 'applied' : 'unconfirmed';
        } catch {
            return 'unconfirmed';
        }
    }

    async function replaceSectionPreview(pageId, result) {
        const data = result?.data ?? {};
        const html = data.html ?? result?.html;
        const revisionId = revisionIdFrom(result);
        const storedIndex = data.stored_index ?? result?.stored_index;

        try {
            const reply = await bridge.request(
                'replace-section',
                { html, revisionId, storedIndex, pageId },
                { timeoutMs: 2000 },
            );
            if (reply?.deferred) {
                // Surface it to the parent shell (Task 28's banner listens for this); the write itself
                // already succeeded, so this is informational only.
                bridge.emit?.('preview-deferred', { reason: reply.reason ?? 'editing', pageId });

                return 'deferred';
            }

            return 'applied';
        } catch {
            return 'unconfirmed';
        }
    }

    async function runExternalOnce({ pageId, structural, fn }) {
        let ready = await handshake(pageId);

        if (asKey(ready?.pageId) !== asKey(pageId)) {
            await navigateTo(pageId, 'webmcp'); // runExternalOnce only ever runs an agent dispatch
            ready = await handshake(pageId);
            if (asKey(ready?.pageId) !== asKey(pageId)) {
                throw new EditorBusyError('Editor is busy');
            }
        }

        // A failed human save must stop the agent write. Previously flush()'s result was discarded, so a
        // 409 or server error left the human edit uncommitted while the agent's response replaced or
        // reloaded the DOM holding it — the edit vanished with no error surfaced to either party.
        // EditorBusyError is the retryable signal the tool front already understands.
        if (! await flush()) {
            throw new EditorBusyError('A pending edit could not be saved; retry once it is resolved');
        }

        const result = await fn();
        ingestEnvelope(pageId, result);

        if (result?.ok === false) {
            return { result, preview: 'unconfirmed' };
        }

        const preview = structural
            ? await reloadPreview(pageId, revisionIdFrom(result))
            : await replaceSectionPreview(pageId, result);

        return { result, preview };
    }

    function runExternal(args) {
        const run = chain.then(() => runExternalOnce(args));
        chain = run.then(() => undefined, () => undefined);

        return run;
    }

    return {
        enqueueFieldSave,
        flush,
        dropPendingSaves,
        runExternal,
        navigateTo,
        currentRevision,
        setRevision,
        currentEpoch,
        setEpoch,
        compositionRevision,
        setCompositionRevision,
    };
}
