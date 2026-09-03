import { saveField } from './field-save.js';
import { mountVersionsDrawer } from './versions-drawer.js';

const TERMINAL_JOB_STATUSES = new Set(['done', 'failed', 'stale_revision']);
const JOB_POLL_DELAYS = [2000, 4000, 8000, 10000];
const JOB_POLL_TIMEOUT_MS = 5 * 60 * 1000;
const STILL_RUNNING_MESSAGE = 'Generation is still running server-side. Check Versions later.';
let generationSequence = 0;

export function activateImagePicker(el, config) {
    mountImagePicker.open({
        config,
        fieldKey: el.dataset.editable,
        uploadUrl: portraitUploadUrl(el.dataset.editable, config),
        onPick: async (mediaUrl, mediaId) => {
            const value = isPortraitIdField(el.dataset.editable) ? mediaId : mediaUrl;
            await saveField(el, value, config);
        },
    });
}

export const mountImagePicker = {
    open({ config, coordinator = null, fieldKey = null, onPick, uploadUrl = config.mediaUploadUrl }) {
        openImagePicker({ config, coordinator, fieldKey, onPick, uploadUrl });
    },
};

function openImagePicker({ config, coordinator, fieldKey, onPick, uploadUrl }) {
    document.getElementById('site-editor-image-picker')?.remove();

    const context = imageFieldContext(fieldKey, config, coordinator);
    const panel = document.createElement('section');
    panel.id = 'site-editor-image-picker';
    panel.className = 'fixed inset-y-0 right-0 z-[10000] flex w-full max-w-md flex-col gap-4 overflow-y-auto border-l border-zinc-200 bg-white p-5 shadow-2xl dark:border-zinc-700 dark:bg-zinc-900';

    const heading = document.createElement('div');
    heading.className = 'flex items-center justify-between gap-3';
    const title = document.createElement('h2');
    title.className = 'text-lg font-semibold text-zinc-900 dark:text-white';
    title.textContent = 'Choose an image';
    const close = button('Close', 'border border-zinc-300 bg-white text-zinc-700 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100');
    close.addEventListener('click', () => { panel.dataset.cancelled = '1'; panel.remove(); });
    heading.append(title, close);
    panel.appendChild(heading);

    const actions = document.createElement('div');
    actions.className = 'grid gap-2 sm:grid-cols-2';
    const upload = button('Upload image', 'bg-blue-600 text-white hover:bg-blue-500');
    upload.addEventListener('click', () => chooseUpload({ config, coordinator, context, onPick, uploadUrl, panel }));
    actions.appendChild(upload);

    if (context && config.generateImageUrl && config.jobStatusUrl) {
        const generate = button('Generate', 'bg-violet-600 text-white hover:bg-violet-500');
        generate.dataset.imageGenerate = '';
        generate.addEventListener('click', () => generateImage({ config, coordinator, context, onPick, panel }));
        actions.appendChild(generate);
    }

    if (context && config.imageVersionsUrl && config.restoreMediaVersionUrl) {
        const versions = button('Versions', 'border border-zinc-300 bg-white text-zinc-700 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100');
        versions.addEventListener('click', () => {
            mountVersionsDrawer({ config, coordinator }).open({
                scope: 'media',
                ...context,
                onRestore: (url, id) => {
                    onPick(url, id);
                    panel.remove();
                },
            });
        });
        actions.appendChild(versions);
    }

    panel.appendChild(actions);
    document.body.appendChild(panel);
}

function chooseUpload({ config, coordinator, context, onPick, uploadUrl, panel }) {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';

    input.addEventListener('change', async () => {
        if (! input.files[0]) {
            return;
        }

        const formData = new FormData();
        formData.append('file', input.files[0]);
        appendUploadContext(formData, context, coordinator, config);

        const uploadResponse = await fetch(uploadUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': config.csrfToken || '',
                'X-Edit-Csrf': config.editCsrf || '',
            },
            body: formData,
        });

        const response = await uploadResponse.json().catch(() => ({}));
        if (! uploadResponse.ok) {
            alert(response.message || response.error?.message || 'Upload failed');
            return;
        }

        const media = response.ok ? response.data : response;
        updateCoordinatorState(coordinator, response.state);
        onPick(media.url, media.media_id ?? media.id ?? null);
        panel.remove();
    });

    input.click();
}

