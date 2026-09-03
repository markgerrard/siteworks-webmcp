import { signalPendingApprovals } from './agent-view.js';

const POLL_MS = 2000;
const CEILING_MS = 5 * 60 * 1000;
const VALUE_MAX = 120;
const CONTROL_AND_FORMAT = /\p{C}/gu;

function sanitise(value) {
    return String(value ?? '')
        .normalize('NFC')
        .replace(CONTROL_AND_FORMAT, '')
        .slice(0, VALUE_MAX);
}

function formatCountdown(expiresAt) {
    const remainingMs = new Date(expiresAt).getTime() - Date.now();
    if (! Number.isFinite(remainingMs)) {
        return '';
    }

    const remainingSec = Math.max(0, Math.floor(remainingMs / 1000));
    const minutes = Math.floor(remainingSec / 60);
    const seconds = remainingSec % 60;

    return `${minutes}:${String(seconds).padStart(2, '0')}`;
}

function hasAgentApproval(config) {
    return Array.isArray(config?.capabilities) && config.capabilities.includes('agent_approval');
}

function approvalsListUrl(siteId) {
    // Shell config (EditorShellController) carries no approvals URL — adding one
    // is PHP, which this lane must not touch. A path built from siteId is
    // same-origin on both editor surfaces.
    return `/sites/${siteId}/agent-approvals`;
}

function decisionUrl(siteId, id, verb) {
    return `${approvalsListUrl(siteId)}/${encodeURIComponent(id)}/${verb}`;
}

/**
 * Positional (per-section) approval presentation waits on stable section
 * identifiers (lane A1b Task 28). Whole-operation cards only today.
 */
function renderTargetDetail(row, cardEl) {
    void row;
    void cardEl;
}

function rowId(row) {
    return row?.id == null ? '' : String(row.id);
}

export function createApprovalView({ config }) {
    let timer = null;
    let startedAt = 0;
    let inFlight = false;
    let abortController = null;
    const surfacedIds = new Set();

    function host() {
        let root = document.getElementById('webmcp-approval-list');
        if (root) {
            return root;
        }
        const panel = document.getElementById('webmcp-agent-view');
        if (! panel) {
            return null;
        }
        root = document.createElement('section');
        root.id = 'webmcp-approval-list';
        const toolList = document.getElementById('webmcp-agent-tool-list');
        if (toolList && toolList.parentNode === panel) {
            panel.insertBefore(root, toolList);
        } else {
            panel.appendChild(root);
        }

        return root;
    }

    function notifyHuman(approvals) {
        const ids = approvals.map(rowId).filter(Boolean);
        const shouldOpen = ids.length > 0 && ids.some((id) => ! surfacedIds.has(id));
        for (const id of ids) {
            surfacedIds.add(id);
        }
        if (ids.length === 0) {
            surfacedIds.clear();
        }
        signalPendingApprovals({ count: ids.length, open: shouldOpen });
    }

    function stop() {
        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }
        startedAt = 0;
        abortController?.abort();
    }

    function appendField(card, key, value, { ltr = false } = {}) {
        const field = document.createElement('div');
        field.className = 'flex gap-2';
        const label = document.createElement('span');
        label.textContent = `${sanitise(key)}: `;
        const valueEl = document.createElement('span');
        if (ltr) {
            valueEl.dir = 'ltr';
        }
        valueEl.textContent = sanitise(value);
        field.append(label, valueEl);
        card.appendChild(field);
    }

    function renderRow(row) {
        const card = document.createElement('article');
        card.className = 'mt-3 flex flex-col gap-1 border-t border-zinc-200 pt-2 text-xs text-zinc-700 dark:border-zinc-700 dark:text-zinc-300';

        appendField(card, 'operation', row.operation);
        appendField(card, 'channel', row.channel);
        appendField(card, 'expires', formatCountdown(row.expires_at));

        const summary = row.summary && typeof row.summary === 'object' ? row.summary : {};
        for (const [key, value] of Object.entries(summary)) {
            appendField(card, key, value, { ltr: true });
        }

        renderTargetDetail(row, card);

        const approve = document.createElement('button');
        approve.type = 'button';
        approve.textContent = 'Approve';
        approve.addEventListener('click', () => {
            void decide(row.id, 'approve', card);
        });

        const deny = document.createElement('button');
        deny.type = 'button';
        deny.textContent = 'Deny';
        deny.addEventListener('click', () => {
            void decide(row.id, 'deny', card);
        });

        card.append(approve, deny);

        return card;
    }

    function render(approvals) {
        const root = host();
        if (! root) {
            return;
        }

        root.replaceChildren();
        for (const row of approvals) {
            root.appendChild(renderRow(row));
        }
        notifyHuman(approvals);
    }

    async function poll() {
        if (startedAt > 0 && Date.now() - startedAt >= CEILING_MS) {
            stop();

            return;
        }
        if (inFlight) {
            return;
        }

        inFlight = true;
        abortController = new AbortController();
        try {
            const res = await fetch(approvalsListUrl(config.siteId), {
                method: 'GET',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: abortController.signal,
            });
            if (! res.ok) {
                return;
            }

            const body = await res.json();
            const approvals = Array.isArray(body?.approvals) ? body.approvals : [];
            render(approvals);
            if (approvals.length === 0) {
                stop();
            } else {
                startedAt = Date.now();
            }
        } catch {
            // A failed fetch does not kill the poll; the next tick retries and
            // still counts against the five-minute ceiling. Abort from stop()
            // lands here too.
        } finally {
            inFlight = false;
        }
    }

    function startPolling() {
        if (timer !== null) {
            return;
        }

        startedAt = Date.now();
        void poll();
        timer = setInterval(() => {
            void poll();
        }, POLL_MS);
    }

    function renderDecisionOutcome(card, status) {
        let line = card.querySelector('[data-decision-outcome]');
        if (! line) {
            line = document.createElement('p');
            line.dataset.decisionOutcome = '1';
            card.appendChild(line);
        }
        if (status === 409) {
            line.textContent = 'This approval is no longer available.';
        } else if (status === 419) {
            line.textContent = 'Your session expired. Refresh the page and try again.';
        } else {
            line.textContent = 'Could not apply this decision.';
        }
    }

    function setCardDeciding(card, deciding) {
        if (! card) {
            return;
        }
        card.dataset.deciding = deciding ? '1' : '0';
        for (const button of card.querySelectorAll('button')) {
            button.disabled = deciding;
        }
    }

    async function decide(id, verb, card) {
        if (card?.dataset.deciding === '1') {
            return;
        }
        setCardDeciding(card, true);

        try {
            const res = await fetch(decisionUrl(config.siteId, id, verb), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': config.csrfToken,
                },
            });
            let body = {};
            try {
                body = await res.json();
            } catch {
                body = {};
            }
            if (! res.ok || body?.ok === false) {
                renderDecisionOutcome(card, res.status);

                return;
            }
        } catch {
            renderDecisionOutcome(card, 0);
            setCardDeciding(card, false);

            return;
        }

        await poll();
    }

    function noticeEnvelope(envelope) {
        try {
            if (! hasAgentApproval(config)) {
                return;
            }
            if (envelope?.error?.code !== 'approval_required') {
                return;
            }
            startPolling();
        } catch {
            // A view failure must never escape into the tool execute path.
        }
    }

    return { noticeEnvelope, stop };
}
