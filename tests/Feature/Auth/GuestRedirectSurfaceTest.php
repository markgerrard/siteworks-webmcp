<?php

use App\Models\User;

/** Guests on customer routes are redirected to customer login. */
function guestGetOnHost(string $host, string $path)
{
    return test()->withServerVariables(['HTTP_HOST' => $host])
        ->get('http://'.$host.$path);
}

test('a guest on the customer surface is sent to the customer login', function () {
    $host = (string) config('domains.customer_domain');

    $response = guestGetOnHost($host, '/portal');

    $response->assertRedirect();

    $target = (string) $response->headers->get('Location');

    expect($target)->toBe(route('login'),
        'A guest on the customer surface must be sent to the customer login. Sending them to '
        .'agent.login 500s on the customer container, where that route does not exist.')
        ->and($target)->not->toContain('/auth/redirect');
});

test('an authenticated client is not redirected at all', function () {
    $host = (string) config('domains.customer_domain');
    $client = User::factory()->create(['role' => null, 'client_id' => \App\Models\Client::factory()->create()->id]);

    $response = test()->actingAs($client)
        ->withServerVariables(['HTTP_HOST' => $host])
        ->get('http://'.$host.'/portal');

    expect($response->status())->not->toBe(500);
});
