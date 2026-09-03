<?php

use App\Enums\LogoSize;
use App\Models\BusinessProfile;
use App\Models\Site;
use App\Services\Site\HeaderPresentation;

/**
 * @return list<string>
 */
function emittedHeaderHeightClasses(): array
{
    $emitted = [];
    foreach ([LogoSize::Standard, LogoSize::Large, LogoSize::Compact] as $size) {
        $classes = HeaderPresentation::headerHeightClasses(new Site(['logo_size' => $size]));
        foreach (preg_split('/\s+/', $classes['unscrolled'].' '.$classes['scrolled']) as $token) {
            if ($token !== '') {
                $emitted[$token] = true;
            }
        }
    }

    $saas = new Site(['logo_size' => LogoSize::Standard]);
    $saas->setRelation('businessProfile', new BusinessProfile([
        'profile_data' => ['archetype' => 'saas_platform'],
    ]));
    foreach (preg_split('/\s+/', implode(' ', HeaderPresentation::headerHeightClasses($saas))) as $token) {
        if ($token !== '') {
            $emitted[$token] = true;
        }
    }

    return array_keys($emitted);
}

function productionSourceHaystack(): string
{
    $chunks = [(string) file_get_contents(resource_path('css/site.css'))];
    $roots = [base_path('app'), resource_path('views/site')];
    foreach ($roots as $root) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            if (! str_ends_with($path, '.php') && ! str_ends_with($path, '.css') && ! str_ends_with($path, '.blade.php')) {
                continue;
            }
            $chunks[] = (string) file_get_contents($path);
        }
    }

    return implode("\n", $chunks);
}

it('every headerHeightClasses token appears verbatim in production source', function () {
    $emitted = emittedHeaderHeightClasses();
    $haystack = productionSourceHaystack();

    expect($emitted)->not->toBeEmpty();
    foreach ($emitted as $class) {
        expect($haystack)->toContain($class);
    }
});

it('site.css pins every header-height utility in an inline source', function () {
    $css = (string) file_get_contents(resource_path('css/site.css'));
    $utilities = [
        'h-[8.75rem]',
        'h-[7.875rem]',
        'h-[7.5rem]',
        'h-[6.75rem]',
        'h-[9.375rem]',
        'h-[10.9375rem]',
        'h-[8.4375rem]',
        'h-[9.84375rem]',
        'h-[5rem]',
        'h-[5.75rem]',
        'h-[4.25rem]',
        'h-[4.75rem]',
    ];

    expect($css)->toContain('@source inline("');
    foreach ($utilities as $class) {
        expect($css)->toContain($class);
    }
});
