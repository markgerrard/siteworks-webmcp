<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\User;

/**
 * Seed the Camino demo and return [site 64, demo portal user].
 *
 * @return array{0: Site, 1: User}
 */
function demoSite64(): array
{
    test()->artisan('demo:seed')->assertSuccessful();

    $site = Site::query()->findOrFail(64);
    $user = User::query()->where('email', (string) config('demo.user_email'))->firstOrFail();

    return [$site, $user];
}

function demoSite64HomePage(Site $site): GeneratedPage
{
    return $site->generatedPages()->where('page_type', 'home')->firstOrFail();
}
