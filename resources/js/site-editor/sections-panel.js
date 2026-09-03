const buttonClass =
    'inline-flex items-center justify-center rounded-md border px-2.5 py-1.5 text-sm font-medium transition ' +
    'focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 disabled:cursor-not-allowed disabled:opacity-40';

export function mountSectionsPanel({ bridge, config }) {
    let pageId = Number(config.pageId);
    let structure = null;
    let busy = false;

    const toggle = document.createElement('button');
    toggle.id = 'btn-sections';
    toggle.type = 'button';
    toggle.className = buttonClass + ' border-zinc-300 bg-white text-zinc-700 hover:bg-zinc-100 ' +
        'dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:hover:bg-zinc-700';
    toggle.textContent = 'Sections';
    document.querySelector('#site-editor-toolbar > div:last-child')?.prepend(toggle);

    const panel = document.createElement('aside');
    panel.id = 'site-editor-sections-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Page sections');
    panel.setAttribute('aria-hidden', 'true');
    panel.className =
        'fixed inset-y-0 right-0 z-[10002] flex w-[24rem] max-w-full translate-x-full flex-col ' +
        'border-l border-zinc-200 bg-zinc-50 shadow-xl transition-transform duration-200 ease-out ' +
        'dark:border-zinc-700 dark:bg-zinc-900';
    document.body.appendChild(panel);

    toggle.addEventListener('click', async () => {
        if (panel.getAttribute('aria-hidden') === 'false') {
            close();
            return;
        }

        open();
        await load();
    });

    function open() {
        panel.classList.remove('translate-x-full');
        panel.setAttribute('aria-hidden', 'false');
        renderLoading();
    }

    function close() {
        panel.classList.add('translate-x-full');
        panel.setAttribute('aria-hidden', 'true');
    }

    async function setPage(nextPageId) {
        const parsedPageId = Number(nextPageId);
        if (! Number.isInteger(parsedPageId) || parsedPageId < 1 || parsedPageId === pageId) {
            return;
        }

        pageId = parsedPageId;
        structure = null;

        if (panel.getAttribute('aria-hidden') === 'false') {
            await load();
        }
    }

    async function load() {
        renderLoading();

        try {
            const response = await fetch(urlFor(config.structureUrl, pageId, 'structure'), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const result = await response.json();

            if (! response.ok || ! result.ok) {
                renderError(result.error?.message || 'Could not load page sections.');
                return;
            }

            structure = result.data;
            updateSeeds(result);
            render();
        } catch {
            renderError('Could not load page sections.');
        }
    }

    async function write(body) {
        if (busy || ! structure) {
            return;
        }

        busy = true;
        render();

        try {
            // TODO(T25): route through coordinator.runExternal
            const result = await postJson(
                config,
                urlFor(config.sectionsUrl, pageId, 'sections'),
                body,
                {
                    revisionBase: config.currentRevisionIds?.[pageId],
                    structureEpoch: config.structureEpochs?.[pageId],
                },
            );
            updateSeeds(result);

            if (! result.ok) {
                renderError(result.error?.message || 'Could not update page sections.', true);
                return;
            }

            bridge.sendToIframe('reload-preview', {});

            await load();
        } catch {
            renderError('Could not update page sections. Your page was not changed.', true);
        } finally {
            busy = false;
            if (structure && panel.querySelector('[data-sections-list]')) {
                render();
            }
        }
    }

    function updateSeeds(result) {
        config.currentRevisionIds ||= {};
        config.structureEpochs ||= {};

        const revisionId = result.state?.draft_revision_id ?? result.data?.draft_revision_id;
        const structureEpoch = result.state?.structure_epoch ?? result.data?.structure_epoch;

        if (revisionId != null) {
            config.currentRevisionIds[pageId] = revisionId;
        }
        if (structureEpoch != null) {
            config.structureEpochs[pageId] = structureEpoch;
        }
    }

    function render() {
        panel.replaceChildren(header());

        const content = document.createElement('div');
        content.className = 'flex flex-1 flex-col gap-4 overflow-y-auto p-4';
        content.appendChild(addControls());

        const list = document.createElement('ol');
        list.dataset.sectionsList = '';
        list.className = 'flex flex-col gap-2';
        const storedIndexes = structure.sections
            .map((section) => section.stored_index)
            .filter((index) => Number.isInteger(index));
        const lastStoredIndex = storedIndexes.length > 0 ? Math.max(...storedIndexes) : -1;

        structure.sections.forEach((section) => {
            list.appendChild(sectionRow(section, lastStoredIndex));
        });

        if (structure.sections.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'text-sm text-zinc-500 dark:text-zinc-400';
            empty.textContent = 'This page has no sections yet.';
            list.appendChild(empty);
        }

        content.appendChild(list);
        panel.appendChild(content);
    }

    function header() {
        const wrapper = document.createElement('div');
        wrapper.className = 'flex items-center justify-between gap-3 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700';

        const heading = document.createElement('h2');
        heading.className = 'text-base font-semibold text-zinc-900 dark:text-white';
        heading.textContent = 'Sections';

        const closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'rounded p-1 text-zinc-500 hover:bg-zinc-200 hover:text-zinc-900 ' +
            'dark:hover:bg-zinc-800 dark:hover:text-white';
        closeButton.setAttribute('aria-label', 'Close sections panel');
        closeButton.textContent = '×';
        closeButton.addEventListener('click', close);

        wrapper.append(heading, closeButton);
        return wrapper;
    }

    function addControls() {
        const wrapper = document.createElement('div');
        wrapper.className = 'flex items-end gap-2';

        const label = document.createElement('label');
        label.className = 'flex flex-1 flex-col gap-1 text-xs font-medium text-zinc-600 dark:text-zinc-300';
        label.appendChild(document.createTextNode('Add section'));

        const select = document.createElement('select');
        select.className = 'rounded-md border border-zinc-300 bg-white px-2.5 py-2 text-sm text-zinc-900 ' +
            'dark:border-zinc-600 dark:bg-zinc-800 dark:text-white';
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Choose a type';
        select.appendChild(placeholder);

        availableTypes().forEach(([type, definition]) => {
            const option = document.createElement('option');
            option.value = type;
            option.textContent = definition.label;
            select.appendChild(option);
        });
        label.appendChild(select);

        const addButton = document.createElement('button');
        addButton.type = 'button';
        addButton.disabled = busy;
        addButton.className = buttonClass + ' border-blue-600 bg-blue-600 text-white hover:bg-blue-500';
        addButton.textContent = 'Add';
        addButton.addEventListener('click', () => {
            if (select.value !== '') {
                write({ op: 'add', type: select.value, position: addPosition() });
            }
        });

        wrapper.append(label, addButton);
        return wrapper;
    }

    function availableTypes() {
        const presentTypes = new Set(structure.sections.map((section) => section.type));

        return Object.entries(config.sectionCatalog || {}).filter(([type, definition]) => {
            const pageTypes = Array.isArray(definition.page_types) ? definition.page_types : [];
            const allowed = pageTypes.includes('*') || pageTypes.includes(structure.page_type);

            return allowed && (! definition.singleton || ! presentTypes.has(type));
        });
    }

    function addPosition() {
        const indexes = structure.sections
            .map((section) => section.stored_index)
            .filter((index) => Number.isInteger(index));

        return indexes.length > 0 ? Math.max(...indexes) + 1 : 0;
    }

    function sectionRow(section, lastStoredIndex) {
        const item = document.createElement('li');
        item.className = 'flex flex-col gap-2 rounded-lg border border-zinc-200 bg-white p-3 ' +
            'dark:border-zinc-700 dark:bg-zinc-800';

        const top = document.createElement('div');
        top.className = 'flex items-center justify-between gap-3';
        const name = document.createElement('span');
        name.className = 'min-w-0 truncate text-sm font-medium text-zinc-900 dark:text-white';
        name.textContent = config.sectionCatalog?.[section.type]?.label || humanize(section.type);
        top.appendChild(name);

        if (section.mutable && Number.isInteger(section.stored_index)) {
            const actions = document.createElement('div');
            actions.className = 'flex shrink-0 items-center gap-1';
            actions.append(
                actionButton('↑', 'Move section up', section.stored_index === 0, () => write({
                    op: 'move', from: section.stored_index, to: section.stored_index - 1,
                })),
                actionButton('↓', 'Move section down', section.stored_index === lastStoredIndex, () => write({
                    op: 'move', from: section.stored_index, to: section.stored_index + 1,
                })),
                actionButton('Remove', 'Remove section', false, () => {
                    if (confirm(`Remove the ${name.textContent} section?`)) {
                        write({ op: 'remove', stored_index: section.stored_index });
                    }
                }, true),
            );
            top.appendChild(actions);
        }

        item.appendChild(top);

        if (section.mutable && Array.isArray(section.variant_options) && section.variant_options.length > 0) {
            const variantLabel = document.createElement('label');
            variantLabel.className = 'flex flex-col gap-1 text-xs text-zinc-500 dark:text-zinc-400';
            variantLabel.appendChild(document.createTextNode('Variant'));
            const variant = document.createElement('select');
            variant.className = 'rounded-md border border-zinc-300 bg-white px-2 py-1.5 text-sm text-zinc-900 ' +
                'dark:border-zinc-600 dark:bg-zinc-900 dark:text-white';
            variant.disabled = busy;

            const reset = document.createElement('option');
            reset.value = '';
            reset.textContent = 'Default';
            variant.appendChild(reset);
            section.variant_options.forEach((optionValue) => {
                const option = document.createElement('option');
                option.value = optionValue;
                option.textContent = humanize(optionValue);
                variant.appendChild(option);
            });
            variant.value = section.variant ?? '';
            variant.addEventListener('change', () => write({
                op: 'set_variant',
                stored_index: section.stored_index,
                variant: variant.value === '' ? null : variant.value,
            }));
            variantLabel.appendChild(variant);
            item.appendChild(variantLabel);
        }

        if (! section.mutable) {
            const locked = document.createElement('span');
            locked.className = 'text-xs text-zinc-500 dark:text-zinc-400';
            locked.textContent = 'Managed automatically';
            item.appendChild(locked);
        }

        return item;
    }

    function actionButton(text, label, disabled, handler, destructive = false) {
        const button = document.createElement('button');
        button.type = 'button';
        button.disabled = busy || disabled;
        button.className = 'rounded px-2 py-1 text-xs font-medium disabled:opacity-30 ' +
            (destructive
                ? 'text-red-600 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-950/40'
                : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-700');
        button.textContent = text;
        button.setAttribute('aria-label', label);
        button.addEventListener('click', handler);
        return button;
    }

    function renderLoading() {
        panel.replaceChildren(header());
        const loading = document.createElement('p');
        loading.className = 'p-4 text-sm text-zinc-500 dark:text-zinc-400';
        loading.textContent = 'Loading sections…';
        panel.appendChild(loading);
    }

    function renderError(message, preserveStructure = false) {
        panel.replaceChildren(header());
        const error = document.createElement('div');
        error.setAttribute('role', 'alert');
        error.className = 'm-4 rounded-md bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-200';
        error.textContent = message;
        panel.appendChild(error);

        if (preserveStructure && structure) {
            const retry = document.createElement('button');
            retry.type = 'button';
            retry.className = buttonClass + ' mx-4 border-zinc-300 bg-white text-zinc-700 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white';
            retry.textContent = 'Reload sections';
            retry.addEventListener('click', load);
            panel.appendChild(retry);
        }
    }

    return { close, setPage };
}

async function postJson(config, url, body, { revisionBase, structureEpoch }) {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': config.csrfToken,
            'X-Page-Revision-Base': revisionBase ?? '',
            Accept: 'application/json',
        },
        body: JSON.stringify({ ...body, structure_epoch: structureEpoch }),
    });

    return response.json();
}

function urlFor(templateUrl, pageId, suffix) {
    return String(templateUrl ?? '').replace(
        new RegExp(`/pages/\\d+/${suffix}$`),
        `/pages/${pageId}/${suffix}`,
    );
}

function humanize(value) {
    return String(value ?? '')
        .replace(/[-_]+/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}
