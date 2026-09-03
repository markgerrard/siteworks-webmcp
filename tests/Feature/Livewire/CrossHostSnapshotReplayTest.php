<?php

use App\Enums\AgentRole;
use App\Livewire\Hooks\BindSnapshotToHost;
use App\Models\Site;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Snapshots must be bound to the host that minted them: a snapshot minted on one
 * surface is rejected when replayed on another, so persistent middleware is never
 * skipped by replaying a snapshot across hosts.
 */
test('a real rendered snapshot records the host it was minted on', function () {
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $staff->id]);
    $host = (string) config('domains.agent_domain');

    $response = test()->actingAs($staff)
        ->withServerVariables(['HTTP_HOST' => $host])
        ->get('http://'.$host.'/sites/'.$site->id);

    $response->assertOk();

    expect(preg_match('/wire:snapshot="([^"]*)"/', (string) $response->getContent(), $matches))
        ->toBe(1, 'No Livewire component rendered, so this assertion would be vacuous.');

    $snapshot = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true);

    expect($snapshot['memo']['host'] ?? null)->toBe($host,
        'Without the host in the memo there is nothing to bind a snapshot to.');
});

test('hydrating a snapshot minted on another host is rejected', function () {
    $hook = new BindSnapshotToHost();

    request()->headers->set('HOST', 'app.example.test');

    try {
        $hook->hydrate(['host' => 'agents.example.test']);
        $this->fail('An agents snapshot must not hydrate on the customer host.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }
});

test('hydrating a snapshot on its own host is allowed', function () {
    $hook = new BindSnapshotToHost();

    request()->headers->set('HOST', 'agents.example.test');

    $hook->hydrate(['host' => 'agents.example.test']);

    expect(true)->toBeTrue();
});

test('a snapshot carrying no host memo is rejected rather than trusted', function () {
    $hook = new BindSnapshotToHost();

    request()->headers->set('HOST', 'agents.example.test');

    // Failing open on absence would invite stripping the field. It sits inside the
    // checksum, so a legitimate snapshot always carries one.
    expect(fn () => $hook->hydrate([]))->toThrow(HttpException::class);
});
