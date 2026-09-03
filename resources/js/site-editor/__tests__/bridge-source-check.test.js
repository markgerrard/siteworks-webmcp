import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { ParentBridge } from '../bridge-parent.js';
import { IframeBridge } from '../bridge-iframe.js';

/*
 * Both bridges authenticated the message ORIGIN but not the sending WINDOW, so any
 * other window on the allowlisted origin holding a reference to the shell or the preview could post a
 * valid protocol message and be believed — forged `field-changed` into the save coordinator on the parent
 * side, forged `replace-section` HTML into the iframe on the other. A confused deputy, closed by pinning
 * event.source to the one window each side is allowed to hear from.
 */

const PROTOCOL = 'siteworks-editor-1';
const IFRAME_ORIGIN = 'http://preview.test';
const PARENT_ORIGIN = 'http://shell.test';

let iframe;

beforeEach(() => {
    document.body.replaceChildren();
    iframe = document.createElement('iframe');
    iframe.id = 'editor-preview-iframe';
    document.body.append(iframe);
});

afterEach(() => {
    document.body.replaceChildren();
});

function post({ origin, source, type = 'field-changed' }) {
    window.dispatchEvent(new MessageEvent('message', {
        data: { protocol: PROTOCOL, id: 'msg-1', type, payload: { value: 'forged' } },
        origin,
        source,
    }));
}

test('the parent bridge ignores a same-origin message from a window other than its iframe', () => {
    const bridge = new ParentBridge({ iframeOrigin: IFRAME_ORIGIN });
    const handler = vi.fn();
    bridge.on('field-changed', handler);

    post({ origin: IFRAME_ORIGIN, source: window });

    expect(handler).not.toHaveBeenCalled();
});

test('the parent bridge still accepts a message from its own iframe', () => {
    const bridge = new ParentBridge({ iframeOrigin: IFRAME_ORIGIN });
    const handler = vi.fn();
    bridge.on('field-changed', handler);

    post({ origin: IFRAME_ORIGIN, source: iframe.contentWindow });

    expect(handler).toHaveBeenCalledTimes(1);
    expect(handler.mock.calls[0][0]).toEqual({ value: 'forged' });
});

test('the iframe bridge ignores a same-origin message from a window other than its parent', () => {
    const bridge = new IframeBridge({ parentOrigin: PARENT_ORIGIN });
    const handler = vi.fn();
    bridge.on('replace-section', handler);

    // a sibling frame on the shell origin, not window.parent
    post({ origin: PARENT_ORIGIN, source: iframe.contentWindow, type: 'replace-section' });

    expect(handler).not.toHaveBeenCalled();
});

test('the iframe bridge still accepts a message from its parent window', () => {
    const bridge = new IframeBridge({ parentOrigin: PARENT_ORIGIN });
    const handler = vi.fn();
    bridge.on('replace-section', handler);

    post({ origin: PARENT_ORIGIN, source: window.parent, type: 'replace-section' });

    expect(handler).toHaveBeenCalledTimes(1);
});
