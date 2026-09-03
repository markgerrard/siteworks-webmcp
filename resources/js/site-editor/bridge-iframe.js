// resources/js/site-editor/bridge-iframe.js
//
// Iframe-side bridge. Emits postMessage events to the parent admin shell.
// NEVER fetches admin endpoints directly — all writes go via parent.

const PROTOCOL = 'siteworks-editor-1';

// crypto.randomUUID only exists in secure contexts (https / localhost).
// The browser-test server runs plain http on a mapped domain, which is NOT
// a secure context — fall back to getRandomValues, available everywhere.
function uuid() {
    if (crypto.randomUUID) {
        return crypto.randomUUID();
    }
    const b = crypto.getRandomValues(new Uint8Array(16));
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    const h = [...b].map(x => x.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

export class BridgeTimeoutError extends Error {
    constructor(message = 'Bridge request timed out') {
        super(message);
        this.name = 'BridgeTimeoutError';
    }
}

export class IframeBridge {
    constructor(config = window.__siteworks_editor_iframe_config__) {
        this.config = config ?? {};
        this.parentOrigin = this.config.parentOrigin ?? '';
        this.handlers = new Map();
        this.pending = new Map();
        if (this.parentOrigin) {
            this.bindIncoming();
        }
    }

    bindIncoming() {
        window.addEventListener('message', (event) => {
            if (event.origin !== this.parentOrigin) {
                console.warn('[bridge-iframe] dropping message from unexpected origin', event.origin);
                return;
            }
            // Origin is not identity: a sibling frame on the shell origin could otherwise post a
            // `replace-section` and put its own markup into this document. Only our embedder is heard.
            if (event.source !== window.parent) {
                console.warn('[bridge-iframe] dropping message from an unexpected window');
                return;
            }
            const data = event.data;
            if (! data || data.protocol !== PROTOCOL) {
                return;
            }
            this.dispatch(data);
        });
    }

    dispatch(data) {
        const meta = {
            id: data.id,
            type: data.type,
            inReplyTo: data.inReplyTo,
            raw: data,
        };

        if (data.inReplyTo != null && this.pending.has(data.inReplyTo)) {
            const pending = this.pending.get(data.inReplyTo);
            this.pending.delete(data.inReplyTo);
            if (pending.timer) {
                clearTimeout(pending.timer);
            }
            pending.resolve(data.payload);
        }

        const handlers = this.handlers.get(data.type);
        if (handlers) {
            for (const handler of [...handlers]) {
                handler(data.payload, meta);
            }
        }
    }

    emit(type, payload = {}, { inReplyTo } = {}) {
        const message = { protocol: PROTOCOL, id: uuid(), type, payload };
        if (inReplyTo != null && inReplyTo !== '') {
            message.inReplyTo = inReplyTo;
        }
        window.parent.postMessage(message, this.parentOrigin);
    }

    request(type, payload = {}, { timeoutMs } = {}) {
        const id = uuid();
        const message = { protocol: PROTOCOL, id, type, payload };
        const promise = new Promise((resolve, reject) => {
            const timer = timeoutMs != null
                ? setTimeout(() => {
                    this.pending.delete(id);
                    reject(new BridgeTimeoutError(`Timed out waiting for reply to ${type}`));
                }, timeoutMs)
                : null;
            this.pending.set(id, { resolve, reject, timer });
        });
        window.parent.postMessage(message, this.parentOrigin);

        return promise;
    }

    reply(meta, type, payload = {}) {
        this.emit(type, payload, { inReplyTo: meta.id });
    }

    on(type, handler) {
        const list = this.handlers.get(type) ?? [];
        list.push(handler);
        this.handlers.set(type, list);
    }
}

export const bridge = new IframeBridge();

// Tell parent we're ready. inReplyTo is an optional envelope field (Task 26
// stamps a reload/nav ack here); current callers pass two arguments.
if (window.__siteworks_editor_iframe_config__) {
    // A cross-site iframe may have storage denied (Safari ITP / strict modes) — the accessor THROWS there.
    // Losing the ack only costs one uncorrelated reload; letting it throw would abort boot and the editor
    // would never initialise.
    let storedRequestId = null;
    try {
        storedRequestId = window.sessionStorage.getItem('siteworks-editor:reload-ack');
        window.sessionStorage.removeItem('siteworks-editor:reload-ack');
    } catch (_) {
        storedRequestId = null;
    }
    bridge.emit('ready', {
        siteId: bridge.config.siteId,
        pageId: bridge.config.pageId,
        revisionId: bridge.config.currentRevisionId,
    }, { inReplyTo: storedRequestId });
}
