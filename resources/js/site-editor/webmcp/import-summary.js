/**
 * Slide-in summary of a committed `import_products` call, built from the tool result alone.
 *
 * It is a side panel, not a modal: nothing behind it is blocked, the list underneath keeps
 * working, and Escape or either close control dismisses it. At most one panel exists — a
 * later import replaces the earlier summary rather than stacking on it.
 */
export const IMPORT_SUMMARY_ID = 'webmcp-import-summary';

const CLOSE_MS = 300;
const VALUE_MAX = 160;
const CONTROL_AND_FORMAT = /\p{C}/gu;

const NOTE_LABELS = {
    missing_description: 'No description',
    duplicate_category: 'Duplicate category skipped',
    missing_variant_label: 'Unlabelled variant',
    price_missing: 'No price — set a price before publishing',
    matches_existing: 'Already in the catalogue',
    slug_taken: 'Slug already in use',
    missing_name: 'No name',
    category_not_found: 'Category not found',
    missing_variants: 'No variants',
    bad_sku: 'Invalid SKU',
    duplicate_sku: 'Duplicate SKU',
    bad_price: 'Invalid price',
    published_not_accepted: 'Cannot import as published',
    write_failed: 'Could not be saved',
};

let closeTimer = null;
let previousFocus = null;

function text(value) {
    return String(value ?? '')
        .normalize('NFC')
        .replace(CONTROL_AND_FORMAT, '')
        .slice(0, VALUE_MAX);
}

export function noteLabel(code) {
    const key = String(code ?? '');

    return NOTE_LABELS[key] ?? text(key.replaceAll('_', ' '));
}

export function formatPence(pence) {
    if (typeof pence !== 'number' || ! Number.isFinite(pence)) {
        return '—';
    }

    return (pence / 100).toFixed(2);
}

function reducedMotion() {
    try {
        return window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches === true;
    } catch {
        return false;
    }
}

function nextFrame(fn) {
    if (typeof window.requestAnimationFrame === 'function') {
        window.requestAnimationFrame(() => fn());

        return;
    }
    setTimeout(fn, 0);
}

function rowName(row) {
    if (typeof row?.name === 'string' && row.name.trim() !== '') {
        return text(row.name);
    }
    if (typeof row?.slug === 'string' && row.slug !== '') {
        return text(row.slug);
    }

    return row?.source_row != null ? `Row ${text(row.source_row)}` : 'Untitled';
}

function list(value) {
    return Array.isArray(value) ? value.map(noteLabel).filter((label) => label !== '') : [];
}

function el(tag, className, content) {
    const node = document.createElement(tag);
    if (className) {
        node.className = className;
    }
    if (content !== undefined) {
        node.textContent = content;
    }

    return node;
}

const BADGE_TONES = {
    red: 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-950/40 dark:text-red-300',
    amber: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300',
    zinc: 'border-zinc-300 bg-zinc-50 text-zinc-700 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300',
};

function badge(label, tone) {
    const classes = BADGE_TONES[tone] ?? BADGE_TONES.zinc;

    return el('span', `inline-flex items-center rounded-full border px-2 py-0.5 text-[0.7rem] font-medium ${classes}`, label);
}

function notesCell(row, rejected) {
    const cell = el('td', 'px-3 py-2 align-top text-xs');
    cell.dataset.importNotes = '1';
    const warnings = list(row.warnings);
    const errors = rejected ? list(row.errors) : [];

    if (warnings.length === 0 && errors.length === 0) {
        cell.appendChild(el('span', 'text-zinc-400 dark:text-zinc-500', '—'));

        return cell;
    }

    if (warnings.length > 0) {
        cell.appendChild(el('span', 'text-amber-700 dark:text-amber-300', warnings.join('; ')));
    }
    if (errors.length > 0) {
        const line = el('span', 'text-red-600 dark:text-red-400', errors.join('; '));
        line.dataset.importErrors = '1';
        if (warnings.length > 0) {
            cell.appendChild(document.createElement('br'));
        }
        cell.appendChild(line);
    }

    return cell;
}

/**
 * A row is one of three things: a draft the import created, an existing product
 * the row matched (left untouched), or a rejected row that produced nothing.
 */
function rowKind(row) {
    if (row?.status === 'created') {
        return 'created';
    }
    if (row?.status === 'matched') {
        return 'matched';
    }

    return 'rejected';
}

function renderRow(row) {
    const kind = rowKind(row);
    const tr = el('tr', 'border-t border-zinc-100 dark:border-zinc-800');
    tr.dataset.importRow = kind;

    tr.appendChild(el('td', 'px-3 py-2 align-top font-medium text-zinc-900 dark:text-zinc-100', rowName(row)));
    tr.appendChild(el('td', 'px-3 py-2 align-top text-zinc-600 dark:text-zinc-400', typeof row?.category === 'string' && row.category !== '' ? text(row.category) : '—'));
    tr.appendChild(el('td', 'px-3 py-2 align-top text-right tabular-nums text-zinc-600 dark:text-zinc-400', formatPence(row?.price_pence)));

    const status = el('td', 'px-3 py-2 align-top');
    status.appendChild(kind === 'created' ? badge('Draft', 'zinc') : kind === 'matched' ? badge('Matched', 'amber') : badge('Rejected', 'red'));
    tr.appendChild(status);

    tr.appendChild(notesCell(row, kind === 'rejected'));

    return tr;
}

