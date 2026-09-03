let pendingApprovalCount = 0;
let lastLive = false;

function pillClass(live) {
    const base = 'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium';

    if (live) {
        return `${base} border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-200`;
    }

    return `${base} border-zinc-300 bg-zinc-50 text-zinc-600 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300`;
}

function pillLabel(live) {
    const base = live ? 'Site tools live' : 'Site tools off';

    return pendingApprovalCount > 0 ? `${base} · ${pendingApprovalCount}` : base;
}

/**
 * Pending-count signal for the pill. Opening the review is opt-in so a later
 * renderAgentView() (every ordinary tool call) cannot pop it back open.
 */
export function signalPendingApprovals({ count = 0, open = false } = {}) {
    pendingApprovalCount = Number(count) || 0;
    const pill = document.getElementById('webmcp-agent-pill');
    if (pill) {
        pill.textContent = pillLabel(lastLive);
    }
    if (open) {
        const panel = document.getElementById('webmcp-agent-view');
        if (panel) {
            panel.hidden = false;
        }
    }
}

function hostForAgentView() {
    // The CP top bar is the pill's home — it previously fell
    // back to document.body on non-editor pages and floated over the sidebar.
    const topbarStatus = document.querySelector('[data-cp-status-slot]');
    if (topbarStatus) {
        return topbarStatus;
    }

    const toolbar = document.getElementById('site-editor-toolbar');
    if (toolbar) {
        return toolbar.querySelector(':scope > div') ?? toolbar;
    }

    return document.querySelector('[data-editor-toolbar]') ?? document.body;
}

function ensureAgentView() {
    let root = document.getElementById('webmcp-agent-tools');
    if (root) {
        return root;
    }

    pendingApprovalCount = 0;

    root = document.createElement('div');
    root.id = 'webmcp-agent-tools';
    root.className = 'relative flex items-center gap-2';

    const pill = document.createElement('button');
    pill.id = 'webmcp-agent-pill';
    pill.type = 'button';
    pill.className = pillClass(false);
    pill.addEventListener('click', () => {
        const panel = document.getElementById('webmcp-agent-view');
        if (! panel) {
            return;
        }
        panel.hidden = ! panel.hidden;
    });
    root.appendChild(pill);

    const panel = document.createElement('div');
    panel.id = 'webmcp-agent-view';
    panel.hidden = true;
    panel.setAttribute('role', 'region');
    panel.setAttribute('aria-label', "Agent's view");
    panel.className = 'absolute right-0 top-full z-[10003] mt-2 w-80 max-h-[70vh] max-w-[calc(100vw-1rem)] overflow-y-auto overscroll-contain rounded-md border border-zinc-200 bg-white p-3 shadow-lg dark:border-zinc-700 dark:bg-zinc-900';

    const heading = document.createElement('h2');
    heading.className = 'text-sm font-semibold text-zinc-900 dark:text-white';
    heading.textContent = "Agent's view";
    panel.appendChild(heading);

    // The effective exposure set for this site (spec § 8) — "what does this tenant register" is
    // answerable in the review, not only from editor:mcp-tools.
    const exposure = document.createElement('p');
    exposure.id = 'webmcp-agent-exposure';
    exposure.className = 'mt-1 text-xs text-zinc-500 dark:text-zinc-400';
    panel.appendChild(exposure);

    const list = document.createElement('ul');
    list.id = 'webmcp-agent-tool-list';
    list.className = 'mt-2 flex flex-col gap-1';
    panel.appendChild(list);

    const logList = document.createElement('ol');
    logList.id = 'webmcp-agent-log';
    logList.className = 'mt-3 flex flex-col gap-1 border-t border-zinc-200 pt-2 dark:border-zinc-700';
    panel.appendChild(logList);

    root.appendChild(panel);
    hostForAgentView()?.appendChild(root);

    return root;
}

export function renderAgentView({ live = false, tools = [], log = [], exposureSet = null } = {}) {
    ensureAgentView();
    lastLive = live;

    const pill = document.getElementById('webmcp-agent-pill');
    if (pill) {
        pill.textContent = pillLabel(live);
        pill.className = pillClass(live);
    }

    const exposure = document.getElementById('webmcp-agent-exposure');
    if (exposure) {
        exposure.replaceChildren();
        if (exposureSet !== null) {
            exposure.textContent = `Exposure set: ${exposureSet}`;
        }
    }

    const list = document.getElementById('webmcp-agent-tool-list');
    if (list) {
        list.replaceChildren();
        for (const tool of tools) {
            const item = document.createElement('li');
            item.className = 'flex items-center justify-between gap-2 text-xs text-zinc-700 dark:text-zinc-300';
            item.dataset.toolName = tool.name;

            const name = document.createElement('span');
            name.textContent = tool.name;

            const badge = document.createElement('span');
            badge.className = 'rounded-full border px-1.5 py-0.5 text-[0.65rem] uppercase tracking-wide '
                + (tool.readOnly
                    ? 'border-zinc-300 text-zinc-500 dark:border-zinc-600 dark:text-zinc-400'
                    : 'border-amber-300 text-amber-700 dark:border-amber-700 dark:text-amber-300');
            badge.textContent = tool.readOnly ? 'read' : 'write';

            item.append(name, badge);
            list.appendChild(item);
        }
    }

    const logList = document.getElementById('webmcp-agent-log');
    if (logList) {
        logList.replaceChildren();
        for (const entry of log.slice(-10).reverse()) {
            const item = document.createElement('li');
            item.className = 'text-xs text-zinc-500 dark:text-zinc-400';
            const status = entry.ok ? 'ok' : (entry.code ?? 'error');
            item.textContent = `${entry.name} ${status}`;
            const warnings = Array.isArray(entry.warnings) ? entry.warnings : [];
            for (const warning of warnings) {
                const warningNode = document.createElement('div');
                warningNode.dataset.agentWarning = '';
                warningNode.textContent = warning.message ?? '';
                item.appendChild(warningNode);
            }
            logList.appendChild(item);
        }
    }
}
