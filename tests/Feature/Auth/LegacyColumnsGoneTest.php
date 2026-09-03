<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('no longer has is_agent on users', function (): void {
    expect(Schema::hasColumn('users', 'is_agent'))->toBeFalse();
});

it('no longer has is_admin on users', function (): void {
    expect(Schema::hasColumn('users', 'is_admin'))->toBeFalse();
});

it('no longer has is_super_admin on users', function (): void {
    expect(Schema::hasColumn('users', 'is_super_admin'))->toBeFalse();
});

it('no longer has user_id on sites', function (): void {
    expect(Schema::hasColumn('sites', 'user_id'))->toBeFalse();
});

it('allows null password on users', function (): void {
    expect(Schema::getColumnType('users', 'password'))->not->toBeNull();
    // Verify the column is nullable by checking its definition accepts null.
    $columns = Schema::getColumns('users');
    $passwordColumn = collect($columns)->firstWhere('name', 'password');
    expect($passwordColumn['nullable'])->toBeTrue();
});
