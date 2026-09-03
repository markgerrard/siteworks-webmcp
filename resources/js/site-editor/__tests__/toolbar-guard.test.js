import { beforeEach, expect, test, vi } from 'vitest';
import { mountToolbar } from '../toolbar.js';

beforeEach(() => {
    document.body.innerHTML = '<div data-editor-toolbar></div>';
    global.fetch = vi.fn(async () => ({
        json: async () => ({ pending_pages: [], composition_pending: false }),
    }));
});

test('agent tools keep shell publish actions enabled and expose hero and logo versions', () => {
    mountToolbar({
        bridge: { sendToIframe: vi.fn() },
        config: {
            siteName: 'Acme',
            capabilities: ['edit', 'agent_tools'],
            imageVersionsUrl: '/sites/1/image-versions',
            restoreImageVersionUrl: '/sites/1/image-versions/restore',
            publishSummaryUrl: '/sites/1/publish-summary',
        },
    });

    expect(document.getElementById('btn-publish').disabled).toBe(false);
    expect(document.getElementById('btn-discard').disabled).toBe(false);
    expect(document.getElementById('btn-hero-versions')).not.toBeNull();
    expect(document.getElementById('btn-logo-versions')).not.toBeNull();
});

/*
 * Wrong implementation: pending_asset_selections always emits <img src={selection.url}>, so a
 * mode='off' row (null version_id, no url) becomes <img src="undefined"> and hides the mode.
 * Oracle is computed from the fixture fields, not from the renderer.
 */
test('publish modal renders a null-version pending mode as text and keeps a url-bearing thumbnail', async () => {
    const heroUrl = 'https://pending.test/hero-41.jpg';
    const withUrl = {
        family: 'hero',
        page_type: 'home',
        slot: 'hero',
        version_id: 41,
        url: heroUrl,
    };
    const modeOff = {
        family: 'hero_video',
        page_type: 'home',
        slot: 'hero',
        version_id: null,
        url: null,
        mode: 'off',
    };
    const expectedModeLabel = `${modeOff.page_type} — ${modeOff.slot} · ${modeOff.mode}`;
    const expectedHeroLabel = `${withUrl.page_type} — ${withUrl.slot}`;

    global.fetch = vi.fn(async () => ({
        json: async () => ({
            pending_pages: [],
            composition_pending: false,
            next_version: 4,
            pending_asset_selections: [withUrl, modeOff],
        }),
    }));

    mountToolbar({
        bridge: { sendToIframe: vi.fn() },
        config: {
            siteName: 'Acme',
            capabilities: ['edit', 'publish'],
            publishSummaryUrl: '/sites/1/publish-summary',
        },
    });

    document.getElementById('btn-publish').click();
    await vi.waitFor(() => {
        expect(document.getElementById('site-editor-publish-modal')).not.toBeNull();
    });

    const items = [...document.querySelectorAll('#site-editor-publish-modal .modal-body ul li')];
    expect(items).toHaveLength(2);

    expect(items[0].firstElementChild.tagName).toBe('IMG');
    expect(items[0].firstElementChild.getAttribute('src')).toBe(heroUrl);
    expect(items[0].querySelector('span').textContent).toBe(expectedHeroLabel);

    expect(items[1].querySelector('img')).toBeNull();
    expect(items[1].firstElementChild.tagName).toBe('SPAN');
    expect(items[1].firstElementChild.textContent).toBe(expectedModeLabel);
});
