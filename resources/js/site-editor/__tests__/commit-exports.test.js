import { beforeEach, expect, test, vi } from 'vitest';
import {
    commitActiveSession,
    createTipTapCommit,
    setActiveTipTapSession,
} from '../tiptap-session.js';
import { commitFocusedInlineEdit, mountEditOverlay } from '../edit-overlay.js';

beforeEach(() => {
    setActiveTipTapSession(null);
    document.body.replaceChildren();
});

test('commitActiveSession emits field-changed then resolves', async () => {
    const order = [];
    const emit = vi.fn(() => {
        order.push('field-changed');
    });

    const initialDoc = { type: 'doc', content: [{ type: 'paragraph' }] };
    const changedDoc = { type: 'doc', content: [{ type: 'heading' }] };

    setActiveTipTapSession({
        commit: createTipTapCommit({
            initialDoc,
            getDoc: () => changedDoc,
            getHtml: () => '<h2>Changed</h2>',
            onSave: async (doc) => {
                emit('field-changed', { value: doc });
                await new Promise((resolve) => setTimeout(resolve, 20));
            },
            finish: () => {},
        }),
    });

    const pending = commitActiveSession().then(() => {
        order.push('resolved');
    });

    await pending;

    expect(emit).toHaveBeenCalledWith('field-changed', { value: changedDoc });
    expect(order).toEqual(['field-changed', 'resolved']);
});

test('commitActiveSession with no active session resolves without emitting', async () => {
    const emit = vi.fn();

    setActiveTipTapSession({
        commit: async () => {
            emit('field-changed');
        },
    });
    setActiveTipTapSession(null);

    await expect(commitActiveSession()).resolves.toBeUndefined();
    expect(emit).not.toHaveBeenCalled();
});

vi.mock('../tiptap-inline.js', async (importOriginal) => {
    const mod = await importOriginal();
    return { ...mod, hasFocusedTipTap: () => globalThis.__fakeTipTapFocused === true };
});

test('commitFocusedInlineEdit blurs the focused plain field so its own blur handler commits exactly once', () => {
    globalThis.__fakeTipTapFocused = false;
    const el = document.createElement('div');
    el.contentEditable = 'true';
    el.tabIndex = 0;
    el.textContent = '  Hello  ';
    document.body.appendChild(el);
    // Mirror activatePlainEditor's once-only blur commit.
    const emitted = [];
    el.addEventListener('blur', () => emitted.push(el.textContent.trim()), { once: true });
    el.focus();
    expect(document.activeElement).toBe(el);

    commitFocusedInlineEdit();

    expect(document.activeElement).not.toBe(el);
    expect(emitted).toEqual(['Hello']); // exactly one commit, trimmed, from the existing handler
});

test('commitFocusedInlineEdit is a no-op when nothing is focused', () => {
    globalThis.__fakeTipTapFocused = false;
    const el = document.createElement('div');
    el.contentEditable = 'true';
    el.tabIndex = 0;
    document.body.appendChild(el);
    const blur = vi.fn();
    el.addEventListener('blur', blur);
    commitFocusedInlineEdit();
    expect(blur).not.toHaveBeenCalled();
});

test('commitFocusedInlineEdit never touches a focused TipTap editor', () => {
    globalThis.__fakeTipTapFocused = true;
    const el = document.createElement('div');
    el.contentEditable = 'true';
    el.tabIndex = 0;
    document.body.appendChild(el);
    el.focus();
    commitFocusedInlineEdit();
    expect(document.activeElement).toBe(el); // untouched — commitActiveSession() owns rich fields
});

test('commitActiveSession swallows a failing commit and still clears the session', async () => {
    const session = { commit: async () => { throw new Error('save failed'); } };
    setActiveTipTapSession(session);
    await expect(commitActiveSession()).resolves.toBeUndefined();
    const { getActiveTipTapSession } = await import('../tiptap-session.js');
    expect(getActiveTipTapSession()).toBeNull();
});

test('real path: two clicks on the same plain field arm exactly one blur commit; commitFocusedInlineEdit emits it once', () => {
    globalThis.__fakeTipTapFocused = false;
    const emit = vi.fn();
    const bridge = { emit, on: vi.fn(), sendToIframe: vi.fn(), config: { currentRevisionId: 42 } };
    mountEditOverlay({ bridge });
    const el = document.createElement('span');
    el.setAttribute('data-editable', '');
    el.dataset.editableType = 'plain';
    el.dataset.editableField = 'hero.title';
    el.textContent = 'Old';
    el.tabIndex = 0;
    document.body.appendChild(el);
    el.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    el.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    expect(el.contentEditable).toBe('true');
    el.focus();
    el.textContent = 'New';
    commitFocusedInlineEdit();
    const fieldChanged = emit.mock.calls.filter(([type]) => type === 'field-changed');
    expect(fieldChanged).toHaveLength(1);
    expect(el.contentEditable).not.toBe('true');
});