async function generateImage({ config, coordinator, context, onPick, panel }) {
    const prompt = window.prompt('What should this image show?', 'A suitable image for this section');
    if (prompt === null) {
        return;
    }

    const buttonElement = panel.querySelector('[data-image-generate]');
    if (buttonElement) {
        buttonElement.disabled = true;
        buttonElement.textContent = 'Generating…';
    }

    const operation = await runOperation({
        coordinator,
        pageId: context.pageId,
        // Refresh INSIDE the dispatched closure: runExternal queues on the coordinator's chain, so an
        // operation queued ahead of us can advance the revision between queueing and dispatch.
        operation: () => (refreshContextFromState(context, coordinator), postJson(config, config.generateImageUrl, {
            page_id: context.pageId,
            stored_index: context.storedIndex,
            field_path: context.fieldPath,
            prompt_hint: newPromptHint(prompt),
            assign: true,
            composition_revision: compositionRevision(coordinator, config),
            revision_base: context.revisionBase,
            structure_epoch: context.structureEpoch,
        })),
    });

    updateCoordinatorState(coordinator, operation.state);
    if (! operation.ok) {
        const code = operation.error?.code;
        refreshContextFromState(context, coordinator);
        if (code === 'quota_exceeded') {
            alert(operation.error.message || 'Image generation is not available in this demo.');
            restoreGenerateButton(buttonElement, 'Generate');
            return;
        }
        if (code === 'job_running') {
            alert('An image generation job is already running.');
        } else {
            alert(operation.error?.message || 'Image generation failed.');
            restoreGenerateButton(buttonElement, 'Generate');
            return;
        }
    }

    // The response's state (incl. a 409's current_revision_id) has been fed to the coordinator above;
    // pull it back into the context so a retry uses it.
    refreshContextFromState(context, coordinator);

    const job = await pollEditorJob({
        isCancelled: () => panel.dataset.cancelled === '1' || ! panel.isConnected,
        jobRef: jobReferenceFromOperation(operation),
        jobStatusUrl: config.jobStatusUrl,
        csrfToken: config.csrfToken,
    });

    if (job.status === 'nothing_to_poll' || job.status === 'cancelled') {
        // Dismissed, or no ref to poll: say nothing. With assign:true the server may well have finished.
        restoreGenerateButton(buttonElement, 'Generate again');
        return;
    }
    if (job.status !== 'done') {
        alert(jobFailureMessage(job));
        restoreGenerateButton(buttonElement, 'Generate again');
        return;
    }

    const dismissed = panel.dataset.cancelled === '1' || ! panel.isConnected;
    const media = job.result || {};
    if (media.url && ! dismissed) {
        onPick(media.url, media.media_id ?? media.id ?? null);
        restoreGenerateButton(buttonElement, 'Generate again');
        return;
    }

    restoreGenerateButton(buttonElement, 'Generate again');
}

export async function pollEditorJob({
    jobRef,
    jobStatusUrl,
    csrfToken = '',
    fetchImpl = fetch,
    wait = delay,
    now = Date.now,
    maxDurationMs = JOB_POLL_TIMEOUT_MS,
    isCancelled = null,
}) {
    if (jobRef === null || jobRef === undefined || jobRef === '') {
        return { status: 'nothing_to_poll' };
    }

    const startedAt = now();
    let delayIndex = 0;

    while (true) {
        if (isCancelled?.()) {
            // The picker was dismissed: stop polling and never call back into a closed panel.
            return { status: 'cancelled' };
        }

        const remainingDuration = maxDurationMs - (now() - startedAt);
        if (remainingDuration <= 0) {
            return stillRunningResult();
        }

        // The bound covers the WHOLE round trip (request AND body read) and aborts the in-flight request —
        // a stalled body must not outlive it.
        const controller = typeof AbortController === 'function' ? new AbortController() : null;
        const read = (async () => {
            const res = await fetchImpl(jobStatusUrlFor(jobStatusUrl, jobRef), {
                credentials: 'same-origin',
                signal: controller?.signal,
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
            });

            return { res, body: await res.json().catch(() => ({})) };
        })().catch(() => null);

        const settled = await fetchWithHardTimeout(read, remainingDuration, () => controller?.abort());
        if (settled === null) {
            return stillRunningResult();
        }
        if (isCancelled?.()) {
            return { status: 'cancelled' }; // dismissed while this request was in flight
        }

        const response = settled.res;
        const envelope = settled.body;

        if (! response.ok || ! envelope.ok) {
            return {
                status: 'failed',
                error: envelope.error?.message || 'Could not check image generation status.',
            };
        }

        const job = envelope.data || {};
        if (TERMINAL_JOB_STATUSES.has(job.status)) {
            return job;
        }

        const nextDelay = JOB_POLL_DELAYS[Math.min(delayIndex, JOB_POLL_DELAYS.length - 1)];
        const elapsed = now() - startedAt;
        if (elapsed >= maxDurationMs || elapsed + nextDelay > maxDurationMs) {
            return stillRunningResult();
        }

        await wait(nextDelay);
        delayIndex += 1;
    }
}

