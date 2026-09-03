import { mountVersionsDrawer } from './versions-drawer.js';

export function initToolbar(config) {
    mountToolbar({
        bridge: {
            sendToIframe: () => {},
        },
        config,
    });
}

export function mountToolbar({ bridge, config, coordinator = null }) {
    // Toolbar reskin uses Tailwind classes consistent with layouts/app/header.blade.php
    // (the main admin top bar): zinc-50/900 surface, zinc-200/700 borders,
    // 3.5rem-ish bar height, Flux-ish button shapes.
    const toolbar = document.createElement('div');
    toolbar.id = 'site-editor-toolbar';
    toolbar.className = 'flex flex-1 items-center justify-between gap-4 min-w-0';

    // LEFT — site name + edit-mode badge with draft summary
    const leftDiv = document.createElement('div');
    leftDiv.className = 'flex items-center gap-3 min-w-0';

    const siteNameEl = document.createElement('span');
    siteNameEl.className = 'text-sm font-semibold text-zinc-900 dark:text-white truncate';
    siteNameEl.textContent = config.siteName; // textContent escapes HTML
    leftDiv.appendChild(siteNameEl);

    const modeSpan = document.createElement('span');
    modeSpan.className = 'flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400';
    const dot = document.createElement('span');
    dot.className = 'inline-block size-1.5 rounded-full bg-amber-400';
    modeSpan.appendChild(dot);
    modeSpan.appendChild(document.createTextNode('Edit mode · '));
    const draftBadge = document.createElement('span');
    draftBadge.id = 'draft-badge';
    draftBadge.className = 'truncate';
    modeSpan.appendChild(draftBadge);
    leftDiv.appendChild(modeSpan);

    // RIGHT — action buttons (Versions · Close · Discard · Publish)
    const rightDiv = document.createElement('div');
    rightDiv.className = 'flex shrink-0 items-center gap-2';

    const btnClass =
        'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium ' +
        'transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 ' +
        'focus-visible:ring-offset-zinc-50 dark:focus-visible:ring-offset-zinc-900';

    const versionsDrawer = mountVersionsDrawer({ config, coordinator, bridge });
    if (config.imageVersionsUrl && config.restoreImageVersionUrl) {
        const btnHeroVersions = document.createElement('button');
        btnHeroVersions.id = 'btn-hero-versions';
        btnHeroVersions.type = 'button';
        btnHeroVersions.className = btnClass + ' border border-zinc-300 bg-white text-zinc-700 hover:bg-zinc-100 ' +
            'dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:hover:bg-zinc-700 ' +
            'focus-visible:ring-zinc-400';
        btnHeroVersions.textContent = 'Hero versions';
        btnHeroVersions.addEventListener('click', () => versionsDrawer.open({
            scope: 'hero',
            pageId: config.pageId,
            pageType: config.pageType || 'home',
            slot: 'hero',
        }));

        const btnLogoVersions = document.createElement('button');
        btnLogoVersions.id = 'btn-logo-versions';
        btnLogoVersions.type = 'button';
        btnLogoVersions.className = btnHeroVersions.className;
        btnLogoVersions.textContent = 'Logo versions';
        btnLogoVersions.addEventListener('click', () => versionsDrawer.open({
            scope: 'logo',
            pageId: config.pageId,
        }));

        rightDiv.append(btnHeroVersions, btnLogoVersions);
    }

    // Close edit — returns to preview on public host / site admin page
    // on admin host. Appears first so it's the left-most exit affordance
    // and not confused with Discard.
    const btnClose = document.createElement('button');
    btnClose.id = 'btn-close-edit';
    btnClose.type = 'button';
    btnClose.className = btnClass + ' border border-zinc-300 bg-white text-zinc-700 hover:bg-zinc-100 ' +
        'dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:hover:bg-zinc-700 ' +
        'focus-visible:ring-zinc-400';
    btnClose.textContent = '× Close edit';

    const btnDiscard = document.createElement('button');
    btnDiscard.id = 'btn-discard';
    btnDiscard.type = 'button';
    btnDiscard.className = btnClass + ' border border-red-200 bg-white text-red-700 hover:bg-red-50 ' +
        'dark:border-red-800 dark:bg-zinc-800 dark:text-red-300 dark:hover:bg-red-950/40 ' +
        'focus-visible:ring-red-500';
    btnDiscard.textContent = 'Discard draft';

    const btnPublish = document.createElement('button');
    btnPublish.id = 'btn-publish';
    btnPublish.type = 'button';
    btnPublish.className = btnClass + ' bg-blue-600 text-white shadow-sm hover:bg-blue-500 ' +
        'focus-visible:ring-blue-500 disabled:cursor-not-allowed disabled:opacity-50';
    btnPublish.textContent = 'Publish site';

    // Demo: the human publishes from the editor toolbar even while agent tools are live.
    // The platform guard ("publish from the sites list") is dropped here on purpose — the
    // WebMCP tools cannot publish (no publish operation exists), so the human button is the
    // only path and the site-list detour was pure friction on camera.

    rightDiv.appendChild(btnClose);
    rightDiv.appendChild(btnDiscard);
    rightDiv.appendChild(btnPublish);

    toolbar.appendChild(leftDiv);
    toolbar.appendChild(rightDiv);
    document.body.classList.add('has-site-editor-toolbar');
    const mount = document.querySelector('[data-editor-toolbar]') || document.body;
    mount.appendChild(toolbar);

    // Close edit mode.
    //   - Admin host: config.closeEditUrl is set → just navigate there
    //     (sites/show). No cookie to clear; admin-edit mode is driven by
    //     the ?edit=1 query / route, and the admin returns to the site
    //     management page.
    //   - Public host: config.exitEditUrl is set → POST to clear the
    //     HTTP-only edit_session cookie, then reload (which will render
    //     public mode because the cookie's gone).
    document.getElementById('btn-close-edit').addEventListener('click', async () => {
        if (config.closeEditUrl) {
            window.location.href = config.closeEditUrl;
            return;
        }
        if (config.exitEditUrl) {
            await fetch(config.exitEditUrl, {
                method: 'POST',
                headers: { 'X-Edit-Csrf': config.editCsrf || '', 'Accept': 'application/json' },
            });
            // Reload to the same public URL; without the cookie, the
            // renderer now serves public mode.
            location.reload();
            return;
        }
        // Fallback: reload. Better than nothing if config is malformed.
        location.reload();
    });

    // Discard
    document.getElementById('btn-discard').addEventListener('click', async () => {
        if (! confirm('Discard ALL pending draft changes (pages + composition)?')) return;
        await fetch(config.discardAllUrl, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': config.csrfToken || '',
                'X-Edit-Csrf': config.editCsrf || '',
                'Accept': 'application/json',
            },
        });
        location.reload();
    });

    // Publish modal
    document.getElementById('btn-publish').addEventListener('click', async () => {
        const summaryRes = await fetch(config.publishSummaryUrl, {
            headers: { 'Accept': 'application/json' },
        });
        const summary = await summaryRes.json();
        showPublishModal(summary, config, bridge);
    });

    // Initial badge
    updateDraftBadge(config);
}

