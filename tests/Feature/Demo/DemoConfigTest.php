<?php

it('exposes demo config from DEMO_* env with the documented defaults', function () {
    $config = require config_path('demo.php');

    expect($config)->toMatchArray([
        'enabled' => (bool) env('DEMO_MODE', false),
        'site_host' => env('DEMO_SITE_HOST', 'localhost'),
        'user_email' => env('DEMO_USER_EMAIL', 'demo@camino.example'),
        'user_password' => env('DEMO_USER_PASSWORD', 'webmcp-demo'),
    ]);
});

it('maps the s3 disk onto the public local disk when DEMO_MODE is truthy', function () {
    $previousDemo = getenv('DEMO_MODE');
    $previousMedia = getenv('MEDIA_DISK');
    $previousPrivate = getenv('MEDIA_PRIVATE_DISK');
    $previousAppUrl = getenv('APP_URL');

    $_ENV['DEMO_MODE'] = $_SERVER['DEMO_MODE'] = '1';
    putenv('DEMO_MODE=1');
    unset($_ENV['MEDIA_DISK'], $_SERVER['MEDIA_DISK'], $_ENV['MEDIA_PRIVATE_DISK'], $_SERVER['MEDIA_PRIVATE_DISK']);
    putenv('MEDIA_DISK');
    putenv('MEDIA_PRIVATE_DISK');
    $_ENV['APP_URL'] = $_SERVER['APP_URL'] = 'http://app.localhost:8090';
    putenv('APP_URL=http://app.localhost:8090');

    $config = require config_path('filesystems.php');

    expect($config['media'])->toBe('public')
        ->and($config['disks']['s3'])->toBe([
            'driver' => 'local',
            'root' => storage_path('app/public'),
            // Root-relative, like the public disk: each demo host serves /storage itself.
            'url' => '/storage',
            'visibility' => 'public',
            'throw' => false,
        ]);

    restoreEnv('DEMO_MODE', $previousDemo);
    restoreEnv('MEDIA_DISK', $previousMedia);
    restoreEnv('MEDIA_PRIVATE_DISK', $previousPrivate);
    restoreEnv('APP_URL', $previousAppUrl);
});

it('serves demo media statically but keeps private media on a disk that only answers signed URLs', function () {
    $previousDemo = getenv('DEMO_MODE');
    $previousMedia = getenv('MEDIA_DISK');
    $previousPrivate = getenv('MEDIA_PRIVATE_DISK');

    $_ENV['DEMO_MODE'] = $_SERVER['DEMO_MODE'] = '1';
    putenv('DEMO_MODE=1');
    unset($_ENV['MEDIA_DISK'], $_SERVER['MEDIA_DISK'], $_ENV['MEDIA_PRIVATE_DISK'], $_SERVER['MEDIA_PRIVATE_DISK']);
    putenv('MEDIA_DISK');
    putenv('MEDIA_PRIVATE_DISK');

    $config = require config_path('filesystems.php');

    $media = $config['disks'][$config['media']];
    $private = $config['disks'][$config['media_private']];
    $staticRoots = array_values($config['links']);

    // Product photos and site media: a public-visibility disk whose root is
    // the storage:link target, so nginx serves them without touching PHP.
    expect($config['media'])->toBe('public')
        ->and($media['visibility'])->toBe('public')
        ->and($staticRoots)->toContain($media['root']);

    // Personalisation images: a disk outside every statically served tree,
    // with no public visibility, so /storage URLs for it require a signature.
    expect($config['media_private'])->toBe('local')
        ->and($private['driver'])->toBe('local')
        ->and($private['serve'])->toBeTrue()
        ->and($private['visibility'] ?? 'private')->toBe('private');
    foreach ($staticRoots as $root) {
        expect(str_starts_with($private['root'], rtrim($root, '/').'/'))->toBeFalse($private['root'])
            ->and($private['root'])->not->toBe($root);
    }

    restoreEnv('DEMO_MODE', $previousDemo);
    restoreEnv('MEDIA_DISK', $previousMedia);
    restoreEnv('MEDIA_PRIVATE_DISK', $previousPrivate);
});

it('keeps the non-demo s3 disk as s3 and private media on the media disk when DEMO_MODE is off', function () {
    $previousDemo = getenv('DEMO_MODE');
    $previousPrivate = getenv('MEDIA_PRIVATE_DISK');

    unset($_ENV['DEMO_MODE'], $_SERVER['DEMO_MODE'], $_ENV['MEDIA_PRIVATE_DISK'], $_SERVER['MEDIA_PRIVATE_DISK']);
    putenv('DEMO_MODE');
    putenv('MEDIA_PRIVATE_DISK');

    $config = require config_path('filesystems.php');

    expect($config['disks']['s3']['driver'])->toBe('s3')
        ->and($config['disks']['s3'])->toHaveKeys([
            'key', 'secret', 'region', 'bucket', 'url', 'endpoint', 'use_path_style_endpoint', 'throw', 'report',
        ])
        ->and($config['media_private'])->toBeNull();

    restoreEnv('DEMO_MODE', $previousDemo);
    restoreEnv('MEDIA_PRIVATE_DISK', $previousPrivate);
});

function restoreEnv(string $key, string|false $previous): void
{
    if ($previous === false) {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);

        return;
    }

    $_ENV[$key] = $_SERVER[$key] = $previous;
    putenv("{$key}={$previous}");
}
