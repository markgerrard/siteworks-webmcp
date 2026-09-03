<?php

namespace App\Services\Demo;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Between-takes reset for the demo: restores the seeded SQLite file and the
 * public media tree from snapshot copies kept inside the container, then
 * clears caches. Mirrors reset-demo-fast.sh on the host so the same reset can
 * be triggered from a URL during filming. Demo mode only.
 */
class DemoSnapshot
{
    public function demoDir(): string
    {
        return storage_path('demo');
    }

    public function mediaDir(): string
    {
        return storage_path('app/public');
    }

    public function hasSnapshot(): bool
    {
        return is_file($this->demoDir().'/demo.seed.sqlite') && is_file($this->demoDir().'/media.seed.tar');
    }

    /** Take the snapshot from the CURRENT state. Run only on a clean seed. */
    public function snapshot(): void
    {
        $d = $this->demoDir();
        DB::disconnect();
        @unlink("$d/demo.seed.sqlite");
        if (! copy("$d/demo.sqlite", "$d/demo.seed.sqlite")) {
            throw new RuntimeException('could not copy demo.sqlite');
        }
        $this->run(['tar', '-C', $this->mediaDir(), '-cf', "$d/media.seed.tar", '.']);
    }

    /** @return array<string, mixed> */
    public function reset(): array
    {
        if (! $this->hasSnapshot()) {
            throw new RuntimeException('NO-SNAPSHOT');
        }
        $start = hrtime(true);
        $d = $this->demoDir();
        $m = $this->mediaDir();
        DB::disconnect();
        foreach (['demo.sqlite-wal', 'demo.sqlite-shm'] as $f) {
            @unlink("$d/$f");
        }
        if (! copy("$d/demo.seed.sqlite", "$d/demo.sqlite")) {
            throw new RuntimeException('could not restore demo.sqlite');
        }
        $this->run(['sh', '-c', 'rm -rf '.escapeshellarg($m).'/* && tar -C '.escapeshellarg($m).' -xf '.escapeshellarg("$d/media.seed.tar")]);
        $private = storage_path('app/private');
        if (is_dir($private)) {
            $this->run(['sh', '-c', 'rm -rf '.escapeshellarg($private).'/*']);
        }
        Artisan::call('optimize:clear');
        DB::reconnect();

        return ['ms' => (int) ((hrtime(true) - $start) / 1_000_000)] + $this->state();
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        return [
            'site' => DB::table('sites')->where('id', 64)->value('business_name'),
            'cats' => DB::table('shop_categories')->pluck('name')->values()->all(),
            'products' => DB::table('shop_products')->count(),
            'published' => DB::table('shop_products')->whereNotNull('published_at')->count(),
            'catalogue_revision' => DB::table('shop_drafts')->where('site_id', 64)->value('catalogue_revision'),
        ];
    }

    /** @param list<string> $cmd */
    private function run(array $cmd): void
    {
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($p)) {
            throw new RuntimeException('could not start '.$cmd[0]);
        }
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($p) !== 0) {
            throw new RuntimeException(trim($err) ?: $cmd[0].' failed');
        }
    }
}