async function updateDraftBadge(config) {
    const res = await fetch(config.publishSummaryUrl, { headers: { 'Accept': 'application/json' } });
    const s = await res.json();
    const pages = s.pending_pages.length;
    const composition = s.composition_pending ? ', composition changed' : '';
    document.getElementById('draft-badge').textContent =
        pages > 0
            ? `${pages} page${pages === 1 ? '' : 's'} with drafts${composition}`
            : (s.composition_pending ? 'composition changes pending' : 'no pending changes');
}

function pendingAssetLabel(selection) {
    const base = selection.family === 'logo'
        ? 'Site logo'
        : selection.page_type + ' — ' + selection.slot;

    return selection.mode ? `${base} · ${selection.mode}` : base;
}

function showPublishModal(summary, config, bridge) {
    let modal = document.getElementById('site-editor-publish-modal');
    if (! modal) {
        modal = document.createElement('div');
        modal.id = 'site-editor-publish-modal';
        modal.innerHTML = '<div class="modal-body"></div>';
        document.body.appendChild(modal);
    }

    // Build modal body safely — no innerHTML interpolation of merchant-controlled
    // strings such as page labels. Static chrome uses innerHTML; dynamic values
    // use textContent or createElement so they are always treated as text.
    const body = modal.querySelector('.modal-body');
    body.innerHTML = '';

    const heading = document.createElement('h2');
    heading.style.cssText = 'font-size:1.25rem;font-weight:bold;margin-bottom:1rem;';
    heading.textContent = 'Ready to publish?';
    body.appendChild(heading);

    if (summary.pending_pages.length > 0) {
        const intro = document.createElement('p');
        intro.textContent = `Pages with pending changes (${summary.pending_pages.length}):`;
        body.appendChild(intro);

        const ul = document.createElement('ul');
        ul.style.cssText = 'margin:0.5rem 0;list-style:disc;padding-left:1.5rem;';
        summary.pending_pages.forEach(p => {
            const li = document.createElement('li');
            li.textContent = p.label; // textContent — p.label is merchant data
            if (p.last_edited_at) {
                const small = document.createElement('small');
                small.style.color = '#666';
                small.textContent = ` edited ${new Date(p.last_edited_at).toLocaleString()}`;
                li.appendChild(small);
            }
            ul.appendChild(li);
        });
        body.appendChild(ul);
    } else {
        const noChanges = document.createElement('p');
        noChanges.textContent = 'No pending page changes.';
        body.appendChild(noChanges);
    }

    const pendingAssets = summary.pending_asset_selections || [];
    if (pendingAssets.length > 0) {
        const assetIntro = document.createElement('p');
        assetIntro.textContent = 'Asset selections to publish (' + pendingAssets.length + '):';
        body.appendChild(assetIntro);

        const assetList = document.createElement('ul');
        assetList.style.cssText = 'display:grid;gap:0.5rem;margin:0.5rem 0;padding:0;list-style:none;';
        pendingAssets.forEach(selection => {
            const item = document.createElement('li');
            item.style.cssText = 'display:flex;align-items:center;gap:0.75rem;';

            // A mode='off' row has a null version_id and no url; an <img> would get src="null".
            if (selection.version_id != null && typeof selection.url === 'string' && selection.url !== '') {
                const thumbnail = document.createElement('img');
                thumbnail.src = selection.url;
                thumbnail.alt = '';
                thumbnail.style.cssText = 'width:4rem;height:3rem;object-fit:cover;border-radius:0.25rem;';
                item.appendChild(thumbnail);
            }

            const description = document.createElement('span');
            description.textContent = pendingAssetLabel(selection);
            item.appendChild(description);
            assetList.appendChild(item);
        });
        body.appendChild(assetList);
    }

    const compositionP = document.createElement('p');
    if (summary.composition_pending) {
        const strong = document.createElement('strong');
        strong.textContent = 'Composition changes';
        compositionP.appendChild(strong);
        compositionP.appendChild(document.createTextNode(' are pending.'));
    } else {
        compositionP.textContent = 'Composition unchanged.';
    }
    body.appendChild(compositionP);

    const label = document.createElement('label');
    label.style.cssText = 'display:block;margin:1rem 0;';
    label.appendChild(document.createTextNode('Publish note (optional):'));
    const noteInput = document.createElement('input');
    noteInput.id = 'publish-note';
    noteInput.type = 'text';
    noteInput.style.cssText = 'width:100%;padding:0.25rem 0.5rem;border:1px solid #ccc;border-radius:0.25rem;';
    label.appendChild(noteInput);
    body.appendChild(label);

    const actionDiv = document.createElement('div');
    actionDiv.style.cssText = 'display:flex;justify-content:flex-end;gap:0.5rem;';
    const cancelBtn = document.createElement('button');
    cancelBtn.id = 'modal-cancel';
    cancelBtn.type = 'button';
    cancelBtn.style.cssText = 'padding:0.25rem 0.75rem;';
    cancelBtn.textContent = 'Cancel';
    const publishBtn = document.createElement('button');
    publishBtn.id = 'modal-publish';
    publishBtn.type = 'button';
    publishBtn.style.cssText = 'padding:0.25rem 0.75rem;background:rgb(59 130 246);color:white;border-radius:0.25rem;';
    publishBtn.textContent = `Publish v${summary.next_version}`; // next_version is a server-issued integer — safe
    actionDiv.appendChild(cancelBtn);
    actionDiv.appendChild(publishBtn);
    body.appendChild(actionDiv);
    modal.classList.add('open');

    document.getElementById('modal-cancel').addEventListener('click', () => modal.classList.remove('open'));
    document.getElementById('modal-publish').addEventListener('click', async () => {
        const note = document.getElementById('publish-note').value;
        bridge.sendToIframe('publish-started', {});
        const res = await fetch(config.publishUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': config.csrfToken || '',
                'X-Edit-Csrf': config.editCsrf || '',
            },
            body: JSON.stringify({ publish_note: note }),
        });
        if (! res.ok) {
            bridge.sendToIframe('publish-finished', { ok: false, errors: ['Publish failed'] });
            alert('Publish failed');
            return;
        }
        const data = await res.json();
        bridge.sendToIframe('publish-finished', { ok: true, errors: [] });
        alert(`Published v${data.version}`);
        location.reload();
    });
}
