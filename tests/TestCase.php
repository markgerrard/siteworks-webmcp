<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;
    /** @var array<string, array<string, string>> */
    private static array $environmentByTestClass = [];

    /** @var array<string, string|false> */
    private array $originalTestEnvironment = [];

    /**
     * @param  array<string, string>  $environment
     */
    public static function usesEnvironment(string $testClassIdentifier, array $environment): void
    {
        self::$environmentByTestClass[$testClassIdentifier] = $environment;
    }

    protected function setUp(): void
    {
        $this->applyRegisteredEnvironment();

        // CRITICAL — we've been bitten by this. phpunit.xml <env force="true">
        // only updates $_ENV; it does NOT touch $_SERVER or getenv()/putenv().
        // Laravel's env() helper reads $_SERVER FIRST. If the container's
        // OS env can contain a non-test database name
        // at PHP startup, $_SERVER captured that — and even after phpunit
        // rewrites $_ENV to the test database, Laravel still sees the
        // live DB name, and RefreshDatabase::refreshTestDatabase() runs
        // `migrate:fresh` against it, dropping every table.
        //
        // Sync $_ENV → $_SERVER/putenv() before the app boots so there's
        // one source of truth.
        $this->syncPhpUnitEnvIntoServer();

        // Second line of defence: even after sync, inspect DB_DATABASE
        // directly and abort loud if it's not a *_test database. This
        // runs BEFORE parent::setUp(), before RefreshDatabase can run.
        $this->assertSafeDatabaseBeforeBoot();

        parent::setUp();

        $this->withoutVite();

        // Belt-and-braces: after the app booted, re-check from the
        // resolved config. If this ever fires it's a bug — the
        // pre-boot check should have caught it first — but having two
        // independent reads is cheap insurance.
        $default = (string) ($this->app['config']['database.default'] ?? '');
        if ($default === 'sqlite') {
            $sqliteDb = (string) ($this->app['config']['database.connections.sqlite.database'] ?? '');
            if ($sqliteDb !== ':memory:' && $sqliteDb !== '' && ! str_ends_with($sqliteDb, '.sqlite')) {
                throw new \RuntimeException(
                    "SAFETY (post-boot): sqlite database reports '{$sqliteDb}'; expected :memory: or a .sqlite file."
                );
            }
        } else {
            $db = $this->app['config']['database.connections.pgsql.database'] ?? '';
            if (preg_match('/_test(_\\d+)?$/', $db) !== 1) {
                throw new \RuntimeException(
                    "SAFETY (post-boot): connection reports '{$db}'; pre-boot check should have stopped this."
                );
            }
        }

        // Staff routes (dashboard, sites, clients, admin, settings) live on
        // the agent subdomain only. APP_URL is set in phpunit.xml to the
        // agent domain so the default test HTTP_HOST matches the staff
        // routes. Tests that target the primary domain (Fortify login,
        // future client-facing routes) use withServerVariables() to override.
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            foreach ($this->originalTestEnvironment as $key => $value) {
                if ($value === false) {
                    unset($_ENV[$key], $_SERVER[$key]);
                    putenv($key);

                    continue;
                }

                $_ENV[$key] = $_SERVER[$key] = $value;
                putenv("{$key}={$value}");
            }

            $this->originalTestEnvironment = [];
        }
    }

    private function applyRegisteredEnvironment(): void
    {
        foreach (self::$environmentByTestClass as $testClassIdentifier => $environment) {
            if (! str_contains(static::class, $testClassIdentifier)) {
                continue;
            }

            foreach ($environment as $key => $value) {
                $this->originalTestEnvironment[$key] = getenv($key);
                $_ENV[$key] = $_SERVER[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
    }

    /**
     * Propagate phpunit.xml <env force="true"> values from $_ENV into
     * $_SERVER and the process env. Laravel's env() reads $_SERVER
     * first, so without this the container's OS values (set by
     * docker-compose) silently beat the values phpunit.xml forces.
     *
     * We only copy keys that phpunit.xml set — identifiable because
     * they already exist in $_ENV (PHPUnit loaded them) — and only if
     * $_SERVER's current value differs or is absent.
     */
    private function syncPhpUnitEnvIntoServer(): void
    {
        foreach ($_ENV as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                continue;
            }
            if (($_SERVER[$key] ?? null) === $value) {
                continue;
            }
            $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    /**
     * Abort immediately if the test DB target looks like a live database.
     * Runs before parent::setUp() so RefreshDatabase can't wipe real data.
     */
    private function assertSafeDatabaseBeforeBoot(): void
    {
        $db = (string) (
            $_ENV['DB_DATABASE']
            ?? $_SERVER['DB_DATABASE']
            ?? getenv('DB_DATABASE')
            ?: ''
        );

        $connection = (string) (
            $_ENV['DB_CONNECTION']
            ?? $_SERVER['DB_CONNECTION']
            ?? getenv('DB_CONNECTION')
            ?: ''
        );

        if ($connection === 'sqlite') {
            if ($db === ':memory:' || $db === '' || str_ends_with($db, '.sqlite')) {
                return;
            }

            throw new \RuntimeException(
                "SAFETY (pre-boot): sqlite DB_DATABASE='{$db}' is not :memory: or a .sqlite file. Refusing to run."
            );
        }

        if ($db === '') {
            throw new \RuntimeException(
                'SAFETY: DB_DATABASE is not set. Use ./bin/test or set DB_DATABASE to a name ending in _test.'
            );
        }

        if (preg_match('/_test(_\\d+)?$/', $db) !== 1) {
            throw new \RuntimeException(
                "SAFETY (pre-boot): DB_DATABASE='{$db}' does NOT end in '_test' (or '_test_<n>' — Pest --parallel workers). "
                .'Refusing to run — RefreshDatabase would wipe it. '
                .'Use ./bin/test or set DB_DATABASE explicitly to a *_test database.'
            );
        }
    }

    /**
     * The public demo has no Postgres. Feature/Unit tests use the squashed
     * SQLite schema in database/migrations-demo rather than the historical
     * Postgres migration chain.
     *
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing()
    {
        return [
            '--drop-views' => false,
            '--drop-types' => false,
            '--seed' => false,
            '--path' => 'database/migrations-demo',
        ];
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
