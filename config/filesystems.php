<?php

$demoMode = (bool) env('DEMO_MODE', false);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Media Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Dedicated disk for generated and uploaded media. Independent of
    | filesystems.default so a FILESYSTEM_DISK regression cannot silently
    | park new images on container-local disk.
    |
    | Everything on the media disk is addressed by its public URL (site media,
    | hero images, product photos), so in demo mode it is the "public" disk,
    | whose files are served statically from public/storage.
    |
    | Private media is the exception: customer-supplied files (personalisation
    | images) are only ever served through a signed, authorised route. On S3
    | that is enforced per object by ACL, so private media shares the media
    | disk; the local driver cannot keep a private object out of a statically
    | served tree, so in demo mode private media lives on the "local" disk,
    | whose /storage URLs refuse unsigned requests. Null means "same disk as
    | media".
    |
    */

    'media' => env('MEDIA_DISK', $demoMode ? 'public' : 's3'),

    'media_private' => env('MEDIA_PRIVATE_DISK', $demoMode ? 'local' : null),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            // Public-disk URLs default to APP_URL/storage. On hosts where APP_URL is the
            // (Access-gated) agents domain, public sites must get a RELATIVE /storage URL
            // instead, or every product image 302s into Cloudflare Access. The demo
            // serves the portal and the storefront from one app on two hosts, so there
            // the default is the relative form and each host serves its own media.
            'url' => env('FILESYSTEM_PUBLIC_URL', env('DEMO_MODE', false) ? '/storage' : rtrim(env('APP_URL', 'http://localhost'), '/').'/storage'),
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        'exports' => [
            'driver' => 'local',
            'root' => storage_path('app/exports'),
        ],

        's3' => $demoMode ? [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            // Same address form as the public disk: the demo's two hosts each serve
            // /storage themselves, so a logo or upload addressed here never names
            // the portal host from a storefront page.
            'url' => env('FILESYSTEM_PUBLIC_URL', '/storage'),
            'visibility' => 'public',
            'throw' => false,
        ] : [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