export function jobReferenceFromOperation(operation) {
    const jobRef = operation?.ok ? operation.data?.job_ref : operation?.error?.job_ref;

    return typeof jobRef === 'string' && jobRef !== '' ? jobRef : null;
}

function imageFieldContext(fieldKey, config, coordinator) {
    const match = String(fieldKey ?? '').match(/^page\.(\d+)\.section\.(\d+)\.(.+)$/);
    if (! match) {
        return null;
    }

    const pageId = Number(match[1]);

    return {
        pageId,
        storedIndex: Number(match[2]),
        fieldPath: match[3],
        revisionBase: coordinator?.currentRevision?.(pageId)
            ?? config.currentRevisionIds?.[pageId]
            ?? config.currentRevisionId,
        structureEpoch: coordinator?.currentEpoch?.(pageId)
            ?? config.structureEpochs?.[pageId]
            ?? 0,
    };
}

function appendUploadContext(formData, context, coordinator, config) {
    formData.append('composition_revision', String(compositionRevision(coordinator, config)));
    if (! context) {
        return;
    }

    formData.append('page_id', String(context.pageId));
    formData.append('stored_index', String(context.storedIndex));
    formData.append('field_path', context.fieldPath);
    formData.append('revision_base', String(context.revisionBase));
    formData.append('structure_epoch', String(context.structureEpoch));
}

export function newPromptHint(prompt) {
    generationSequence += 1;

    return `${prompt.trim() || 'A suitable image for this section'} (variation ${Date.now()}-${generationSequence})`;
}

function compositionRevision(coordinator, config) {
    return coordinator?.compositionRevision?.() ?? config.compositionRevision ?? 0;
}

async function runOperation({ coordinator, pageId, operation }) {
    if (! coordinator?.runExternal) {
        return operation();
    }

    const coordinated = await coordinator.runExternal({ pageId, structural: false, fn: operation });

    return coordinated.result ?? coordinated;
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
        error: { code: 'internal', message: 'Image generation failed.' },
    }));
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

function jobStatusUrlFor(template, jobRef) {
    const encodedRef = encodeURIComponent(jobRef);

    return String(template ?? '').replace(/\/jobs\/[^/?]+(?=\?|$)/, `/jobs/${encodedRef}`);
}

function delay(milliseconds) {
    return new Promise((resolve) => setTimeout(resolve, milliseconds));
}

function fetchWithHardTimeout(fetchPromise, timeoutMs, onTimeout = null) {
    let timer;
    const timeout = new Promise((resolve) => {
        timer = setTimeout(() => {
            onTimeout?.();
            resolve(null);
        }, timeoutMs);
    });

    return Promise.race([fetchPromise, timeout]).finally(() => clearTimeout(timer));
}

function stillRunningResult() {
    return {
        status: 'failed',
        error: STILL_RUNNING_MESSAGE,
        stillRunningServerSide: true,
    };
}

function button(label, classes) {
    const element = document.createElement('button');
    element.type = 'button';
    element.className = `inline-flex items-center justify-center rounded-md px-3 py-2 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-50 ${classes}`;
    element.textContent = label;

    return element;
}

function restoreGenerateButton(element, label) {
    if (! element) {
        return;
    }
    element.disabled = false;
    element.textContent = label;
}

export function isPortraitIdField(fieldKey) {
    return /\.members\.\d+\.(image_id|alternate_image_id|hover_image_id)$/.test(String(fieldKey ?? ''));
}

export function portraitUploadUrl(fieldKey, config) {
    if (! isPortraitIdField(fieldKey)) {
        return config.mediaUploadUrl;
    }

    // Portraits must NEVER degrade to the raw uploader
    // (original bytes, EXIF kept). Refuse instead of falling open.
    if (! config.portraitUploadUrl) {
        throw new Error('portraitUploadUrl missing from editor config: portrait upload refused');
    }

    return config.portraitUploadUrl;
}

function refreshContextFromState(context, coordinator) {
    if (! coordinator || ! context || context.pageId === null || context.pageId === undefined) {
        return;
    }
    const base = coordinator.currentRevision?.(context.pageId);
    const epoch = coordinator.currentEpoch?.(context.pageId);
    if (Number.isInteger(base)) {
        context.revisionBase = base;
    }
    if (Number.isInteger(epoch)) {
        context.structureEpoch = epoch;
    }
}

export function jobFailureMessage(job) {
    if (job?.error) {
        return job.error;
    }
    if (job?.status === 'stale_revision') {
        return 'The page changed while the image was generated. The image is available in Versions.';
    }

    return 'Image generation failed.';
}
