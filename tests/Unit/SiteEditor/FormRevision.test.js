import assert from 'node:assert/strict';
import test from 'node:test';

import { resolveCurrentFormRevision } from '../../../resources/js/site-editor/form-revision.js';

test('the shared revision supersedes the revision captured when the review opened', () => {
    assert.equal(resolveCurrentFormRevision(
        { currentRevisionId: 42 },
        { revisionId: 21 },
    ), 42);
});

test('the review revision remains a fallback before the shared revision exists', () => {
    assert.equal(resolveCurrentFormRevision(
        { currentRevisionId: null },
        { revisionId: 21 },
    ), 21);
});
