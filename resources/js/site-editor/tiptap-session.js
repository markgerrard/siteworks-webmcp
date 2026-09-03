let activeSession = null;

export function getActiveTipTapSession() {
    return activeSession;
}

export function setActiveTipTapSession(session) {
    activeSession = session;
}

export function clearActiveTipTapSession(session) {
    if (activeSession === session) {
        activeSession = null;
    }
}

export async function destroyActiveTipTap() {
    const session = activeSession;
    if (! session) {
        return;
    }

    await session.commit();
    clearActiveTipTapSession(session);
}

export async function commitActiveSession() {
    // Same tolerance as activateTipTap(): a failed save must never strand the
    // caller (Task 26's handshake replies after this resolves). The session is
    // cleared either way; on true conflict the UI reloads via field-save.
    const session = getActiveTipTapSession();
    if (! session) {
        return;
    }
    try {
        await session.commit();
    } catch (_) {
        // swallow — see above
    }
    clearActiveTipTapSession(session);
}

export function createTipTapCommit({ initialDoc, getDoc, getHtml, onSave, finish }) {
    let committed = false;

    return async () => {
        if (committed) {
            return;
        }

        committed = true;
        const doc = getDoc();
        const html = getHtml();

        if (JSON.stringify(doc) !== JSON.stringify(initialDoc)) {
            await onSave(doc);
        }

        finish(html, doc);
    };
}
