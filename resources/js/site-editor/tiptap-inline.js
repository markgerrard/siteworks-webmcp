import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import {
    clearActiveTipTapSession,
    createTipTapCommit,
    destroyActiveTipTap,
    getActiveTipTapSession,
    setActiveTipTapSession,
} from './tiptap-session.js';

// Tracks the single active editor so that clicking a different rich field
// auto-saves the current one before switching — no orphaned TipTap with no
// toolbar attached, and no silent loss of unsaved edits.
let mountedBridge = null;

export function mountTiptap({ bridge }) {
    mountedBridge = bridge;

    mountedBridge.on('force-reload', () => {
        location.reload();
    });
}

export function hasFocusedTipTap() {
    return document.activeElement instanceof Element
        && document.activeElement.closest('.ProseMirror') !== null;
}

export { destroyActiveTipTap };

export async function activateTipTap(el, currentDoc, onSave, fallbackHtml = '') {
    // If another TipTap is active, commit its pending changes and tear it
    // down cleanly before we replace the DOM for the new field.
    const previousSession = getActiveTipTapSession();
    if (previousSession) {
        try {
            await previousSession.commit();
        } catch (_) {
            // If the previous save failed, fall through — the UI reloads on
            // true conflict via field-save, so we'd rather swap the editor
            // than block the user here.
        }
        clearActiveTipTapSession(previousSession);
    }

    // Snapshot everything we need to rebuild an equivalent element on save.
    // The original element is replaced so we can attach TipTap cleanly; once
    // the user saves we restore a new node with the same tag + attrs (including
    // data-editable-* so the editor can re-activate on subsequent clicks).
    const originalTag = el.tagName.toLowerCase();
    const originalAttrs = {};
    for (const a of el.attributes) {
        originalAttrs[a.name] = a.value;
    }

    // Wrap toolbar + editing host in a single container so they always sit
    // together visually. The toolbar lives directly above the field being
    // edited — much more discoverable than a fixed bar at the top of the
    // viewport which the user's eye doesn't track to.
    const wrapper = document.createElement('div');
    wrapper.style.cssText = [
        'border: 2px solid rgb(59 130 246)',
        'border-radius: 0.5rem',
        'background: transparent',
        'box-shadow: 0 1px 2px rgba(0,0,0,0.04)',
        'overflow: hidden',
    ].join(';');

    const host = document.createElement('div');
    host.style.cssText = [
        'padding: 1rem 1.25rem',
        'background: transparent',
    ].join(';');
    wrapper.appendChild(host);
    el.replaceWith(wrapper);

    const editor = new Editor({
        element: host,
        extensions: [
            StarterKit.configure({
                heading: { levels: [2, 3] },
                codeBlock: false,   // disallowed
                horizontalRule: false,
                strike: true,
            }),
            Link.configure({
                openOnClick: false,
                HTMLAttributes: { rel: 'noopener' },
                validate: (href) => /^https?:\/\//i.test(href),
            }),
        ],
        // TipTap accepts either a ProseMirror JSON doc or an HTML string.
        // Prefer the structured doc; if none exists, hand it the original
        // HTML so fields without a stored doc don't open blank.
        content: currentDoc || fallbackHtml || '',
        autofocus: 'end',
    });
    const initialDoc = editor.getJSON();

    // Inline toolbar — sits directly above the field as the top section of
    // the wrapper. Two rows so a narrow field doesn't clip buttons:
    //   row 1: "Editing · field" label  +  Save / Cancel
    //   row 2: formatting buttons (B I H2 H3 • 1. ❝ 🔗) — flex-wrap so
    //          long button rows wrap on narrow fields rather than overflow.
    // Only one rich toolbar may exist at a time; any stale one is removed.
    document.getElementById('site-editor-rich-toolbar')?.remove();
    const toolbar = document.createElement('div');
    toolbar.id = 'site-editor-rich-toolbar';
    toolbar.style.cssText = [
        'background: rgb(248 250 252)',
        'color: rgb(55 65 81)',
        'border-bottom: 1px solid rgb(226 232 240)',
        'padding: 0.5rem 0.75rem',
        'display: flex',
        'flex-direction: column',
        'gap: 0.4rem',
        'font-family: system-ui, sans-serif',
    ].join(';');

    const fieldLabel = el.dataset.editableField || 'text';
    const btnBase = 'padding:0.35rem 0.55rem;border-radius:0.375rem;font-size:0.875rem;font-weight:500;line-height:1;cursor:pointer;border:1px solid rgb(209 213 219);background:white;color:rgb(55 65 81);min-width:2rem;';
    const btnSave = 'padding:0.45rem 0.9rem;border-radius:0.375rem;font-size:0.875rem;font-weight:600;line-height:1;cursor:pointer;border:1px solid rgb(22 163 74);background:rgb(34 197 94);color:white;';
    const btnCancel = 'padding:0.45rem 0.9rem;border-radius:0.375rem;font-size:0.875rem;font-weight:500;line-height:1;cursor:pointer;border:1px solid rgb(209 213 219);background:white;color:rgb(55 65 81);';

    // Row 1 — status label on the left, Save/Cancel on the right.
    const statusRow = document.createElement('div');
    statusRow.style.cssText = 'display:flex;align-items:center;gap:0.5rem;';

    const labelSpan = document.createElement('span');
    labelSpan.style.cssText = 'font-size:0.8125rem;color:rgb(107 114 128);';
    labelSpan.appendChild(document.createTextNode('Editing · '));
    const labelStrong = document.createElement('strong');
    labelStrong.style.color = 'rgb(55 65 81)';
    labelStrong.textContent = fieldLabel;
    labelSpan.appendChild(labelStrong);
    statusRow.appendChild(labelSpan);

    const spacer = document.createElement('span');
    spacer.style.flex = '1';
    statusRow.appendChild(spacer);

    statusRow.insertAdjacentHTML(
        'beforeend',
        `<button data-action="cancel" type="button" style="${btnCancel}">Cancel</button>` +
        `<button data-action="save" type="button" style="${btnSave}">Save</button>`,
    );

    // Row 2 — formatting buttons. flex-wrap so a narrow field wraps onto a
    // third visual line rather than overflowing horizontally.
    const formatRow = document.createElement('div');
    formatRow.style.cssText = 'display:flex;flex-wrap:wrap;align-items:center;gap:0.25rem;';
    formatRow.innerHTML = `
        <button data-action="bold"       type="button" style="${btnBase}" title="Bold"><strong>B</strong></button>
        <button data-action="italic"     type="button" style="${btnBase}" title="Italic"><em>I</em></button>
        <button data-action="h2"         type="button" style="${btnBase}" data-narrow-hide title="Heading 2">H2</button>
        <button data-action="h3"         type="button" style="${btnBase}" data-narrow-hide title="Heading 3">H3</button>
        <button data-action="ul"         type="button" style="${btnBase}" title="Bullet list">•</button>
        <button data-action="ol"         type="button" style="${btnBase}" title="Numbered list">1.</button>
        <button data-action="blockquote" type="button" style="${btnBase}" title="Quote">❝</button>
        <button data-action="link"       type="button" style="${btnBase}" title="Link">🔗</button>
    `;

    toolbar.appendChild(statusRow);
    toolbar.appendChild(formatRow);
    wrapper.insertBefore(toolbar, host);

    // Hide H2/H3 on narrow fields (sidebar columns, card grids) — most
    // body-text fields aren't headings, so dropping them prevents the format
    // row spilling onto a third visual line in tight layouts. ResizeObserver
    // re-evaluates if the iframe is resized while editing.
    const NARROW_PX = 360;
    const applyNarrow = () => {
        const narrow = wrapper.clientWidth < NARROW_PX;
        formatRow.querySelectorAll('[data-narrow-hide]').forEach((btn) => {
            btn.style.display = narrow ? 'none' : '';
        });
    };
    applyNarrow();
    const ro = new ResizeObserver(applyNarrow);
    ro.observe(wrapper);
    // Tear the observer down when the editor is destroyed (see finish()).
    wrapper._narrowObserver = ro;

    wrapper.style.scrollMarginTop = '7rem';
    wrapper.scrollIntoView({ block: 'center', behavior: 'smooth' });

    const destroy = () => {
        wrapper._narrowObserver?.disconnect();
        if (! editor.isDestroyed) {
            editor.destroy();
        }
        clearActiveTipTapSession(session);
    };

    const finish = (savedHtml, savedDoc) => {
        // Build a replacement element matching the original tag/attrs, with
        // the freshly-rendered HTML (or the TipTap-rendered HTML if cancel).
        // Keep data-editable-doc in sync so the next click re-opens correctly.
        const replacement = document.createElement(originalTag);
        for (const [name, value] of Object.entries(originalAttrs)) {
            if (name === 'data-editable-doc' && savedDoc != null) {
                replacement.setAttribute(name, JSON.stringify(savedDoc));
            } else {
                replacement.setAttribute(name, value);
            }
        }
        // If we have explicit savedHtml (from save), use it; on cancel we just
        // render the original doc back via TipTap's own HTML before destroying.
        if (savedHtml != null) {
            replacement.innerHTML = savedHtml;
        } else {
            replacement.innerHTML = editor.getHTML();
        }
        wrapper.replaceWith(replacement);
        destroy();
    };

    // Register this as the active session so that clicking into another rich
    // field before hitting Save/Cancel commits the current content and tears
    // this editor down cleanly. `commit` is idempotent.
    const session = {
        host,
        destroy,
        commit: createTipTapCommit({
            initialDoc,
            getDoc: () => editor.getJSON(),
            getHtml: () => editor.getHTML(),
            onSave,
            finish,
        }),
    };
    setActiveTipTapSession(session);

    toolbar.addEventListener('click', async (e) => {
        const action = e.target.closest('button')?.dataset.action;
        if (! action) return;
        e.preventDefault();
        switch (action) {
            case 'bold': editor.chain().focus().toggleBold().run(); break;
            case 'italic': editor.chain().focus().toggleItalic().run(); break;
            case 'h2': editor.chain().focus().toggleHeading({ level: 2 }).run(); break;
            case 'h3': editor.chain().focus().toggleHeading({ level: 3 }).run(); break;
            case 'ul': editor.chain().focus().toggleBulletList().run(); break;
            case 'ol': editor.chain().focus().toggleOrderedList().run(); break;
            case 'blockquote': editor.chain().focus().toggleBlockquote().run(); break;
            case 'link': {
                const url = window.prompt('URL (https://...)');
                if (url && /^https?:\/\//i.test(url)) {
                    editor.chain().focus().setLink({ href: url }).run();
                }
                break;
            }
            case 'save':
                await session.commit();
                break;
            case 'cancel':
                clearActiveTipTapSession(session);
                // Restore the original content (doc we started with) and tear down.
                finish(null, currentDoc);
                break;
        }
    });
}
