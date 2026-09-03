import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { bootParentEditor } from '../parent-entry.js';

function createFakeBridge() {
    const handlers = new Map();

    return {
        on: vi.fn((type, handler) => {
            const list = handlers.get(type) ?? [];
            list.push(handler);
            handlers.set(type, list);
        }),
        sendToIframe: vi.fn(),
        request: vi.fn(async () => ({ pageId: 1 })),
        emit(type, payload = {}) {
            for (const handler of handlers.get(type) ?? []) {
                handler(payload, { id: 'evt', type, raw: {} });
            }
        },
    };
}

function makeConfig(overrides = {}) {
    return {
        csrfToken: 'csrf-token',
        siteName: 'Acme',
        pageId: 1,
        capabilities: ['edit', 'publish', 'media'],
        currentRevisionIds: { 1: 10 },
        structureEpochs: { 1: 0 },
        compositionRevision: 0,
        publishSummaryUrl: '/sites/1/publish-summary',
        ...overrides,
    };
}

function shellHtml() {
    return `
        <div id="editor-shell-root">
            <header><div data-editor-toolbar></div></header>
            <iframe id="editor-preview-iframe"></iframe>
        </div>
    `;
}

async function settleDeferredImports() {
    await Promise.resolve();
    await Promise.resolve();
}

beforeEach(() => {
    document.body.innerHTML = shellHtml();
    globalThis.fetch = vi.fn(async () => ({
        ok: true,
        json: async () => ({ pending_pages: [], composition_pending: false, pending_asset_selections: [] }),
    }));
});

afterEach(() => {
    document.body.replaceChildren();
    delete window.__siteworks_webmcp__;
    delete window.__siteworks_test_probe__;
    delete window.__siteworks_editor_shell_config__;
});

test('WebMCP registry import happens only under the agent_tools flag', async () => {
    bootParentEditor({
        bridge: createFakeBridge(),
        config: makeConfig({ capabilities: ['edit', 'publish', 'media'] }),
    });
    await settleDeferredImports();
    expect(window.__siteworks_webmcp__).toBeUndefined();

    bootParentEditor({
        bridge: createFakeBridge(),
        config: makeConfig({ capabilities: ['edit', 'publish', 'media', 'agent_tools'] }),
    });
    await vi.waitFor(() => {
        expect(window.__siteworks_webmcp__).toEqual(expect.objectContaining({
            sync: expect.any(Function),
        }));
    });
});

test('init sends config capabilities including agent_tools when present', () => {
    const capabilities = ['edit', 'publish', 'media', 'agent_tools'];
    const bridge = createFakeBridge();
    bootParentEditor({ bridge, config: makeConfig({ capabilities }) });

    bridge.emit('ready', { pageId: 1 });

    expect(bridge.sendToIframe).toHaveBeenCalledWith('init', {
        csrfToken: 'csrf-token',
        capabilities: ['edit', 'publish', 'media', 'agent_tools'],
    });
});

test('init sends config capabilities without agent_tools when absent', () => {
    const capabilities = ['edit', 'publish', 'media'];
    const bridge = createFakeBridge();
    bootParentEditor({ bridge, config: makeConfig({ capabilities }) });

    bridge.emit('ready', { pageId: 1 });

    expect(bridge.sendToIframe).toHaveBeenCalledWith('init', {
        csrfToken: 'csrf-token',
        capabilities: ['edit', 'publish', 'media'],
    });
    expect(bridge.sendToIframe.mock.calls[0][1].capabilities).not.toContain('agent_tools');
});

test('preview-deferred with reason editing shows a dismissible banner', () => {
    const bridge = createFakeBridge();
    bootParentEditor({ bridge, config: makeConfig() });

    bridge.emit('preview-deferred', { reason: 'editing' });

    const banner = document.getElementById('preview-deferred-banner');
    expect(banner.textContent).toContain('Preview will refresh when you finish editing');

    banner.querySelector('button').click();
    expect(document.getElementById('preview-deferred-banner')).toBeNull();
});

test('preview-deferred with reason page_mismatch shows its banner', () => {
    const bridge = createFakeBridge();
    bootParentEditor({ bridge, config: makeConfig() });

    bridge.emit('preview-deferred', { reason: 'page_mismatch' });

    expect(document.getElementById('preview-deferred-banner').textContent)
        .toContain('Preview will refresh when you return to that page');
});

test('preview-deferred does not throw when the shell container is missing', () => {
    document.body.innerHTML = '<div data-editor-toolbar></div><iframe id="editor-preview-iframe"></iframe>';
    const bridge = createFakeBridge();
    bootParentEditor({ bridge, config: makeConfig() });

    expect(() => bridge.emit('preview-deferred', { reason: 'editing' })).not.toThrow();
    expect(() => bridge.emit('preview-deferred', { reason: 'page_mismatch' })).not.toThrow();
    expect(document.getElementById('preview-deferred-banner')).toBeNull();
});

test('portal-shop with agent_tools installs the shop WebMCP stub and does not mount the editor toolbar', async () => {
    vi.resetModules();
    document.body.innerHTML = '<div id="app"></div>';
    const installWebMCP = vi.fn();
    vi.doMock('../webmcp/index.js', () => ({ installWebMCP }));

    window.__siteworks_editor_shell_config__ = {
        surface: 'portal-shop',
        capabilities: ['edit', 'media', 'agent_tools'],
        catalogueRevision: 3,
        compositionRevision: 0,
        csrfToken: 'csrf-token',
    };

    await import('../parent-entry.js');
    await vi.waitFor(() => {
        expect(installWebMCP).toHaveBeenCalled();
    });

    const call = installWebMCP.mock.calls[0][0];
    expect(call.config.surface).toBe('portal-shop');
    expect(call.coordinator.catalogueRevision()).toBe(3);
    call.coordinator.setCatalogueRevision(9);
    expect(call.coordinator.catalogueRevision()).toBe(9);
    expect(document.getElementById('site-editor-toolbar')).toBeNull();
});

test('portal-shop without agent_tools does not install WebMCP or mount the editor toolbar', async () => {
    vi.resetModules();
    document.body.innerHTML = '<div id="app"></div>';
    const installWebMCP = vi.fn();
    vi.doMock('../webmcp/index.js', () => ({ installWebMCP }));

    window.__siteworks_editor_shell_config__ = {
        surface: 'portal-shop',
        capabilities: ['edit', 'media'],
        catalogueRevision: 0,
        csrfToken: 'csrf-token',
    };

    await import('../parent-entry.js');
    await settleDeferredImports();

    expect(installWebMCP).not.toHaveBeenCalled();
    expect(document.getElementById('site-editor-toolbar')).toBeNull();
});
