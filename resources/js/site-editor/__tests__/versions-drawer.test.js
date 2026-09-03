import { expect, test } from 'vitest';
import { restoreVersionRequest, versionListQuery } from '../versions-drawer.js';

test('media version listing carries stored_index for an exact active badge', () => {
    expect(versionListQuery({
        scope: 'media',
        pageId: 12,
        storedIndex: 3,
        fieldPath: 'background_image',
    })).toEqual({
        scope: 'media',
        page_id: 12,
        stored_index: 3,
        field_path: 'background_image',
    });
});

test('media restore uses the page URL and page concurrency bases', () => {
    expect(restoreVersionRequest({
        config: { restoreMediaVersionUrl: '/sites/4/pages/0/media/restore' },
        scope: 'media',
        versionId: 91,
        pageId: 12,
        storedIndex: 3,
        fieldPath: 'background_image',
        revisionBase: 44,
        structureEpoch: 5,
    })).toEqual({
        url: '/sites/4/pages/12/media/restore',
        body: {
            page_id: 12,
            stored_index: 3,
            field_path: 'background_image',
            media_id: 91,
            revision_base: 44,
            structure_epoch: 5,
        },
    });
});

test('hero and logo restores use the site URL and composition revision', () => {
    const config = { restoreImageVersionUrl: '/sites/4/image-versions/restore' };

    expect(restoreVersionRequest({
        config,
        scope: 'hero',
        versionId: 8,
        pageType: 'home',
        slot: 'hero',
        compositionRevision: 6,
    }).body).toEqual({
        scope: 'hero',
        version_id: 8,
        composition_revision: 6,
        page_type: 'home',
        slot: 'hero',
    });
    expect(restoreVersionRequest({
        config,
        scope: 'logo',
        versionId: 9,
        compositionRevision: 7,
    }).body).toEqual({
        scope: 'logo',
        version_id: 9,
        composition_revision: 7,
    });
});
