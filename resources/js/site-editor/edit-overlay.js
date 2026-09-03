import { activateTipTap, hasFocusedTipTap } from './tiptap-inline.js';

export function initEditOverlay(config) {
    mountEditOverlay({
        bridge: {
            config,
            emit: (type, payload = {}) => {
                document.dispatchEvent(new CustomEvent(`siteworks-editor:${type}`, { detail: payload }));
            },
            on: () => {},
        },
    });
}

export function mountEditOverlay({ bridge }) {
    bridge.on('media-picked', ({ fieldKey, mediaUrl, mediaId }) => {
        applyMediaToField(fieldKey, mediaUrl, mediaId, bridge);
    });

    // In edit mode the form is a thing you EDIT, not a thing you fill in. A
    // native select would drop its option list over the page, and text inputs
    // would take a caret and accept typing that is never saved anywhere.
    //
    // mousedown is where that behaviour starts, so it is suppressed here —
    // the click still reaches the handler below, which is what identifies the
    // field. Inline editables (heading, submit label) are untouched: they own
    // their own editors.
    document.addEventListener('mousedown', (e) => {
        const formEl = e.target.closest('[data-form-editable]');
        if (! formEl || e.target.closest('[data-editable]')) return;
        if (! e.target.closest('input, textarea, select, button')) return;

        e.preventDefault();
    });

    // Keyboard equivalent: a control reached by tabbing must not accept input
    // either, or the operator types into a preview that stores nothing.
    document.addEventListener('keydown', (e) => {
        const formEl = e.target.closest?.('[data-form-editable]');
        if (! formEl || e.target.closest?.('[data-editable]')) return;
        if (! e.target.matches?.('input, textarea, select')) return;
        if (['Tab', 'Escape'].includes(e.key)) return;

        e.preventDefault();
    });

    // Delegated so replacement elements inserted after TipTap save (and any
    // future in-place updates) get handled without re-binding.
    document.addEventListener('click', (e) => {
        const formEl = e.target.closest('[data-form-editable]');
        if (formEl && ! e.target.closest('[data-editable]')) {
            e.preventDefault();
            e.stopPropagation();
            bridge.emit('form-edit-requested', {
                path: formEl.dataset.formEditable,
                kind: formEl.dataset.formKind,
                field: namedControlFor(e.target)?.getAttribute('name') || undefined,
            });
            return;
        }

        const el = e.target.closest('[data-editable]');
        if (! el) return;
        // Ignore clicks inside an active TipTap host — the editor owns those.
        if (el.closest('.ProseMirror')) return;
        // Already-active plain field: the click just moves the caret. Re-activating would arm a
        // second once-blur commit (two field-changed events for one edit).
        if (el.contentEditable === 'true') return;

        e.preventDefault();
        e.stopPropagation();

        emitSelectionChanged(el, bridge);

        const type = el.dataset.editableType;
        switch (type) {
            case 'plain':
                activatePlainEditor(el, bridge);
                break;
            case 'url':
                activateUrlEditor(el, bridge);
                break;
            case 'rich':
                activateRichEditor(el, bridge);
                break;
            case 'image':
                requestImagePicker(el, bridge);
                break;
        }
    }, true);
}

/**
 * Commit-before-external-write for a plain contentEditable field: blur it so the
 * plain editor's own once-only blur handler (activatePlainEditor → finish) commits
 * and emits `field-changed` exactly once with the trimmed value. Never emits a
 * second event; no-op when nothing is focused or a TipTap editor holds focus
 * (commitActiveSession() owns that path).
 */
export function commitFocusedInlineEdit() {
    if (hasFocusedTipTap()) {
        return;
    }
    const el = document.activeElement;
    if (! (el instanceof HTMLElement) || el.contentEditable !== 'true') {
        return;
    }
    el.blur();
}

// Radios and checkboxes render as <label><input><span>Text</span></label>, so a
// click lands on the span and the input is a SIBLING — closest() walks
// ancestors and finds nothing. Fall back to the label's associated control,
// which covers both wrapping labels and for="" labels.
function namedControlFor(target) {
    const direct = target.closest('input[name], textarea[name], select[name], button[name]');
    if (direct) return direct;

    const labelled = target.closest('label')?.control;

    return labelled?.getAttribute('name') ? labelled : null;
}

