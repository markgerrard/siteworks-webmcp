import assert from 'node:assert/strict';
import test from 'node:test';

import {
    createTipTapCommit,
    destroyActiveTipTap,
    setActiveTipTapSession,
} from '../../../resources/js/site-editor/tiptap-session.js';

test('destroy commits changed content through the save callback', async () => {
    const saves = [];
    const finishes = [];
    const initialDoc = { type: 'doc', content: [{ type: 'paragraph' }] };
    const changedDoc = { type: 'doc', content: [{ type: 'heading' }] };

    setActiveTipTapSession({
        commit: createTipTapCommit({
            initialDoc,
            getDoc: () => changedDoc,
            getHtml: () => '<h2>Changed</h2>',
            onSave: async (doc) => saves.push(doc),
            finish: (html, doc) => finishes.push({ html, doc }),
        }),
    });

    await destroyActiveTipTap();

    assert.deepEqual(saves, [changedDoc]);
    assert.deepEqual(finishes, [{ html: '<h2>Changed</h2>', doc: changedDoc }]);
});

test('destroy finishes unchanged content without firing a save', async () => {
    const saves = [];
    const finishes = [];
    const initialDoc = { type: 'doc', content: [{ type: 'paragraph' }] };

    setActiveTipTapSession({
        commit: createTipTapCommit({
            initialDoc,
            getDoc: () => initialDoc,
            getHtml: () => '<p>Unchanged</p>',
            onSave: async (doc) => saves.push(doc),
            finish: (html, doc) => finishes.push({ html, doc }),
        }),
    });

    await destroyActiveTipTap();

    assert.deepEqual(saves, []);
    assert.deepEqual(finishes, [{ html: '<p>Unchanged</p>', doc: initialDoc }]);
});