function renderTable(rows) {
    const wrap = el('div', 'overflow-x-auto');
    const table = el('table', 'w-full text-left text-sm');
    const head = document.createElement('thead');
    const headRow = el('tr', 'text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400');
    for (const [label, extra] of [['Product', ''], ['Category', ''], ['Price', 'text-right'], ['Status', ''], ['Notes', '']]) {
        headRow.appendChild(el('th', `px-3 py-2 font-medium ${extra}`, label));
    }
    head.appendChild(headRow);
    table.appendChild(head);

    const body = document.createElement('tbody');
    for (const row of rows) {
        body.appendChild(renderRow(row));
    }
    table.appendChild(body);
    wrap.appendChild(table);

    return wrap;
}

function button(label, variant) {
    const node = el('button', variant === 'primary'
        ? 'inline-flex items-center rounded-md bg-zinc-800 px-3 py-1.5 text-sm font-medium text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200'
        : 'inline-flex items-center rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800', label);
    node.type = 'button';

    return node;
}

function onKeydown(event) {
    if (event.key === 'Escape') {
        dismissImportSummary();
    }
}

function reviewDrafts() {
    try {
        if (typeof window.Livewire?.dispatch === 'function') {
            window.Livewire.dispatch('shop-filter-drafts');
        }
    } catch {
        // the list simply keeps its current filter
    }
    dismissImportSummary();
}

function buildPanel(data) {
    const created = Number.isInteger(data?.created) ? data.created : 0;
    const rows = Array.isArray(data?.results) ? data.results : [];
    const matched = Number.isInteger(data?.matched) ? data.matched : rows.filter((row) => rowKind(row) === 'matched').length;

    const panel = el('aside', 'fixed inset-y-0 right-0 z-[10004] flex w-[440px] max-w-full flex-col border-l border-zinc-200 bg-white shadow-2xl dark:border-zinc-700 dark:bg-zinc-900 motion-safe:transition-transform motion-safe:duration-300 motion-safe:ease-out');
    panel.id = IMPORT_SUMMARY_ID;
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'false');
    panel.setAttribute('aria-labelledby', `${IMPORT_SUMMARY_ID}-title`);
    panel.tabIndex = -1;
    panel.dataset.state = 'open';

    const header = el('div', 'flex items-start justify-between gap-3 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700');
    const titles = el('div', 'min-w-0');
    const title = el('h2', 'text-base font-semibold text-zinc-900 dark:text-white', `Imported ${created} draft ${created === 1 ? 'product' : 'products'}`);
    title.id = `${IMPORT_SUMMARY_ID}-title`;
    titles.appendChild(title);
    titles.appendChild(el('p', 'mt-1 text-xs text-zinc-500 dark:text-zinc-400', 'Nothing has been published. Drafts stay hidden on the live shop until you publish.'));
    if (matched > 0) {
        const note = el('p', 'mt-1 text-xs text-amber-700 dark:text-amber-300', `${matched} ${matched === 1 ? 'row matched a product' : 'rows matched products'} already in the catalogue and ${matched === 1 ? 'was' : 'were'} left alone.`);
        note.dataset.importMatched = String(matched);
        titles.appendChild(note);
    }
    header.appendChild(titles);

    const close = el('button', 'shrink-0 rounded-md p-1 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white', '×');
    close.type = 'button';
    close.setAttribute('aria-label', 'Close import summary');
    close.dataset.importClose = '1';
    close.addEventListener('click', () => dismissImportSummary());
    header.appendChild(close);
    panel.appendChild(header);

    const body = el('div', 'flex-1 overflow-y-auto overscroll-contain');
    body.appendChild(renderTable(rows));
    panel.appendChild(body);

    const footer = el('div', 'flex items-center justify-end gap-2 border-t border-zinc-200 px-4 py-3 dark:border-zinc-700');
    const secondary = button('Close', 'secondary');
    secondary.dataset.importClose = '1';
    secondary.addEventListener('click', () => dismissImportSummary());
    const primary = button('Review drafts', 'primary');
    primary.dataset.importReview = '1';
    primary.addEventListener('click', reviewDrafts);
    footer.append(secondary, primary);
    panel.appendChild(footer);

    return panel;
}

/**
 * Removes any open summary at once. Slide-out only ever delays the removal of an
 * already-dismissed panel; a replacement can be mounted immediately.
 */
function removeNow() {
    if (closeTimer !== null) {
        clearTimeout(closeTimer);
        closeTimer = null;
    }
    document.getElementById(IMPORT_SUMMARY_ID)?.remove();
    document.removeEventListener('keydown', onKeydown);
}

export function dismissImportSummary() {
    const panel = document.getElementById(IMPORT_SUMMARY_ID);
    if (! panel) {
        return;
    }
    document.removeEventListener('keydown', onKeydown);
    const focusTarget = previousFocus;
    previousFocus = null;
    if (panel.dataset.state === 'closed') {
        return;
    }
    panel.dataset.state = 'closed';

    if (reducedMotion()) {
        panel.remove();
    } else {
        panel.classList.add('translate-x-full');
        closeTimer = setTimeout(() => {
            closeTimer = null;
            panel.remove();
        }, CLOSE_MS);
    }

    if (focusTarget && typeof focusTarget.focus === 'function' && focusTarget.isConnected) {
        focusTarget.focus();
    }
}

export function showImportSummary({ data }) {
    removeNow();

    const panel = buildPanel(data);
    const animate = ! reducedMotion();
    if (animate) {
        panel.classList.add('translate-x-full');
    }
    document.body.appendChild(panel);
    if (animate) {
        nextFrame(() => panel.classList.remove('translate-x-full'));
    }

    previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    document.addEventListener('keydown', onKeydown);
    panel.focus({ preventScroll: true });

    return panel;
}
