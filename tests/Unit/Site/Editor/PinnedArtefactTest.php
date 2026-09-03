<?php

use App\Console\Commands\Editor\ExportSchemasCommand;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\PinnedArtefact;

it('names the repository artisan wrapper AND the directory it must run from, so the printed instruction is runnable', function () {
    // Both halves are load-bearing and both were learned the hard way. `php artisan`
    // exits 127 (php is not on PATH); `./bin/artisan` alone exits 1 with no diagnostic
    // when run from a git worktree, because a worktree has no `.env` and compose cannot
    // resolve DB_PASSWORD. A reader whose pin just failed is often sitting in a worktree,
    // so the location is part of the instruction, not decoration. And it must name WHICH
    // root: inside a worktree `git rev-parse --show-toplevel` returns the worktree root, so
    // "the repository root" alone reads as "retry in place" — where it fails.
    expect(ExportSchemasCommand::REGENERATE)
        ->toBe('from the MAIN CHECKOUT root (not a linked worktree): ./bin/artisan editor:schemas --json --out='.ExportSchemasCommand::ARTEFACT_RELATIVE_PATH)
        ->toContain('MAIN CHECKOUT root')
        ->toContain('not a linked worktree')
        ->toContain('./bin/artisan')
        ->not->toStartWith('php artisan');
});

it('returns silently when the shas match', function () {
    PinnedArtefact::assertMatches(
        'Editor schemas',
        'same-sha',
        'same-sha',
        'tests/Feature/Site/Editor/AgentApprovalFlagOffTest.php:110',
        ExportSchemasCommand::REGENERATE,
    );

    expect(true)->toBeTrue();
});

it('reports the actual sha when the shas differ', function () {
    expect(fn () => PinnedArtefact::assertMatches(
        'Editor schemas',
        'expected-sha',
        'actual-sha',
        'tests/Feature/Site/Editor/AgentApprovalFlagOffTest.php:110',
        ExportSchemasCommand::REGENERATE,
    ))->toThrow(AssertionFailedError::class, 'Actual sha256: actual-sha');
});

it('reports the regeneration command and standing rule when the shas differ', function () {
    expect(fn () => PinnedArtefact::assertMatches(
        'Editor schemas',
        'expected-sha',
        'actual-sha',
        'tests/Feature/Site/Editor/AgentApprovalFlagOffTest.php:110',
        ExportSchemasCommand::REGENERATE,
    ))->toThrow(
        AssertionFailedError::class,
        'Regenerate: '.ExportSchemasCommand::REGENERATE."\nUpdate the pin in the SAME commit as the change — never delete the assertion.",
    );
});

it('reports the file containing the pin when the shas differ', function () {
    expect(fn () => PinnedArtefact::assertMatches(
        'Editor schemas',
        'expected-sha',
        'actual-sha',
        'tests/Feature/Site/Editor/AgentApprovalFlagOffTest.php:110',
        ExportSchemasCommand::REGENERATE,
    ))->toThrow(
        AssertionFailedError::class,
        'Pin location: tests/Feature/Site/Editor/AgentApprovalFlagOffTest.php:110',
    );
});