function activatePlainEditor(el, bridge) {
    const original = el.textContent;

    // Visual cue mirrors the rich editor's blue motif so plain fields don't
    // look identical to non-editable content when contentEditable flips on.
    // Outline (not border) avoids reflowing surrounding layout.
    const prevOutline = el.style.outline;
    const prevOutlineOffset = el.style.outlineOffset;
    const prevBorderRadius = el.style.borderRadius;
    el.style.outline = '2px solid rgb(59 130 246)';
    el.style.outlineOffset = '4px';
    if (! el.style.borderRadius) {
        el.style.borderRadius = '0.25rem';
    }

    // Floating label pinned to the viewport (body-attached, fixed) so it
    // sits outside the contenteditable element — no risk of the cursor
    // landing in it or its text leaking into el.textContent on save.
    const fieldLabel = el.dataset.editableField || 'text';
    const rect = el.getBoundingClientRect();
    const label = document.createElement('div');
    label.style.cssText = [
        'position: fixed',
        `top: ${Math.max(rect.top - 32, 4)}px`,
        `left: ${Math.max(rect.left, 4)}px`,
        'background: rgb(248 250 252)',
        'color: rgb(55 65 81)',
        'border: 1px solid rgb(226 232 240)',
        'border-radius: 0.375rem',
        'padding: 0.25rem 0.6rem',
        'font-size: 0.75rem',
        'font-family: system-ui, sans-serif',
        'box-shadow: 0 1px 2px rgba(0,0,0,0.04)',
        'pointer-events: none',
        'white-space: nowrap',
        'z-index: 9997',
    ].join(';');
    label.appendChild(document.createTextNode('Editing · '));
    const strong = document.createElement('strong');
    strong.style.color = 'rgb(37 99 235)';
    strong.textContent = fieldLabel;
    label.appendChild(strong);
    document.body.appendChild(label);

    el.contentEditable = 'true';
    el.focus();

    const teardown = () => {
        el.contentEditable = 'false';
        el.style.outline = prevOutline;
        el.style.outlineOffset = prevOutlineOffset;
        el.style.borderRadius = prevBorderRadius;
        if (label.isConnected) label.remove();
    };

    const finish = () => {
        teardown();
        const value = el.textContent.trim();
        if (value === original) return;
        emitFieldChanged(el, value, bridge);
    };

    el.addEventListener('blur', finish, { once: true });
    el.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && ! e.shiftKey) {
            e.preventDefault();
            el.blur();
        }
        if (e.key === 'Escape') {
            el.textContent = original;
            el.blur();
        }
    });
}

function activateUrlEditor(el, bridge) {
    const current = el.getAttribute('href') || el.dataset.editableValue || '';
    const value = window.prompt('Enter URL (https://…)', current);
    if (value === null || value === current) return;
    if (! /^https?:\/\//i.test(value)) {
        alert('URL must start with http:// or https://');
        return;
    }
    el.setAttribute('href', value);
    emitFieldChanged(el, value, bridge);
}

function activateRichEditor(el, bridge) {
    // Prefer the JSON doc when the server emitted one (admin-edit mode),
    // otherwise fall back to the element's existing HTML so fields without
    // a structured doc (e.g. form intro) don't open empty.
    let currentDoc = null;
    try {
        currentDoc = el.dataset.editableDoc ? JSON.parse(el.dataset.editableDoc) : null;
    } catch (_) {}

    const fallbackHtml = el.innerHTML;

    activateTipTap(el, currentDoc, (doc) => {
        emitFieldChanged(el, doc, bridge);
    }, fallbackHtml);
}

function requestImagePicker(el, bridge) {
    bridge.emit('media-pick-request', {
        fieldKey: fieldKeyFor(el),
    });
}

function applyMediaToField(fieldKey, mediaUrl, mediaId, bridge) {
    const el = findEditableByFieldKey(fieldKey);
    if (! el) return;

    if (el.tagName.toLowerCase() === 'img') {
        el.setAttribute('src', mediaUrl);
    } else {
        el.style.backgroundImage = `url("${mediaUrl}")`;
    }

    bridge.emit('field-changed', {
        fieldKey,
        value: /\.members\.\d+\.(image_id|alternate_image_id|hover_image_id)$/.test(fieldKey) ? mediaId : mediaUrl,
        revisionId: bridge.config?.currentRevisionId ?? null,
        mediaId,
    });
}

function emitSelectionChanged(el, bridge) {
    const rect = el.getBoundingClientRect();

    bridge.emit('selection-changed', {
        fieldKey: fieldKeyFor(el),
        fieldRect: {
            top: rect.top,
            right: rect.right,
            bottom: rect.bottom,
            left: rect.left,
            width: rect.width,
            height: rect.height,
        },
    });
}

function emitFieldChanged(el, value, bridge) {
    bridge.emit('field-changed', {
        fieldKey: fieldKeyFor(el),
        value,
        revisionId: bridge.config?.currentRevisionId ?? null,
    });
}

function fieldKeyFor(el) {
    return el.dataset.editableImageTarget || el.dataset.editable;
}

function findEditableByFieldKey(fieldKey) {
    return Array.from(document.querySelectorAll('[data-editable], [data-editable-image-target]'))
        .find((el) => fieldKeyFor(el) === fieldKey);
}
