<?php

/**
 * site-public and SURFACE=all must force /robots.txt into PHP so
 * public/robots.txt cannot shadow RobotsController via nginx try_files.
 * agents / customer / editor-preview keep the default conf (static file
 * wins) — they do not register site.robots. SURFACE=all does register
 * site.robots (routes/site-public.php is loaded for all).
 */
test('site-public nginx forces robots.txt into PHP; default conf does not', function () {
    $default = file_get_contents(base_path('docker/app/nginx.conf'));
    $sitePublic = file_get_contents(base_path('docker/app/nginx.site-public.conf'));

    expect($sitePublic)
        ->toContain('location = /robots.txt')
        ->toContain('rewrite ^ /index.php last');

    expect($default)->not->toContain('location = /robots.txt');
});

test('site-public nginx contains every non-comment line of the default conf', function () {
    $sitePublic = file_get_contents(base_path('docker/app/nginx.site-public.conf'));

    $defaultLines = collect(file(base_path('docker/app/nginx.conf'), FILE_IGNORE_NEW_LINES) ?: [])
        ->map(fn (string $line): string => trim($line))
        ->reject(fn (string $line): bool => $line === '' || str_starts_with($line, '#'));

    expect($defaultLines)->not->toBeEmpty();

    foreach ($defaultLines as $line) {
        expect($sitePublic)->toContain($line);
    }
});

test('entrypoint installs site-public nginx when SURFACE is site-public or all', function () {
    $entrypoint = file_get_contents(base_path('docker/app/entrypoint.sh'));

    expect($entrypoint)
        ->toContain('[ "$SURFACE" = "site-public" ] || [ "$SURFACE" = "all" ]')
        ->toContain('nginx.site-public.conf')
        ->toContain('nginx.conf')
        ->toContain('/etc/nginx/sites-available/default');
});

test('entrypoint copies the selected nginx source onto sites-available/default', function () {
    $lines = collect(explode("\n", (string) file_get_contents(base_path('docker/app/entrypoint.sh'))))
        ->map(fn (string $line): string => trim($line))
        ->reject(fn (string $line): bool => $line === '' || str_starts_with($line, '#'));

    expect($lines)->toContain('cp "$NGINX_SRC" /etc/nginx/sites-available/default');
});

test('entrypoint validates nginx config and falls back to the image-baked copy', function () {
    $entrypoint = file_get_contents(base_path('docker/app/entrypoint.sh'));

    expect($entrypoint)
        ->toContain('nginx -t')
        ->toContain('falling back')
        ->toContain('/etc/nginx/sites-available/nginx.default')
        ->toContain('/etc/nginx/sites-available/nginx.site-public');
});

test('Dockerfile bakes both nginx templates for entrypoint selection', function () {
    $dockerfile = file_get_contents(base_path('docker/app/Dockerfile'));

    expect($dockerfile)
        ->toContain('nginx.default')
        ->toContain('nginx.site-public')
        ->toContain('docker/app/nginx.conf')
        ->toContain('docker/app/nginx.site-public.conf');
});
