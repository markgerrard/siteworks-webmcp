// resources/js/site-editor/iframe-entry.js
//
// Vite entry for the editor-preview origin. Loads ONLY iframe-side modules.

import { bridge } from './bridge-iframe.js';
import { commitFocusedInlineEdit, mountEditOverlay } from './edit-overlay.js';
import { commitActiveSession } from './tiptap-session.js';
import { destroyActiveTipTap, hasFocusedTipTap, mountTiptap } from './tiptap-inline.js';

bridge.on('init', (payload) => {
    mountEditOverlay({ bridge });
    mountTiptap({ bridge });
});

bridge.on('prepare-external-write', async (payload, meta) => {
    await commitActiveSession();
    commitFocusedInlineEdit();
    bridge.reply(meta, 'external-write-ready', {
        pageId: bridge.config.pageId,
        revisionId: bridge.config.currentRevisionId,
    });
});

bridge.on('nav-request', ({ to, url, requestId }, meta) => {
    let destination;
    try {
        destination = new URL(url, window.location.href);
    } catch (_) {
        bridge.reply(meta, 'nav-request', { error: 'bad_url' });
        return;
    }

    const expectedPath = `/sites/${bridge.config.siteId}/pages/${to}`;
    if (destination.origin !== window.location.origin || destination.pathname !== expectedPath) {
        bridge.reply(meta, 'nav-request', { error: 'bad_url' });
        return;
    }

    window.sessionStorage.setItem('siteworks-editor:reload-ack', requestId);
    window.location.assign(url);
});

// The parent cannot reload us from its side without sending us back to the
// page it was opened on, so it asks and we reload wherever we actually are.
bridge.on('reload-preview', ({ requestId }) => {
    window.sessionStorage.setItem('siteworks-editor:reload-ack', requestId);
    window.location.reload();
});

bridge.on('replace-section', async ({ html, revisionId, storedIndex, pageId }, meta) => {
    if (String(pageId) !== String(bridge.config.pageId)) {
        bridge.reply(meta, 'replace-section', { deferred: true, reason: 'page_mismatch' });
        return;
    }

    if (hasFocusedTipTap()) {
        bridge.reply(meta, 'replace-section', { deferred: true, reason: 'editing' });
        return;
    }

    const selector = `[data-section-index="${CSS.escape(String(storedIndex))}"]`;
    const nextDocument = new DOMParser().parseFromString(html, 'text/html');
    const currentSection = document.querySelector(selector);
    const nextSection = nextDocument.querySelector(selector);

    if (! currentSection || ! nextSection) {
        bridge.reply(meta, 'replace-section', { applied: true, reloading: true });
        window.location.reload();
        return;
    }

    // Tear down any open (but unfocused) editor first: a session left alive over the swap would commit its
    // pre-swap value on the next handshake and silently overwrite the agent's write.
    await destroyActiveTipTap();

    currentSection.innerHTML = nextSection.innerHTML;
    window.Alpine?.initTree?.(currentSection);
    initDatePickers();
    window.lucide?.createIcons();
    bridge.config.currentRevisionId = revisionId;
    bridge.reply(meta, 'replace-section', { applied: true, revisionId });
});

bridge.on('replace-preview', async ({ html, revisionId }, meta) => {
    if (typeof html !== 'string' || html === '') {
        bridge.reply(meta, 'replace-preview', { applied: true, reloading: true });
        window.location.reload();
        return;
    }

    if (hasFocusedTipTap()) {
        console.info('[iframe-entry] preview replacement skipped while inline editing is active');
        bridge.reply(meta, 'replace-preview', { deferred: true, reason: 'editing' });
        return;
    }

    const nextDocument = new DOMParser().parseFromString(html, 'text/html');

    // Narrow swap: replace the edited form (and its out-of-form title /
    // submit copy) so Leaflet, Alpine, lucide and the chatbot stay on
    // the live document. A full body swap via DOMParser marks scripts
    // "already started" — the map goes blank and flatpickr dies.
    await destroyActiveTipTap();
    if (! replaceFormInPlace(nextDocument)) {
        bridge.reply(meta, 'replace-preview', { applied: true, reloading: true });
        window.location.reload();
        return;
    }

    if (revisionId) {
        bridge.config.currentRevisionId = revisionId;
    }

    initDatePickers();
    window.requestAnimationFrame(() => {
        window.lucide?.createIcons();
    });
    bridge.reply(meta, 'replace-preview', { applied: true, revisionId });
});

function replaceFormInPlace(nextDocument) {
    const nextForms = [...nextDocument.querySelectorAll('[data-form-editable]')];
    if (nextForms.length === 0) {
        return false;
    }

    let replaced = 0;

    nextForms.forEach((nextForm) => {
        const marker = nextForm.getAttribute('data-form-editable');
        if (! marker) {
            return;
        }

        const current = document.querySelector(`[data-form-editable="${CSS.escape(marker)}"]`);
        if (! current) {
            return;
        }

        const imported = document.importNode(nextForm, true);
        current.replaceWith(imported);
        window.Alpine?.initTree?.(imported);
        replaced += 1;

        // Title / intro / submit_label can sit outside the <form> itself.
        nextDocument.querySelectorAll('[data-editable]').forEach((nextEl) => {
            const path = nextEl.getAttribute('data-editable') || '';
            if (! path.startsWith(`${marker}.`)) {
                return;
            }
            if (imported.querySelector(`[data-editable="${CSS.escape(path)}"]`)) {
                return;
            }
            const live = document.querySelector(`[data-editable="${CSS.escape(path)}"]`);
            if (! live || imported.contains(live)) {
                return;
            }
            live.replaceWith(document.importNode(nextEl, true));
        });
    });

    return replaced > 0;
}

function initDatePickers() {
    if (typeof window.flatpickr !== 'function') {
        return;
    }

    document.querySelectorAll('input[data-flatpickr]:not(.flatpickr-input)').forEach((el) => {
        window.flatpickr(el, {
            dateFormat: 'Y-m-d',
            altInput: true,
            altFormat: 'j F Y',
            minDate: 'today',
        });
    });
}
