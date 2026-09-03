<?php

use Illuminate\Support\Env;
use Illuminate\Support\Facades\Config;

it('defaults SURFACE to all when env is unset', function () {
    Config::set('surfaces.current', null);
    config()->offsetUnset('surfaces.current');
    Env::getRepository()->clear('SURFACE');

    $config = require base_path('config/surfaces.php');

    expect($config['current'])->toBe('all');
});

it('reads SURFACE env into config', function () {
    // NOT putenv(): Laravel memoises its Env repository, so a putenv() after
    // boot never reaches env() and this asserted 'all' forever.
    $_ENV['SURFACE'] = $_SERVER['SURFACE'] = 'site-public';
    Env::getRepository()->clear('SURFACE');
    Env::getRepository()->set('SURFACE', 'site-public');

    try {
        $config = require base_path('config/surfaces.php');

        expect($config['current'])->toBe('site-public');
    } finally {
        unset($_ENV['SURFACE'], $_SERVER['SURFACE']);
        Env::getRepository()->clear('SURFACE');
    }
});

it('exposes the valid surface values', function () {
    $config = require base_path('config/surfaces.php');

    expect($config['valid'])->toEqualCanonicalizing(['all', 'agents', 'customer', 'site-public', 'editor-preview']);
});

it('exposes config(domains.customer_domain) backed by APP_CUSTOMER_DOMAIN', function () {
    config()->set('domains.customer_domain', 'placeholder.test');

    expect(config('domains.customer_domain'))->toBe('placeholder.test');
});
