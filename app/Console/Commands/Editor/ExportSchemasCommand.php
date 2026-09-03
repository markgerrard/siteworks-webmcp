<?php

namespace App\Console\Commands\Editor;

use App\Services\Site\Editor\OperationSchemas;
use App\Services\Site\Editor\WarningCodes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class ExportSchemasCommand extends Command
{
    public const ARTEFACT_RELATIVE_PATH = 'resources/js/site-editor/webmcp/schemas.json';

    /**
     * Instruction the pin helper prints. Two things about it are load-bearing, and
     * both were learned by this guard printing an instruction that did not work:
     *
     *   - The command writes the file itself. Do NOT shell-redirect stdout: a
     *     `docker run` of the app image runs the entrypoint's Vite rebuild onto
     *     stdout, which would corrupt the artefact.
     *   - It must be run from the MAIN CHECKOUT root — NOT a linked worktree, and
     *     saying only "the repository root" is not enough: inside a worktree
     *     `git rev-parse --show-toplevel` returns the WORKTREE root, so that phrase
     *     reads as "retry where you are", which is exactly where it fails.
     *     `bin/artisan` drives the app container through docker compose, and a
     *     worktree has no `.env`, so compose cannot resolve `DB_PASSWORD` and the
     *     wrapper's `set -e` aborts with exit 1 and no diagnostic. A reader whose
     *     pin just failed is often sitting in a worktree, so naming which root is
     *     part of the instruction.
     */
    public const REGENERATE = 'from the MAIN CHECKOUT root (not a linked worktree): ./bin/artisan editor:schemas --json --out='.self::ARTEFACT_RELATIVE_PATH;

    protected $signature = 'editor:schemas
        {--json : Emit the operation schemas as JSON}
        {--out= : Write JSON to this path (project-relative unless absolute). Omit for stdout only.}';

    protected $description = 'Export editor operation schemas for WebMCP clients.';

    public function handle(OperationSchemas $schemas): int
    {
        if (! $this->option('json')) {
            $this->error('The --json option is required.');

            return self::INVALID;
        }

        $payload = json_encode(
            [
                'warnings_codes_version' => WarningCodes::version(),
                'operations' => $schemas->all(),
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        $out = (string) $this->option('out');
        if ($out !== '' && $out !== '-') {
            $path = str_starts_with($out, '/') ? $out : $this->laravel->basePath($out);
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $payload.PHP_EOL);
        }

        $this->line($payload);

        return self::SUCCESS;
    }
}
