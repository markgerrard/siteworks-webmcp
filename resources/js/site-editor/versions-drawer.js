export function mountVersionsDrawer({ config, coordinator = null, bridge = null }) {
    return {
        open(options) {
            return openVersionsDrawer({ config, coordinator, bridge, ...options });
        },
    };
}

async function openVersionsDrawer({
    config,
    coordinator,
    bridge,
    scope,
    pageId = config.pageId,
    storedIndex = null,
    fieldPath = null,
    pageType = config.pageType || 'home',
    slot = 'hero',
    onRestore = null,
}) {
    document.getElementById('site-editor-versions-drawer')?.remove();

    const drawer = document.createElement('aside');
    drawer.id = 'site-editor-versions-drawer';
    drawer.className = 'fixed inset-y-0 right-0 z-[10001] flex w-full max-w-lg flex-col gap-4 overflow-y-auto border-l border-zinc-200 bg-white p-5 shadow-2xl dark:border-zinc-700 dark:bg-zinc-900';

    const header = document.createElement('div');
    header.className = 'flex items-center justify-between gap-3';
    const heading = document.createElement('h2');
    heading.className = 'text-lg font-semibold text-zinc-900 dark:text-white';
    heading.textContent = `${scope === 'logo' ? 'Logo' : scope === 'hero' ? 'Hero' : 'Image'} versions`;
    const close = actionButton('Close');
    close.addEventListener('click', () => drawer.remove());
    header.append(heading, close);
    drawer.appendChild(header);

    const status = document.createElement('p');
    status.className = 'text-sm text-zinc-500 dark:text-zinc-400';
    status.textContent = 'Loading versions…';
    drawer.appendChild(status);
    document.body.appendChild(drawer);

    const query = versionListQuery({ scope, pageId, storedIndex, fieldPath, pageType, slot });

    const response = await fetch(urlWithQuery(config.imageVersionsUrl, query), {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
    });
    const envelope = await response.json().catch(() => ({}));
    if (! response.ok || ! envelope.ok) {
        status.textContent = envelope.error?.message || 'Could not load versions.';
        return;
    }

    status.remove();
    const versions = envelope.data?.versions || [];
    if (versions.length === 0) {
        const empty = document.createElement('p');
        empty.className = 'text-sm text-zinc-500 dark:text-zinc-400';
        empty.textContent = 'No versions yet.';
        drawer.appendChild(empty);
        return;
    }

    const grid = document.createElement('div');
    grid.className = 'grid grid-cols-2 gap-3';
    versions.forEach((version) => {
        const card = document.createElement('article');
        card.className = 'flex flex-col gap-2 rounded-lg border border-zinc-200 p-2 dark:border-zinc-700';
        const image = document.createElement('img');
        image.className = 'aspect-video w-full rounded-md bg-zinc-100 object-cover dark:bg-zinc-800';
        image.src = version.url;
        image.alt = '';
        card.appendChild(image);

        const badges = document.createElement('div');
        badges.className = 'flex flex-wrap gap-1 text-xs';
        if (version.active) {
            badges.appendChild(badge('Active', 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200'));
        }
        if (version.drafted) {
            badges.appendChild(badge('Draft', 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200'));
        }
        card.appendChild(badges);

        const restore = actionButton('Restore');
        restore.disabled = version.drafted === true;
        restore.addEventListener('click', async () => {
            restore.disabled = true;
            const result = await restoreVersion({
                config,
                coordinator,
                scope,
                version,
                pageId,
                storedIndex,
                fieldPath,
                pageType,
                slot,
            });
            if (! result.ok) {
                alert(result.error?.message || 'Restore failed.');
                restore.disabled = false;
                return;
            }

            updateCoordinatorState(coordinator, result.state);
            if (typeof onRestore === 'function') {
                onRestore(version.url, scope === 'media' ? version.id : null);
            } else {
                bridge?.sendToIframe?.('reload-preview', {});
            }
            drawer.remove();
        });
        card.appendChild(restore);
        grid.appendChild(card);
    });
    drawer.appendChild(grid);
}

async function restoreVersion({ config, coordinator, scope, version, pageId, storedIndex, fieldPath, pageType, slot }) {
    const compositionRevision = coordinator?.compositionRevision?.() ?? config.compositionRevision ?? 0;
    const revisionBase = coordinator?.currentRevision?.(pageId)
        ?? config.currentRevisionIds?.[pageId]
        ?? config.currentRevisionId;
    const structureEpoch = coordinator?.currentEpoch?.(pageId)
        ?? config.structureEpochs?.[pageId]
        ?? 0;

    const request = restoreVersionRequest({
        config,
        scope,
        versionId: version.id,
        pageId,
        storedIndex,
        fieldPath,
        pageType,
        slot,
        compositionRevision,
        revisionBase,
        structureEpoch,
    });
    const operation = () => postJson(config, request.url, request.body);

    if (! coordinator?.runExternal) {
        return operation();
    }

    const coordinated = await coordinator.runExternal({
        pageId,
        structural: false,
        fn: operation,
    });

    return coordinated.result ?? coordinated;
}

export function versionListQuery({ scope, pageId, storedIndex, fieldPath, pageType, slot }) {
    if (scope === 'hero') {
        return { scope, page_type: pageType, slot };
    }
    if (scope === 'media') {
        return {
            scope,
            page_id: pageId,
            stored_index: storedIndex,
            field_path: fieldPath,
        };
    }

    return { scope };
}

export function restoreVersionRequest({
    config,
    scope,
    versionId,
    pageId,
    storedIndex,
    fieldPath,
    pageType,
    slot,
    compositionRevision,
    revisionBase,
    structureEpoch,
}) {
    if (scope === 'media') {
        return {
            url: pageUrl(config.restoreMediaVersionUrl, pageId),
            body: {
                page_id: pageId,
                stored_index: storedIndex,
                field_path: fieldPath,
                media_id: versionId,
                revision_base: revisionBase,
                structure_epoch: structureEpoch,
            },
        };
    }

    return {
        url: config.restoreImageVersionUrl,
        body: {
            scope,
            version_id: versionId,
            composition_revision: compositionRevision,
            ...(scope === 'hero' ? { page_type: pageType, slot } : {}),
        },
    };
}

async function postJson(config, url, body) {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': config.csrfToken || '',
        },
        body: JSON.stringify(body),
    });

    return response.json().catch(() => ({
        ok: false,
        error: { code: 'internal', message: 'Restore failed.' },
    }));
}

function urlWithQuery(url, query) {
    const parsed = new URL(url, window.location.origin);
    Object.entries(query).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') {
            parsed.searchParams.set(key, String(value));
        }
    });

    return parsed.toString();
}

function pageUrl(template, pageId) {
    return String(template ?? '').replace(/\/pages\/\d+\//, `/pages/${pageId}/`);
}

function actionButton(label) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'inline-flex items-center justify-center rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-700 transition hover:bg-zinc-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:hover:bg-zinc-700';
    button.textContent = label;

    return button;
}

function badge(label, classes) {
    const element = document.createElement('span');
    element.className = `rounded-full px-2 py-0.5 font-medium ${classes}`;
    element.textContent = label;

    return element;
}

function updateCoordinatorState(coordinator, state) {
    if (! state) {
        return;
    }
    if (Number.isInteger(state.composition_revision)) {
        coordinator?.setCompositionRevision?.(state.composition_revision);
    }
    if (Number.isInteger(state.page_id) && Number.isInteger(state.draft_revision_id)) {
        coordinator?.setRevision?.(state.page_id, state.draft_revision_id);
    }
    if (Number.isInteger(state.page_id) && Number.isInteger(state.structure_epoch)) {
        coordinator?.setEpoch?.(state.page_id, state.structure_epoch);
    }
}
