<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('primary domain GET /login returns 200 with email/password inputs', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
    $response->assertSee('email', false);
    $response->assertSee('password', false);
});
