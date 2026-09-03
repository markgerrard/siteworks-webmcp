<?php

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    Route::middleware(['web', 'require.acting_user'])
        ->any('/acting-user-required', fn () => response()->json(['ok' => true]));
});

it('forbids guests', function () {
    $this->postJson('/acting-user-required')
        ->assertForbidden();
});

it('allows a session-authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/acting-user-required')
        ->assertSuccessful()
        ->assertJson(['ok' => true]);
});
