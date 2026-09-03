<?php

use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Models\Site;
use Illuminate\Support\Facades\File;
use Livewire\Compiler\Compiler;
use Livewire\Component;

/**
 * Panel r1/r2 invariant: public methods on Livewire components are
 * remotely callable actions whose return values are JSON-encoded into
 * effects.returns. A public method returning the Site model therefore
 * leaks the full sites row (agent_notes, cost caps, reviews_cache, …)
 * to anyone who can mount the component. These arch tests pin the
 * invariant so a future public helper can't reintroduce the leak.
 */

/**
 * @return list<string> class names in the method's declared return type
 */
function declaredReturnTypeNames(ReflectionMethod $method): array
{
    $type = $method->getReturnType();

    if ($type instanceof ReflectionNamedType) {
        return [$type->getName()];
    }

    if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
        return collect($type->getTypes())
            ->filter(fn ($inner) => $inner instanceof ReflectionNamedType)
            ->map(fn (ReflectionNamedType $inner) => $inner->getName())
            ->values()
            ->all();
    }

    return [];
}

it('the AuthorizesSiteAccess trait exposes no public methods', function () {
    $publicMethods = collect((new ReflectionClass(AuthorizesSiteAccess::class))
        ->getMethods(ReflectionMethod::IS_PUBLIC))
        ->map(fn (ReflectionMethod $method) => $method->getName())
        ->all();

    expect($publicMethods)->toBe([]);
});

it('no Livewire component has a public method returning the Site model', function () {
    /** @var Compiler $compiler */
    $compiler = app('livewire.compiler');

    // Single-file components: every blade file under resources/views/livewire
    // declaring an anonymous component class (partials without one are skipped).
    $componentClasses = collect(File::allFiles(resource_path('views/livewire')))
        ->filter(fn ($file) => str_ends_with($file->getFilename(), '.blade.php'))
        ->filter(fn ($file) => str_contains($file->getContents(), 'extends Component'))
        ->map(fn ($file) => $compiler->compile($file->getPathname()));

    // Class-based components under app/Livewire.
    $namedClasses = collect(File::allFiles(app_path('Livewire')))
        ->map(function ($file) {
            $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            return 'App\\Livewire\\'.$relative;
        })
        ->filter(fn (string $class) => class_exists($class) && is_subclass_of($class, Component::class));

    $classes = $componentClasses->merge($namedClasses);

    expect($classes->count())->toBeGreaterThan(4);

    $offenders = [];
    foreach ($classes as $class) {
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (in_array(Site::class, declaredReturnTypeNames($method), true)) {
                $offenders[] = $class.'::'.$method->getName();
            }
        }
    }

    expect($offenders)->toBe([]);
});
