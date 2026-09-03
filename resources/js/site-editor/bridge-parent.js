// resources/js/site-editor/bridge-parent.js

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

export class ParentBridge {
    constructor(config) {
        this.config = config;
        this.iframeOrigin = config.iframeOrigin;
        this.iframe = document.getElementById('editor-preview-iframe');
        this.handlers = new Map();
        this.pending = new Map();
        if (this.iframeOrigin) {
            this.bindIncoming();
        }
    }

    bindIncoming() {
        window.addEventListener('message', (event) => {
            if (event.origin !== this.iframeOrigin) {
                console.warn('[bridge-parent] dropping message from unexpected origin', event.origin);
                return;
            }
            // Origin is not identity: any OTHER window on the allowlisted preview origin that holds a
            // reference to this shell could post a valid-looking envelope and drive the save coordinator.
            // We only ever hear from the one iframe we opened.
            if (event.source !== this.iframe?.contentWindow) {
                console.warn('[bridge-parent] dropping message from an unexpected window');
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

    sendToIframe(type, payload = {}) {
        const message = { protocol: PROTOCOL, id: uuid(), type, payload };
        this.iframe.contentWindow.postMessage(message, this.iframeOrigin);
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
        this.iframe.contentWindow.postMessage(message, this.iframeOrigin);

        return promise;
    }

    reply(meta, type, payload = {}) {
        const message = {
            protocol: PROTOCOL,
            id: uuid(),
            type,
            payload,
            inReplyTo: meta.id,
        };
        this.iframe.contentWindow.postMessage(message, this.iframeOrigin);
    }

    on(type, handler) {
        const list = this.handlers.get(type) ?? [];
        list.push(handler);
        this.handlers.set(type, list);
    }
}

export const bridge = new ParentBridge(window.__siteworks_editor_shell_config__ ?? { iframeOrigin: '' });
