// resources/js/site-editor/form-panel.js
//
// Slide-out form settings, opened from a click inside the preview iframe.
// Parent-side by design: same shape as image-picker.js, and it keeps the
// iframe dumb, which matters because that document renders generated site
// content on a separate origin.

import { resolveCurrentFormRevision } from './form-revision.js';
import { EditorBusyError } from './mutation-coordinator.js';

const RESERVED = ['name', 'email'];
const TYPES = ['text', 'tel', 'email', 'date', 'textarea', 'select', 'radio'];
const CHOICE_TYPES = ['select', 'radio'];

const inputClass =
    'mt-1 w-full rounded-md border border-zinc-300 bg-white px-2.5 py-1.5 text-sm text-zinc-900 ' +
    'focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:border-zinc-600 ' +
    'dark:bg-zinc-800 dark:text-zinc-100';

const btnClass =
    'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium ' +
    'transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 ' +
    'focus-visible:ring-offset-zinc-50 dark:focus-visible:ring-offset-zinc-900';

export function mountFormPanel({ bridge, config, coordinator }) {
    let state = null;   // { sectionIndex, definition, revisionId }
    let root = null;
    let banner = null;
    let highlightTimer = null;

    async function open({ path, kind, field }) {
        // The marker carries BOTH ids: page.<pageId>.section.<index>. Take the
        // page id from it, never from the shell config — the preview iframe can
        // navigate to another page, and pairing that page's section index with
        // the originally-opened page id edits the wrong page.
        const target = parseMarker(path);
        if (! target) return renderError('Could not load this form.');
        const { pageId, sectionIndex } = target;

        if (state?.pageId === pageId && state.sectionIndex === sectionIndex) {
            state.kind = kind;
            selectField(field);
            return;
        }

        try {
            const res = await fetch(urlFor(config.formDefinitionUrl, pageId, sectionIndex), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });

            if (! res.ok) return renderError('Could not load this form.');

            const definition = await res.json();
            state = { pageId, sectionIndex, definition, revisionId: definition.revision_id, kind };
            config.currentRevisionId = definition.revision_id;
            render(definition);
            selectField(field);
        } catch {
            return renderError('Could not load this form.');
        }
    }

    async function save() {
        if (! state) return;

        try {
            const { result } = await coordinator.runExternal({
                pageId: state.pageId,
                structural: false,
                fn: persistForm,
            });
            applySaveResult(result);
        } catch (error) {
            if (error instanceof EditorBusyError) {
                return renderError('The preview is busy. Try again in a moment.');
            }

            return renderError('Save failed. Your changes are still here.');
        }
    }

    async function persistForm() {
        const res = await fetch(urlFor(config.formUpdateUrl, state.pageId, state.sectionIndex), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': config.csrfToken,
                // Optimistic concurrency, same header PageFieldUpdateController reads.
                'X-Page-Revision-Base': resolveCurrentFormRevision(config, state),
                Accept: 'application/json',
            },
            body: JSON.stringify(collectFromDom()),
        });

        const body = await res.json().catch(() => ({}));

        if (res.status === 409) {
            return {
                ok: false,
                error: {
                    code: 'stale_revision',
                    message: body.message ?? 'Page revision base is stale.',
                    current_revision_id: body.current_revision_id,
                },
                state: { draft_revision_id: body.current_revision_id },
            };
        }

        if (res.status === 422) {
            return {
                ok: false,
                error: { code: 'validation', message: 'Please fix the highlighted fields.', fields: body.errors },
                errors: body.errors,
            };
        }

        if (! res.ok) {
            throw new Error('Save failed');
        }

        return {
            ok: true,
            data: {
                html: body.html,
                draft_revision_id: body.revision_id,
                stored_index: state.sectionIndex,
            },
            state: { draft_revision_id: body.revision_id },
        };
    }

    function applySaveResult(result) {
        if (result?.ok === false && result.error?.code === 'stale_revision') {
            // Do NOT close or discard input — the operator's edit is still valid,
            // it just needs rebasing on someone else's change.
            const current = result.error.current_revision_id;
            if (current) {
                state.revisionId = current;
                config.currentRevisionId = current;
            }

            return renderConflict();
        }

        if (result?.ok === false && (result.error?.code === 'validation' || result.errors)) {
            return renderFieldErrors(result.errors ?? result.error?.fields);
        }

        if (result?.ok === false) {
            return renderError('Save failed. Your changes are still here.');
        }

        const revisionId = result?.state?.draft_revision_id ?? result?.data?.draft_revision_id;
        if (revisionId) {
            state.revisionId = revisionId;
            config.currentRevisionId = revisionId;
        }
        snapshotIntoDefinition();
        showBanner('Saved.', 'success');
    }

    function parseMarker(path) {
        const m = /^page\.(\d+)\.section\.(\d+)$/.exec(String(path ?? ''));

        return m ? { pageId: m[1], sectionIndex: parseInt(m[2], 10) } : null;
    }

    function urlFor(templateUrl, pageId, sectionIndex) {
        // The shell hands us the route with the page id and a trailing /0
        // placeholder. Both are replaced: the section index comes from the
        // marker, and so does the page id, because the iframe may have
        // navigated away from the page the shell was opened on.
        return templateUrl.replace(
            /\/pages\/\d+\/form\/0$/,
            `/pages/${pageId}/form/${sectionIndex}`,
        );
    }

    function close() {
        if (root) {
            root.classList.add('translate-x-full');
            root.setAttribute('aria-hidden', 'true');
        }
        state = null;
    }

    function snapshotIntoDefinition() {
        if (! state || ! root) return;
        const collected = collectFromDom();
        state.definition.title = collected.title;
        state.definition.submit_label = collected.submit_label;
        state.definition.fields = collected.fields;
    }

    function collectFromDom() {
        const title = root.querySelector('[data-form-title]')?.value ?? '';
        const submitLabel = root.querySelector('[data-form-submit-label]')?.value ?? '';
        const fields = [];

        root.querySelectorAll('[data-field-row]').forEach((row) => {
            if (row.dataset.reserved === '1') return;

            const field = {
                label: row.querySelector('[data-field-label]')?.value ?? '',
                type: row.querySelector('[data-field-type]')?.value ?? 'text',
                required: Boolean(row.querySelector('[data-field-required]')?.checked),
                placeholder: row.querySelector('[data-field-placeholder]')?.value ?? '',
            };

            const existingName = row.dataset.fieldName;
            if (existingName) {
                field.name = existingName;
            }

            if (CHOICE_TYPES.includes(field.type)) {
                field.options = Array.from(row.querySelectorAll('[data-field-option]'))
                    .map((input) => input.value)
                    .filter((value) => value.trim() !== '');
            }

            fields.push(field);
        });

        return { title, submit_label: submitLabel, fields };
    }

    function ensureRoot() {
        if (root) return root;

        root = document.createElement('aside');
        root.id = 'site-editor-form-panel';
        root.setAttribute('role', 'dialog');
        root.setAttribute('aria-label', 'Edit form');
        root.className =
            'fixed inset-y-0 right-0 z-[10001] flex w-[22rem] max-w-full flex-col border-l border-zinc-200 ' +
            'bg-zinc-50 shadow-xl transition-transform duration-200 ease-out dark:border-zinc-700 dark:bg-zinc-900 ' +
            'translate-x-full';

        document.body.appendChild(root);
        return root;
    }

    function render(definition, { expandedIndex = null, focusLabel = false } = {}) {
        const panel = ensureRoot();
        panel.replaceChildren();
        panel.classList.remove('translate-x-full');
        panel.setAttribute('aria-hidden', 'false');

        const reserved = Array.isArray(definition.reserved) ? definition.reserved : RESERVED;
        const customFields = (definition.fields ?? []).filter((field) => ! reserved.includes(field.name));
        const maxFields = Number(definition.max_fields ?? 8);
        const cappedFields = definition.section_type === 'lead_form'
            ? customFields.filter((field) => field.name !== 'message')
            : customFields;
        const atCap = cappedFields.length >= maxFields;

        const header = document.createElement('div');
        header.className = 'flex items-center justify-between gap-2 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700';
        const heading = document.createElement('h2');
        heading.className = 'text-sm font-semibold text-zinc-900 dark:text-white';
        heading.textContent = 'Edit form';
        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.id = 'form-panel-close';
        closeBtn.className = btnClass + ' border border-zinc-300 bg-white text-zinc-700 hover:bg-zinc-100 ' +
            'dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:hover:bg-zinc-700';
        closeBtn.textContent = 'Close';
        closeBtn.addEventListener('click', close);
        header.appendChild(heading);
        header.appendChild(closeBtn);
        panel.appendChild(header);

        banner = document.createElement('div');
        banner.id = 'form-panel-banner';
        banner.className = 'hidden px-4 py-2 text-sm';
        panel.appendChild(banner);

        const body = document.createElement('div');
        body.className = 'flex-1 overflow-y-auto px-4 py-3 space-y-4';

        // Panel order mirrors the order the fields appear on the page:
        // heading, the always-on Name/Email, the custom fields, then the submit
        // button last. Anything else makes the custom fields look like they sit
        // below the button they actually render above.
        body.appendChild(labelledInput('Heading', 'data-form-title', definition.title ?? '', 60));

        const reservedBlock = document.createElement('div');
        reservedBlock.className = 'space-y-2';
        reserved.forEach((key) => {
            reservedBlock.appendChild(reservedRow(key));
        });
        body.appendChild(reservedBlock);

        const list = document.createElement('div');
        list.id = 'form-panel-fields';
        list.className = 'space-y-3';
        customFields.forEach((field, index) => {
            list.appendChild(fieldRow(field, index, customFields.length));
        });
        body.appendChild(list);

        const addBtn = document.createElement('button');
        addBtn.type = 'button';
        addBtn.id = 'form-panel-add-field';
        addBtn.className = btnClass + ' w-full justify-center border border-dashed border-zinc-300 text-zinc-700 ' +
            'hover:bg-zinc-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-zinc-600 dark:text-zinc-200 ' +
            'dark:hover:bg-zinc-800';
        addBtn.textContent = '+ Add field';
        addBtn.disabled = atCap;
        addBtn.addEventListener('click', () => {
            if (atCap) return;
            snapshotIntoDefinition();
            state.definition.fields = [
                ...(state.definition.fields ?? []).filter((field) => ! reserved.includes(field.name)),
                { label: '', type: 'text', required: false, placeholder: '' },
            ];
            render(state.definition, {
                expandedIndex: state.definition.fields.length - 1,
                focusLabel: true,
            });
        });
        body.appendChild(addBtn);

        body.appendChild(labelledInput('Submit button', 'data-form-submit-label', definition.submit_label ?? '', 32));

        panel.appendChild(body);

        const footer = document.createElement('div');
        footer.className = 'flex items-center justify-end gap-2 border-t border-zinc-200 px-4 py-3 dark:border-zinc-700';
        const cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.id = 'form-panel-cancel';
        cancelBtn.className = btnClass + ' border border-zinc-300 bg-white text-zinc-700 hover:bg-zinc-100 ' +
            'dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:hover:bg-zinc-700';
        cancelBtn.textContent = 'Cancel';
        cancelBtn.addEventListener('click', close);
        const saveBtn = document.createElement('button');
        saveBtn.type = 'button';
        saveBtn.id = 'form-panel-save';
        saveBtn.className = btnClass + ' bg-blue-600 text-white shadow-sm hover:bg-blue-500 focus-visible:ring-blue-500';
        saveBtn.textContent = 'Save';
        saveBtn.addEventListener('click', () => { save(); });
        footer.appendChild(cancelBtn);
        footer.appendChild(saveBtn);
        panel.appendChild(footer);

        if (expandedIndex !== null) {
            const row = list.querySelector(`[data-field-index="${expandedIndex}"]`);
            if (row) {
                setExpandedRow(row, true);
                if (focusLabel) {
                    row.querySelector('[data-field-label]')?.focus();
                }
            }
        }
    }

    function labelledInput(labelText, attr, value, maxLength) {
        const wrap = document.createElement('label');
        wrap.className = 'block text-xs font-medium text-zinc-600 dark:text-zinc-300';
        wrap.appendChild(document.createTextNode(labelText));
        const input = document.createElement('input');
        input.type = 'text';
        input.className = inputClass;
        input.value = value ?? '';
        input.maxLength = maxLength;
        input.setAttribute(attr, '');
        wrap.appendChild(input);
        return wrap;
    }

    function reservedRow(key) {
        const row = document.createElement('div');
        row.dataset.fieldRow = '';
        row.dataset.reserved = '1';
        row.dataset.fieldName = key;
        row.className = 'rounded-md border border-zinc-200 bg-zinc-100 px-3 py-2 transition-shadow ' +
            'dark:border-zinc-700 dark:bg-zinc-800/60';

        const label = document.createElement('div');
        label.className = 'text-sm font-medium capitalize text-zinc-700 dark:text-zinc-200';
        label.textContent = key;

        const hint = document.createElement('p');
        hint.className = 'mt-0.5 text-xs text-zinc-500 dark:text-zinc-400';
        hint.textContent = 'Always on the form — every enquiry needs these';

        row.appendChild(label);
        row.appendChild(hint);
        return row;
    }

    function fieldRow(field, index, total) {
        const row = document.createElement('div');
        row.dataset.fieldRow = '';
        row.dataset.fieldIndex = String(index);
        if (field.name) {
            row.dataset.fieldName = field.name;
        }
        row.className = 'rounded-md border border-zinc-200 bg-white transition-shadow ' +
            'dark:border-zinc-700 dark:bg-zinc-800';

        const header = document.createElement('div');
        header.className = 'flex items-center gap-1 p-2';

        const bodyId = `form-panel-field-${index}-body`;
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'flex min-w-0 flex-1 items-center gap-2 rounded px-1 py-1 text-left ' +
            'focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500';
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-controls', bodyId);

        const summary = document.createElement('span');
        summary.className = 'min-w-0 flex-1 truncate text-sm font-medium text-zinc-700 dark:text-zinc-200';
        summary.textContent = field.label?.trim() || 'New field';

        const type = document.createElement('span');
        type.className = 'shrink-0 text-xs text-zinc-500 dark:text-zinc-400';
        type.textContent = field.type ?? 'text';

        const chevron = document.createElement('span');
        chevron.dataset.fieldChevron = '';
        chevron.className = 'w-3 shrink-0 text-center text-xs text-zinc-500 dark:text-zinc-400';
        chevron.setAttribute('aria-hidden', 'true');
        chevron.textContent = '›';

        toggle.appendChild(summary);
        toggle.appendChild(type);
        toggle.appendChild(chevron);
        toggle.addEventListener('click', () => {
            const shouldExpand = toggle.getAttribute('aria-expanded') !== 'true';
            collapseFieldRows();
            if (shouldExpand) {
                setExpandedRow(row, true);
            }
        });
        header.appendChild(toggle);

        const controls = document.createElement('div');
        controls.className = 'flex shrink-0 items-center gap-1';
        const up = iconButton('↑', 'Move up', index === 0, () => moveField(index, -1));
        const down = iconButton('↓', 'Move down', index === total - 1, () => moveField(index, 1));
        const remove = iconButton('×', 'Remove field', false, () => removeField(index));
        controls.appendChild(up);
        controls.appendChild(down);
        controls.appendChild(remove);
        header.appendChild(controls);
        row.appendChild(header);

        const body = document.createElement('div');
        body.id = bodyId;
        body.hidden = true;
        body.className = 'space-y-2 border-t border-zinc-200 p-3 dark:border-zinc-700';

        const labelWrap = document.createElement('label');
        labelWrap.className = 'block text-xs font-medium text-zinc-600 dark:text-zinc-300';
        labelWrap.appendChild(document.createTextNode('Label'));
        const labelInput = document.createElement('input');
        labelInput.type = 'text';
        labelInput.className = inputClass;
        labelInput.maxLength = 40;
        labelInput.value = field.label ?? '';
        labelInput.setAttribute('data-field-label', '');
        labelInput.addEventListener('input', () => {
            summary.textContent = labelInput.value.trim() || 'New field';
        });
        labelWrap.appendChild(labelInput);
        body.appendChild(labelWrap);

        const typeWrap = document.createElement('label');
        typeWrap.className = 'block text-xs font-medium text-zinc-600 dark:text-zinc-300';
        typeWrap.appendChild(document.createTextNode('Type'));
        const typeSelect = document.createElement('select');
        typeSelect.className = inputClass;
        typeSelect.setAttribute('data-field-type', '');
        TYPES.forEach((type) => {
            const option = document.createElement('option');
            option.value = type;
            option.textContent = type;
            if (type === (field.type ?? 'text')) option.selected = true;
            typeSelect.appendChild(option);
        });
        typeSelect.addEventListener('change', () => {
            snapshotIntoDefinition();
            render(state.definition, { expandedIndex: index });
        });
        typeWrap.appendChild(typeSelect);
        body.appendChild(typeWrap);

        const placeholderWrap = document.createElement('label');
        placeholderWrap.className = 'block text-xs font-medium text-zinc-600 dark:text-zinc-300';
        placeholderWrap.appendChild(document.createTextNode('Placeholder'));
        const placeholderInput = document.createElement('input');
        placeholderInput.type = 'text';
        placeholderInput.className = inputClass;
        placeholderInput.maxLength = 100;
        placeholderInput.value = field.placeholder ?? '';
        placeholderInput.setAttribute('data-field-placeholder', '');
        placeholderWrap.appendChild(placeholderInput);
        body.appendChild(placeholderWrap);

        const requiredWrap = document.createElement('label');
        requiredWrap.className = 'flex items-center gap-2 text-xs font-medium text-zinc-600 dark:text-zinc-300';
        const requiredInput = document.createElement('input');
        requiredInput.type = 'checkbox';
        requiredInput.setAttribute('data-field-required', '');
        requiredInput.checked = Boolean(field.required);
        requiredWrap.appendChild(requiredInput);
        requiredWrap.appendChild(document.createTextNode('Required'));
        body.appendChild(requiredWrap);

        if (CHOICE_TYPES.includes(field.type ?? '')) {
            body.appendChild(optionsEditor(field.options ?? []));
        }

        row.appendChild(body);

        return row;
    }

    function collapseFieldRows() {
        root.querySelectorAll('[data-field-index]').forEach((row) => {
            setExpandedRow(row, false);
        });
    }

    function setExpandedRow(row, expanded) {
        const toggle = row.querySelector('[aria-controls]');
        const body = toggle ? row.querySelector(`#${toggle.getAttribute('aria-controls')}`) : null;
        if (! toggle || ! body) return;

        toggle.setAttribute('aria-expanded', String(expanded));
        body.hidden = ! expanded;
        const chevron = toggle.querySelector('[data-field-chevron]');
        if (chevron) {
            chevron.textContent = expanded ? '⌄' : '›';
        }
    }

    function expandedFieldIndex() {
        const toggle = root.querySelector('[data-field-index] [aria-expanded="true"]');
        const index = toggle?.closest('[data-field-index]')?.dataset.fieldIndex;

        return index === undefined ? null : Number(index);
    }

    function selectField(fieldName) {
        if (! root) return;

        collapseFieldRows();
        clearHighlight();

        if (! fieldName) return;

        const row = Array.from(root.querySelectorAll('[data-field-row]'))
            .find((candidate) => candidate.dataset.fieldName === fieldName);
        if (! row) return;

        if (row.dataset.reserved !== '1') {
            setExpandedRow(row, true);
        }

        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        row.classList.add('ring-2', 'ring-blue-500', 'dark:ring-blue-400');
        highlightTimer = window.setTimeout(() => {
            row.classList.remove('ring-2', 'ring-blue-500', 'dark:ring-blue-400');
            highlightTimer = null;
        }, 1400);
    }

    function clearHighlight() {
        if (highlightTimer !== null) {
            window.clearTimeout(highlightTimer);
            highlightTimer = null;
        }
        root?.querySelectorAll('[data-field-row]').forEach((row) => {
            row.classList.remove('ring-2', 'ring-blue-500', 'dark:ring-blue-400');
        });
    }

    function optionsEditor(options) {
        const wrap = document.createElement('div');
        wrap.className = 'space-y-1.5';
        const title = document.createElement('div');
        title.className = 'text-xs font-medium text-zinc-600 dark:text-zinc-300';
        title.textContent = 'Options';
        wrap.appendChild(title);

        const list = document.createElement('div');
        list.className = 'space-y-1.5';
        const values = options.length > 0 ? options : [''];
        values.forEach((value) => {
            list.appendChild(optionRow(value));
        });
        wrap.appendChild(list);

        const add = document.createElement('button');
        add.type = 'button';
        add.className = 'text-xs font-medium text-blue-600 hover:text-blue-500';
        add.textContent = '+ Add option';
        add.addEventListener('click', () => {
            const maxOptions = Number(state?.definition?.max_options);
            if (! Number.isFinite(maxOptions) || list.querySelectorAll('[data-field-option]').length >= maxOptions) {
                return;
            }
            list.appendChild(optionRow(''));
        });
        wrap.appendChild(add);
        return wrap;
    }

    function optionRow(value) {
        const row = document.createElement('div');
        row.className = 'flex items-center gap-1';
        const input = document.createElement('input');
        input.type = 'text';
        input.className = inputClass;
        input.value = value;
        input.setAttribute('data-field-option', '');
        const remove = iconButton('×', 'Remove option', false, () => {
            row.remove();
        });
        row.appendChild(input);
        row.appendChild(remove);
        return row;
    }

    function iconButton(text, title, disabled, onClick) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.title = title;
        btn.textContent = text;
        btn.disabled = disabled;
        btn.className = 'inline-flex h-7 w-7 items-center justify-center rounded border border-zinc-300 text-sm ' +
            'text-zinc-600 hover:bg-zinc-100 disabled:cursor-not-allowed disabled:opacity-40 ' +
            'dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-700';
        btn.addEventListener('click', onClick);
        return btn;
    }

    function moveField(index, direction) {
        let expandedIndex = expandedFieldIndex();
        snapshotIntoDefinition();
        const reserved = Array.isArray(state.definition.reserved) ? state.definition.reserved : RESERVED;
        const fields = (state.definition.fields ?? []).filter((field) => ! reserved.includes(field.name));
        const next = index + direction;
        if (next < 0 || next >= fields.length) return;
        [fields[index], fields[next]] = [fields[next], fields[index]];
        state.definition.fields = fields;
        if (expandedIndex === index) {
            expandedIndex = next;
        } else if (expandedIndex === next) {
            expandedIndex = index;
        }
        render(state.definition, { expandedIndex });
    }

    function removeField(index) {
        let expandedIndex = expandedFieldIndex();
        snapshotIntoDefinition();
        const reserved = Array.isArray(state.definition.reserved) ? state.definition.reserved : RESERVED;
        const fields = (state.definition.fields ?? []).filter((field) => ! reserved.includes(field.name));
        fields.splice(index, 1);
        state.definition.fields = fields;
        if (expandedIndex === index) {
            expandedIndex = null;
        } else if (expandedIndex !== null && expandedIndex > index) {
            expandedIndex -= 1;
        }
        render(state.definition, { expandedIndex });
    }

    function showBanner(message, tone) {
        if (! banner) return;
        banner.classList.remove('hidden');
        banner.textContent = message;
        const toneClass = {
            error: 'text-red-700 bg-red-50 dark:bg-red-950/40 dark:text-red-300',
            success: 'text-green-700 bg-green-50 dark:bg-green-950/40 dark:text-green-300',
            warn: 'text-amber-800 bg-amber-50 dark:bg-amber-950/40 dark:text-amber-200',
        }[tone] ?? '';
        banner.className = 'px-4 py-2 text-sm ' + toneClass;
    }

    function renderError(message) {
        ensureRoot();
        if (! root.querySelector('h2')) {
            render({ title: '', submit_label: '', fields: [], reserved: RESERVED, max_fields: 8 });
        }
        showBanner(message, 'error');
    }

    function renderConflict() {
        showBanner('Someone else saved this form. Your edits are still here — save again to apply them on top.', 'warn');
    }

    function renderFieldErrors(errors) {
        const first = errors && typeof errors === 'object'
            ? Object.values(errors).flat()[0]
            : null;
        showBanner(first || 'Please fix the highlighted fields.', 'error');
    }

    return { open, close };
}
