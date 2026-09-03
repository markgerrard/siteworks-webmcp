<?php

namespace App\Services\Site\Editor;

use App\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class EditorJobStatus
{
    private const TTL_HOURS = 24;

    /**
     * @param  array<string, mixed>  $meta
     */
    public function start(Site $site, string $kind, array $meta = []): string
    {
        $ref = (string) Str::ulid();

        $this->put($ref, [
            'site_id' => $site->id,
            'kind' => $kind,
            'status' => 'queued',
            'meta' => $meta,
            'result' => null,
            'error' => null,
        ]);

        return $ref;
    }

    public function running(string $ref): void
    {
        $this->mutate($ref, function (array $payload): array {
            if (in_array($payload['status'] ?? null, ['done', 'failed', 'stale_revision'], true)) {
                return $payload; // terminal states never demote (a retried job's running() must not strand pollers)
            }
            $payload['status'] = 'running';

            return $payload;
        });
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function done(string $ref, array $result, ?ResultReceipt $receipt = null): void
    {
        $this->mutate($ref, function (array $payload) use ($result, $receipt): array {
            $payload['status'] = 'done';
            $payload['result'] = $result;
            $payload['error'] = null;
            $payload['receipt'] = $receipt?->toArray();
            unset($payload['current_revision_id']);

            return $payload;
        });
    }

    /**
     * Fixed, agent-safe classification of a job failure. get_job_status returns a sanitised error
     * code rather than raw exception text, so provider HTTP error bodies, internal hostnames and stack
     * fragments never travel to a third-party model. The real message is logged server-side instead.
     */
    public static function failureCode(?\Throwable $exception): string
    {
        if ($exception === null) {
            return 'internal';
        }

        // A run-time authorisation denial is not breakage. An agent polling get_job_status has to be able
        // to tell "your access was revoked while this sat on the queue" from "something fell over".
        if ($exception instanceof QueuedJobDenied) {
            return 'forbidden';
        }

        if ($exception instanceof \Illuminate\Http\Client\RequestException
            || $exception instanceof \Illuminate\Http\Client\ConnectionException) {
            return 'provider_error';
        }

        return 'internal';
    }

    public function failed(string $ref, string $error, ?ResultReceipt $receipt = null): void
    {
        $this->mutate($ref, function (array $payload) use ($error, $receipt): array {
            $payload['status'] = 'failed';
            $payload['error'] = $error;
            $payload['result'] = null;
            $payload['receipt'] = $receipt?->toArray();
            unset($payload['current_revision_id']);

            return $payload;
        });
    }

    public function stale(string $ref, int $currentRevisionId, ?ResultReceipt $receipt = null): void
    {
        $this->mutate($ref, function (array $payload) use ($currentRevisionId, $receipt): array {
            $payload['status'] = 'stale_revision';
            $payload['current_revision_id'] = $currentRevisionId;
            $payload['receipt'] = $receipt?->toArray();

            return $payload;
        });
    }

    /**
     * @return array{site_id: int, kind: string, status: string, meta: array<string, mixed>, result: array<string, mixed>|null, error: string|null, receipt?: array<string, mixed>|null, current_revision_id?: int}|null
     */
    public function get(Site $site, string $ref): ?array
    {
        $payload = Cache::get($this->key($ref));

        if (! is_array($payload) || ($payload['site_id'] ?? null) !== $site->id) { // strict: the stored id is the int start() wrote
            return null;
        }

        return $payload;
    }

    /**
     * @param  callable(array{site_id: int, kind: string, status: string, meta: array<string, mixed>, result: array<string, mixed>|null, error: string|null, receipt?: array<string, mixed>|null, current_revision_id?: int}): array{site_id: int, kind: string, status: string, meta: array<string, mixed>, result: array<string, mixed>|null, error: string|null, receipt?: array<string, mixed>|null, current_revision_id?: int}  $update
     */
    private function mutate(string $ref, callable $update): void
    {
        $payload = Cache::get($this->key($ref));

        if (! is_array($payload)) {
            return;
        }

        $updated = $update($payload);
        $this->put($ref, $updated);

        // A terminal status releases the idempotency-key → ref mapping the generation operations keep
        // (editor:job:key:{sha1}), ownership-aware, so a finished run never answers later calls as job_running.
        if (in_array($updated['status'] ?? null, ['done', 'failed', 'stale_revision'], true)) {
            self::releaseMapping($updated['meta']['idempotency_key'] ?? null, $ref);
        }
    }

    public static function mappingKey(string $idempotencyKey): string
    {
        return 'editor:job:key:'.sha1($idempotencyKey);
    }

    public static function releaseMapping(?string $idempotencyKey, string $ref): void
    {
        if (! is_string($idempotencyKey) || $idempotencyKey === '') {
            return;
        }
        $key = self::mappingKey($idempotencyKey);
        if (Cache::get($key) === $ref) {
            Cache::forget($key);
            // We owned the window, so the dispatcher's NX claim was ours too: release it so a re-roll inside
            // the hour is not refused as "already running" for a run that has finished (same key derivation
            // as IdempotentDispatcher::key()).
            \Illuminate\Support\Facades\Redis::del('ai:idem:'.sha1($idempotencyKey));
        }
    }

    /**
     * @param  array{site_id: int, kind: string, status: string, meta: array<string, mixed>, result: array<string, mixed>|null, error: string|null, receipt?: array<string, mixed>|null, current_revision_id?: int}  $payload
     */
    private function put(string $ref, array $payload): void
    {
        Cache::put($this->key($ref), $payload, now()->addHours(self::TTL_HOURS));
    }

    private function key(string $ref): string
    {
        return "editor:job:{$ref}";
    }
}
