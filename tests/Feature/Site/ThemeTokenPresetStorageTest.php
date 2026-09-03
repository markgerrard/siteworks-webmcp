<?php

use App\Models\ThemeTokenPreset;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;

it('creates theme_token_presets with the T50 storage columns', function () {
    expect(Schema::hasTable('theme_token_presets'))->toBeTrue()
        ->and(Schema::hasColumns('theme_token_presets', [
            'id',
            'name',
            'description',
            'tokens',
            'created_by_user_id',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
});

it('persists a named token map with an optional description and creator', function () {
    $user = User::factory()->create();

    $preset = ThemeTokenPreset::query()->create([
        'name' => 'cream-band',
        'description' => 'Warm cream band on inverted sites',
        'tokens' => [
            'color-band' => '#f7f2ea',
            'color-text-on-band' => '#1a1a1a',
        ],
        'created_by_user_id' => $user->id,
    ]);

    expect($preset->fresh())
        ->name->toBe('cream-band')
        ->description->toBe('Warm cream band on inverted sites')
        ->tokens->toBe([
            'color-band' => '#f7f2ea',
            'color-text-on-band' => '#1a1a1a',
        ])
        ->created_by_user_id->toBe($user->id);

    expect($preset->createdBy->is($user))->toBeTrue();
});

it('allows a null description', function () {
    $preset = ThemeTokenPreset::factory()->create(['description' => null]);

    expect($preset->fresh()->description)->toBeNull();
});

it('enforces a unique name', function () {
    ThemeTokenPreset::factory()->create(['name' => 'cream-band']);

    expect(fn () => ThemeTokenPreset::factory()->create(['name' => 'cream-band']))
        ->toThrow(UniqueConstraintViolationException::class);
});
