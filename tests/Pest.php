<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->in('Feature', 'Unit');

require_once __DIR__.'/Support/ShopModeHelpers.php';
require_once __DIR__.'/Support/DemoSite64.php';

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Spawn a child `php artisan` process with a different SURFACE env value
 * so tests can observe surface-gated route loading without re-bootstrapping
 * the Laravel app inside the current PHPUnit process. Returns stdout.
 *
 * Used by SurfaceGatingTest, PublicSurfaceIsolationTest, and any other
 * test that needs to inspect SURFACE-specific app boot state.
 */
function attachManagedContentEligibility(\App\Models\Site $site, array $subscriptionAttrs = []): \App\Models\Site
{
    if ($site->managedContentSubscription === null) {
        \App\Models\SiteSubscription::factory()->for($site)->create($subscriptionAttrs);
        $site->unsetRelation('managedContentSubscription');
    } elseif ($subscriptionAttrs !== []) {
        $site->managedContentSubscription->update($subscriptionAttrs);
    }

    if (! $site->currentVersion()->exists()) {
        $version = \App\Models\Site\SiteVersion::query()->create([
            'site_id' => $site->id,
            'version' => 1,
            'composition' => ['nav' => ['items' => []], 'footer' => ['columns' => []]],
            'page_revisions' => [],
            'published_at' => now(),
        ]);

        \App\Models\Site\SiteVersionCurrent::query()->create([
            'site_id' => $site->id,
            'version_id' => $version->id,
            'updated_at' => now(),
        ]);
    }

    return $site->fresh();
}

function artisanInSurface(string $surface, string $command): string
{
    $process = new \Symfony\Component\Process\Process(
        array_merge(['php', 'artisan'], explode(' ', $command)),
        base_path(),
        ['SURFACE' => $surface] + $_ENV,
    );
    $process->mustRun();

    return $process->getOutput();
}
