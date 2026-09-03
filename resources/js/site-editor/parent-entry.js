// resources/js/site-editor/parent-entry.js

import { bridge } from './bridge-parent.js';
import { mountToolbar } from './toolbar.js';
import { mountImagePicker, portraitUploadUrl } from './image-picker.js';
import { mountFormPanel } from './form-panel.js';
import { mountSectionsPanel } from './sections-panel.js';
import { createMutationCoordinator } from './mutation-coordinator.js';

export function bootParentEditor({ bridge, config }) {
    const coordinator = createMutationCoordinator({ bridge, config });

    mountToolbar({ bridge, config });

    if (config.capabilities?.includes('agent_tools')) {
        import('./webmcp/index.js').then(({ installWebMCP }) => {
            installWebMCP({ bridge, config, coordinator });
        });
    }

    const sectionsPanel = config.capabilities?.includes('editor_ui')
        ? mountSectionsPanel({ bridge, config })
        : null;

    window.__siteworks_test_probe__ = window.__siteworks_test_probe__ || { fieldChanges: 0 };

    bridge.on('ready', (payload) => {
        sectionsPanel?.setPage(payload.pageId);
        bridge.sendToIframe('init', {
            csrfToken: config.csrfToken,
            capabilities: config.capabilities,
        });
    });

    bridge.on('preview-deferred', (payload = {}) => {
        showPreviewDeferredBanner(payload?.reason);
    });

    bridge.on('field-changed', ({ fieldKey, value, revisionId }) => {
        window.__siteworks_test_probe__.fieldChanges += 1;
        coordinator.enqueueFieldSave(fieldKey, value, revisionId);
    });

    bridge.on('media-pick-request', ({ fieldKey }) => {
        mountImagePicker.open({
            config,
            uploadUrl: portraitUploadUrl(fieldKey, config),
            onPick: (mediaUrl, mediaId) => {
                bridge.sendToIframe('media-picked', { fieldKey, mediaUrl, mediaId });
            },
        });
    });

    const formPanel = mountFormPanel({ bridge, config, coordinator });

    bridge.on('form-edit-requested', ({ path, kind, field }) => {
        formPanel.open({ path, kind, field });
    });

    // Browsing to another page in the preview leaves the drawer showing a form
    // that is no longer on screen. A live-update swap does NOT fire load, so
    // saving still leaves the review open — this only fires on real navigation.
    document.getElementById('editor-preview-iframe')?.addEventListener('load', () => {
        formPanel.close();
    });
}

const PREVIEW_DEFERRED_MESSAGES = {
    editing: 'Preview will refresh when you finish editing',
    page_mismatch: 'Preview will refresh when you return to that page',
};

function showPreviewDeferredBanner(reason) {
    const container = document.getElementById('editor-shell-root');
    if (! container) {
        return;
    }

    const message = PREVIEW_DEFERRED_MESSAGES[reason] ?? PREVIEW_DEFERRED_MESSAGES.editing;

    let banner = document.getElementById('preview-deferred-banner');
    if (! banner) {
        banner = document.createElement('div');
        banner.id = 'preview-deferred-banner';
        banner.setAttribute('role', 'status');
        banner.className = 'flex items-center justify-between gap-4 border-b border-amber-200 bg-amber-50 px-6 py-2 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200';

        const text = document.createElement('p');
        text.dataset.previewDeferredMessage = '';
        banner.appendChild(text);

        const dismiss = document.createElement('button');
        dismiss.type = 'button';
        dismiss.setAttribute('aria-label', 'Dismiss');
        dismiss.className = 'shrink-0 rounded-md px-2 py-1 text-amber-800 hover:bg-amber-100 dark:text-amber-200 dark:hover:bg-amber-900/40';
        dismiss.textContent = 'Dismiss';
        dismiss.addEventListener('click', () => {
            banner.remove();
        });
        banner.appendChild(dismiss);

        const header = container.querySelector('header');
        if (header) {
            header.after(banner);
        } else {
            container.prepend(banner);
        }
    }

    const text = banner.querySelector('[data-preview-deferred-message]');
    if (text) {
        text.textContent = message;
    }
}

const config = window.__siteworks_editor_shell_config__;
if (config?.surface === 'shop-admin' || config?.surface === 'portal-shop') {
    if (config.capabilities?.includes('agent_tools')) {
        import('./webmcp/index.js').then(({ installWebMCP }) => {
            // The catalogue revision is optimistic-concurrency state: every shop write bumps it and
            // returns the new value, so the bridge must carry it forward between calls or every second
            // agent write in the same page would 409 as stale.
            let catalogueRevision = config.catalogueRevision ?? 0;
            const coordinator = {
                currentRevision: () => null,
                currentEpoch: () => null,
                compositionRevision: () => config.compositionRevision ?? 0,
                catalogueRevision: () => catalogueRevision,
                setCatalogueRevision(value) {
                    catalogueRevision = value;
                },
                setRevision() {},
                setEpoch() {},
                setCompositionRevision() {},
                dropPendingSaves: async () => true,
                navigateTo: async () => ({}),
                runExternal: async ({ fn }) => ({ result: await fn(), preview: 'not_applicable' }),
            };
            const shopBridge = { on() {}, emit() {}, sendToIframe() {} };
            installWebMCP({ bridge: shopBridge, config, coordinator });
        });
    }
} else if (config) {
    bootParentEditor({ bridge, config });
}
