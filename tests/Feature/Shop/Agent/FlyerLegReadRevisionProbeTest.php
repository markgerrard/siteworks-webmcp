<?php

use App\Models\Shop\Product;
use App\Models\Shop\ShopDraft;
use App\Models\Site;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use Database\Seeders\Shop\TaxClassSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommerceReads;

beforeEach(function () {
    CommerceReads::enableFlags();
    $this->seed(TaxClassSeeder::class);
});

it('returns a catalogue_revision that describes the listed rows under a concurrent create', function () {
    if (! function_exists('pcntl_fork')) {
        $this->fail('The coherent-read probe requires pcntl.');
    }

    [$actor, $site] = CommerceReads::shopSite();
    Product::factory()->for($site)->create(['name' => 'Before', 'slug' => 'before']);

    $siteId = $site->id;
    $actorId = $actor->id;

    DB::commit();
    DB::disconnect();

    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($sockets)->not->toBeFalse();
    [$parentSocket, $childSocket] = $sockets;
    $childPid = pcntl_fork();
    if ($childPid === 0) {
        fclose($parentSocket);
        try {
            stream_set_timeout($childSocket, 8);
            if (trim((string) fgets($childSocket)) !== 'go') {
                throw new RuntimeException('Concurrent create was not released.');
            }
            config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
            DB::setDefaultConnection('pgsql_b');
            DB::purge('pgsql_b');
            CommerceReads::enableFlags();
            $childActor = User::on('pgsql_b')->findOrFail($actorId);
            $childSite = Site::on('pgsql_b')->findOrFail($siteId);
            $result = app(EditorOperations::class)->run(
                new EditorContext($childActor, $childSite, ActorChannel::Ui),
                'draft_product',
                CommerceReads::draftProductInput(['name' => 'After']),
            );
            if (! $result->ok) {
                throw new RuntimeException('draft_product failed: '.json_encode($result->error));
            }
            fwrite($childSocket, "committed\n");
        } catch (Throwable $exception) {
            @fwrite($childSocket, 'error:'.$exception->getMessage()."\n");
        }
        fclose($childSocket);
        exit(0);
    }
    fclose($childSocket);

    $released = false;
    DB::listen(function (QueryExecuted $query) use (&$released, $parentSocket): void {
        if ($released) {
            return;
        }
        $sql = strtolower($query->sql);
        if (! str_contains($sql, 'shop_products') || ! str_contains($sql, 'order by') || str_contains($sql, 'for update')) {
            return;
        }

        $released = true;
        fwrite($parentSocket, "go\n");
        stream_set_timeout($parentSocket, 8);
        $ack = trim((string) fgets($parentSocket));
        if ($ack !== 'committed') {
            throw new RuntimeException('Concurrent create did not commit: '.$ack);
        }
    });

    $list = app(EditorOperations::class)->run(
        new EditorContext(User::query()->findOrFail($actorId), Site::query()->findOrFail($siteId), ActorChannel::Ui),
        'list_products',
        ['limit' => 50],
    );

    $deadline = microtime(true) + 8;
    do {
        $waited = pcntl_waitpid($childPid, $status, WNOHANG);
        if ($waited === $childPid) {
            break;
        }
        usleep(10_000);
    } while (microtime(true) < $deadline);

    if ($waited !== $childPid) {
        posix_kill($childPid, SIGKILL);
        pcntl_waitpid($childPid, $status);
        $this->fail('The concurrent create did not finish within 8 seconds.');
    }

    stream_set_blocking($parentSocket, false);
    $childOutput = (string) stream_get_contents($parentSocket);
    fclose($parentSocket);

    expect($list->ok)->toBeTrue()
        ->and($childOutput)->not->toContain('error:')
        ->and($released)->toBeTrue();

    $listedNames = collect($list->data['products'])->pluck('name')->values()->all();
    $persistedCount = Product::query()->where('site_id', $siteId)->count();
    $outcome = [
        'catalogue_revision' => $list->data['catalogue_revision'],
        'listed_count' => count($list->data['products']),
        'persisted_count' => $persistedCount,
        'listed_names' => $listedNames,
        'shop_drafts_revision' => (int) (ShopDraft::query()->where('site_id', $siteId)->value('catalogue_revision') ?? 0),
    ];

    expect($outcome['catalogue_revision'])->toBe($outcome['shop_drafts_revision'])
        ->and($outcome['listed_count'] === $outcome['persisted_count'] || $outcome['catalogue_revision'] === 0)->toBeTrue(
            'Revision '.$outcome['catalogue_revision'].' must describe the revision-'.$outcome['catalogue_revision'].' rows. Outcome: '.json_encode($outcome),
        );

    if ($outcome['catalogue_revision'] >= 1) {
        expect($outcome['listed_count'])->toBe($outcome['persisted_count'],
            'Revision 1 must describe the revision-1 rows. Outcome: '.json_encode($outcome),
        );
    }
});
