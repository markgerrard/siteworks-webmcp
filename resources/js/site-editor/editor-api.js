export async function postFieldChange(config, fieldKey, value, revisionId) {
    const parsed = parseFieldKey(fieldKey);
    if (! parsed) {
        surfaceFieldSaveFailure('Save failed. Your edit did not apply.');
        return;
    }

    const res = await fetch(urlForFieldUpdate(config.fieldUpdateUrl, parsed.pageId), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': config.csrfToken,
            'X-Page-Revision-Base': revisionId || config.currentRevisionId || '',
            'Accept': 'application/json',
        },
        body: JSON.stringify({
            section_index: parsed.sectionIndex,
            field_path: parsed.fieldPath,
            value,
        }),
    });

    if (res.status === 409) {
        const body = await res.json().catch(() => ({}));
        if (body.current_revision_id) {
            config.currentRevisionId = body.current_revision_id;
        }
        surfaceFieldSaveFailure('Someone else saved this page. Your edit is still here — try again.');

        return { pageId: parsed.pageId, ...body };
    }

    if (! res.ok) {
        surfaceFieldSaveFailure('Save failed. Your edit did not apply.');

        return null;
    }

    const body = await res.json().catch(() => ({}));
    if (body.draft_revision_id) {
        config.currentRevisionId = body.draft_revision_id;
    }

    // Returned (was previously discarded) so the mutation coordinator can advance its PER-PAGE revision
    // after a committed save. config.currentRevisionId above is a single-page legacy scalar and is not
    // what currentRevision(pageId) reads.
    return { pageId: parsed.pageId, ...body };
}

export async function postOperation(config, url, body, options = {}) {
    const { revisionBase, structureEpoch, compositionRevision, catalogueRevision, channel = null } = options;
    const payload = { ...body };

    if (revisionBase !== undefined && revisionBase !== null) {
        payload.revision_base = revisionBase;
    }
    if (structureEpoch !== undefined && structureEpoch !== null) {
        payload.structure_epoch = structureEpoch;
    }
    if (compositionRevision !== undefined && compositionRevision !== null) {
        payload.composition_revision = compositionRevision;
    }
    if (catalogueRevision !== undefined && catalogueRevision !== null) {
        payload.catalogue_revision = catalogueRevision;
    }

    const headers = {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': config.csrfToken,
    };
    if (revisionBase !== undefined && revisionBase !== null) {
        headers['X-Page-Revision-Base'] = String(revisionBase);
    }
    if (channel) {
        // Declares this write as agent-driven. It can only ever make the server STRICTER (the webmcp
        // channel additionally requires the actor's role), so a caller omitting it gains nothing.
        headers['X-Editor-Channel'] = String(channel);
    }
    if (channel === 'webmcp' && config.agentSessionId) {
        headers['X-Editor-Agent-Session'] = String(config.agentSessionId);
    }

    const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers,
        body: JSON.stringify(payload),
    });

    return await res.json().catch(() => ({}));
}

export async function getJson(config, url, channel = null) {
    const headers = { Accept: 'application/json' };

    // This argument was accepted and then silently dropped, so every agent READ arrived unlabelled and was
    // gated and audited as a human one — the read half of the channel-laundering the write path already
    // fixed. It matters in all three live flag combinations: with humans off + agents on the reads 403 and
    // the agent can write but not read; with humans on + agents off the reads bypass the agent flag
    // entirely (capabilities are baked in at shell render, so an open tab keeps reading after the flag is
    // switched off); and with both on the log records agent reads as `ui`.
    if (channel) {
        headers['X-Editor-Channel'] = String(channel);
    }
    if (channel === 'webmcp' && config.agentSessionId) {
        headers['X-Editor-Agent-Session'] = String(config.agentSessionId);
    }

    const res = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers,
    });

    if (! res.ok) {
        throw new Error(`GET ${url} failed with ${res.status}`);
    }

    return await res.json();
}

export function parseFieldKey(fieldKey) {
    const parts = String(fieldKey ?? '').split('.');
    if (parts[0] !== 'page' || parts[2] !== 'section' || parts.length < 5) {
        return null;
    }

    return {
        pageId: parts[1],
        sectionIndex: parseInt(parts[3], 10),
        fieldPath: parts.slice(4).join('.'),
    };
}

function urlForFieldUpdate(templateUrl, pageId) {
    // The shell hands us the route with a /pages/0/ placeholder. Replace
    // whatever page id is there — same approach as form-panel.js urlFor.
    return String(templateUrl ?? '').replace(
        /\/pages\/\d+\/fields$/,
        `/pages/${pageId}/fields`,
    );
}

function surfaceFieldSaveFailure(message) {
    let banner = document.getElementById('site-editor-field-save-banner');
    if (! banner) {
        banner = document.createElement('div');
        banner.id = 'site-editor-field-save-banner';
        banner.setAttribute('role', 'alert');
        document.body.prepend(banner);
    }
    banner.textContent = message;
    banner.className = 'px-4 py-2 text-sm text-amber-800 bg-amber-50 dark:bg-amber-950/40 dark:text-amber-200';
}
