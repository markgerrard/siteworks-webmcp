import { expect, test, vi } from 'vitest';
import { jobReferenceFromOperation, newPromptHint, pollEditorJob, jobFailureMessage } from '../image-picker.js';

test('pollEditorJob backs off from two seconds to a ten second cap', async () => {
    const statuses = ['queued', 'running', 'running', 'running', 'running', 'done'];
    const fetchImpl = vi.fn(async () => ({
        ok: true,
        json: async () => ({
            ok: true,
            data: { status: statuses.shift(), result: { media_id: 7, url: '/generated.webp' } },
        }),
    }));
    const delays = [];
    let elapsed = 0;

    const result = await pollEditorJob({
        jobRef: 'job-123',
        jobStatusUrl: '/sites/1/jobs/0',
        fetchImpl,
        wait: async (milliseconds) => {
            delays.push(milliseconds);
            elapsed += milliseconds;
        },
        now: () => elapsed,
    });

    expect(delays).toEqual([2000, 4000, 8000, 10000, 10000]);
    expect(fetchImpl).toHaveBeenCalledTimes(6);
    expect(fetchImpl).toHaveBeenCalledWith('/sites/1/jobs/job-123', expect.any(Object));
    expect(result.status).toBe('done');
});

test('pollEditorJob stops after the hard bound while the server job keeps running', async () => {
    const fetchImpl = vi.fn(async () => ({
        ok: true,
        json: async () => ({ ok: true, data: { status: 'running' } }),
    }));
    let elapsed = 0;

    const result = await pollEditorJob({
        jobRef: 'job-456',
        jobStatusUrl: '/sites/1/jobs/0',
        fetchImpl,
        maxDurationMs: 5000,
        wait: async (milliseconds) => {
            elapsed += milliseconds;
        },
        now: () => elapsed,
    });

    expect(result).toEqual({
        status: 'failed',
        error: 'Generation is still running server-side. Check Versions later.',
        stillRunningServerSide: true,
    });
    expect(elapsed).toBeLessThanOrEqual(5000);
});

test('pollEditorJob hard bound also covers a status request that never settles', async () => {
    const result = await pollEditorJob({
        jobRef: 'orphaned-chain',
        jobStatusUrl: '/sites/1/jobs/0',
        fetchImpl: () => new Promise(() => {}),
        maxDurationMs: 10,
    });

    expect(result.stillRunningServerSide).toBe(true);
    expect(result.error).toContain('still running server-side');
});

test('a null error job_ref means there is nothing to poll', async () => {
    const fetchImpl = vi.fn();
    const response = {
        ok: false,
        error: { code: 'job_running', message: 'Already running.', job_ref: null },
    };

    expect(jobReferenceFromOperation(response)).toBeNull();
    await expect(pollEditorJob({
        jobRef: jobReferenceFromOperation(response),
        jobStatusUrl: '/sites/1/jobs/0',
        fetchImpl,
    })).resolves.toEqual({ status: 'nothing_to_poll' });
    expect(fetchImpl).not.toHaveBeenCalled();
});

test('job_running exposes a non-null existing reference for polling', () => {
    expect(jobReferenceFromOperation({
        ok: false,
        error: { code: 'job_running', job_ref: 'existing-ref' },
    })).toBe('existing-ref');
});

test('Generate again gets a new prompt hint even inside the dedupe window', () => {
    const first = newPromptHint('A boiler engineer at work');
    const second = newPromptHint('A boiler engineer at work');

    expect(second).not.toBe(first);
});

test('polling stops as soon as the picker is dismissed', async () => {
    let calls = 0;
    let cancelled = false;
    const result = await pollEditorJob({
        jobRef: 'ref-1',
        jobStatusUrl: '/sites/1/jobs/JOB',
        isCancelled: () => cancelled,
        fetchImpl: async () => {
            calls += 1;
            cancelled = true; // dismissed while the first request was in flight
            return { ok: true, json: async () => ({ ok: true, data: { status: 'queued' } }) };
        },
        wait: async () => {},
    });

    expect(result.status).toBe('cancelled');
    expect(calls).toBe(1);
});

test('the hard bound covers a stalled body read and aborts the request', async () => {
    let aborted = false;
    const result = await pollEditorJob({
        jobRef: 'ref-1',
        jobStatusUrl: '/sites/1/jobs/JOB',
        maxDurationMs: 30,
        fetchImpl: async (_url, opts) => {
            opts?.signal?.addEventListener('abort', () => { aborted = true; });
            return { ok: true, json: () => new Promise(() => {}) }; // body never settles
        },
        wait: async () => {},
    });

    expect(result.status).not.toBe('done');
    expect(aborted).toBe(true);
});

test('a dismissal during the request stops before acting on a done response', async () => {
    let cancelled = false;
    const result = await pollEditorJob({
        jobRef: 'ref-1',
        jobStatusUrl: '/sites/1/jobs/JOB',
        isCancelled: () => cancelled,
        fetchImpl: async () => {
            cancelled = true; // panel closed while this request was in flight
            return { ok: true, json: async () => ({ ok: true, data: { status: 'done', result: { url: 'https://x/y.jpg' } } }) };
        },
        wait: async () => {},
    });

    expect(result.status).toBe('cancelled');
});

test('failure messages: stale_revision points at Versions, a server error wins, everything else is generic', () => {
    expect(jobFailureMessage({ status: 'stale_revision' }))
        .toBe('The page changed while the image was generated. The image is available in Versions.');
    expect(jobFailureMessage({ status: 'failed', error: 'image spend cap exceeded' })).toBe('image spend cap exceeded');
    expect(jobFailureMessage({ status: 'failed' })).toBe('Image generation failed.');
});
