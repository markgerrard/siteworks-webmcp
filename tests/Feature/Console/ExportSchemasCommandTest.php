<?php

use App\Console\Commands\Editor\ExportSchemasCommand;
use App\Services\Site\Editor\OperationRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

it('requires the json option', function () {
    $this->artisan('editor:schemas')
        ->expectsOutputToContain('The --json option is required.')
        ->assertExitCode(2);
});

it('writes valid JSON to --out independently of stdout so a shell redirect is not the artefact', function () {
    $path = sys_get_temp_dir().'/editor-schemas-'.uniqid('', true).'.json';

    $this->artisan('editor:schemas', ['--json' => true, '--out' => $path])
        ->assertSuccessful();

    expect(is_file($path))->toBeTrue();

    $contents = (string) file_get_contents($path);
    expect($contents)->toStartWith('{')
        ->and($contents)->not->toContain('[entrypoint]');

    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    $operations = array_keys(app(OperationRegistry::class)->all());

    expect($decoded)->toHaveKeys(['warnings_codes_version', 'operations'])
        ->and($decoded['operations'])->toHaveKeys($operations)
        // Oracle is the committed artefact, not stdout from this same command. An --out-only
        // writer that emitted valid JSON with every operation name but bogus schemas and a
        // bogus warnings_codes_version would pass the keys assertions and the stdout/file
        // self-comparison below.
        ->and(hash_file('sha256', $path))->toBe(hash_file('sha256', base_path(ExportSchemasCommand::ARTEFACT_RELATIVE_PATH)));

    File::delete($path);
});

it('still emits the JSON on stdout so existing pin comparisons keep working', function () {
    $path = sys_get_temp_dir().'/editor-schemas-stdout-'.uniqid('', true).'.json';

    Artisan::call('editor:schemas', ['--json' => true, '--out' => $path]);

    expect(trim(Artisan::output()))->toBe(trim((string) file_get_contents($path)));

    File::delete($path);
});
