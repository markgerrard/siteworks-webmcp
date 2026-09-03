/**
 * Shared field-save utility used by edit-overlay and image-picker.
 *
 * Saves are serialised through a per-module promise chain: a second save
 * waits for the first to resolve before firing, so its X-Page-Revision-Base
 * always reflects the newest draft revision id. This kills the self-inflicted
 * 409s that happened when two fields were edited in quick succession.
 *
 * On a real 409 (another tab/user won the race), the server returns the
 * current_revision_id in the body; we retry once with that value. If the
 * retry also 409s, we surface a real sync message.
 *
 * No location.reload() — the DOM already reflects the user's edit, the
 * draft is persisted server-side, and config.currentRevisionId is the
 * single source of truth for the next save's base.
 */

let saveChain = Promise.resolve();

export function saveField(el, value, config) {
    const next = saveChain.then(() => performSave(el, value, config));
    // Keep the chain alive even if one save throws.
    saveChain = next.catch(() => {});
    return next;
}

async function performSave(el, value, config) {
    const fieldPath = el.dataset.editable;
    const parts = fieldPath.split('.');
    // path format: page.{id}.section.{n}.{field...}
    const sectionIndex = parseInt(parts[3], 10);
    const fieldOnly = parts.slice(4).join('.');

    const payload = JSON.stringify({
        section_index: sectionIndex,
        field_path: fieldOnly,
        value,
    });

    let response = await postSave(config, payload);

    if (response.status === 409) {
        const body = await response.json().catch(() => ({}));
        const fresh = body.current_revision_id;
        if (fresh != null) {
            config.currentRevisionId = fresh;
            response = await postSave(config, payload);
        }
    }

    if (response.status === 409) {
        alert('Page changed while you were editing. Refreshing to sync.');
        location.reload();
        return;
    }

    if (! response.ok) {
        const body = await response.json().catch(() => ({}));
        const specific = body?.errors?.value?.[0];
        alert('Save failed: ' + (specific || body.message || response.statusText));
        return;
    }

    const data = await response.json();
    if (data.draft_revision_id) {
        config.currentRevisionId = data.draft_revision_id;
    }
    // Intentionally no reload — the user's edit is already visible in the DOM
    // and the draft is persisted. Caller handles any section-specific refresh.
}

function postSave(config, body) {
    const headers = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Page-Revision-Base': config.currentRevisionId || '',
    };

    // /_edit/* routes use per-session cookie CSRF; admin-host routes use session CSRF.
    if (config.fieldUpdateUrl && config.fieldUpdateUrl.startsWith('/_edit/')) {
        headers['X-Edit-Csrf'] = config.editCsrf || '';
    } else {
        headers['X-CSRF-TOKEN'] = config.csrfToken || '';
    }

    return fetch(config.fieldUpdateUrl, {
        method: 'POST',
        headers,
        body,
    });
}
