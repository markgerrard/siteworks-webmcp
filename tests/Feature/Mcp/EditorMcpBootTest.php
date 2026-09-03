<?php

use App\Http\Middleware\RequireActingUser;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;
use Laravel\Mcp\Server\Middleware\ReorderJsonAccept;

it('promotes laravel mcp to a production dependency', function () {
    $composer = json_decode(file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['require']['laravel/mcp'] ?? null)->toBe('^0.6.5');
});

it('registers the editor MCP transport with RequireActingUser', function () {
    $route = Route::getRoutes()->getByName('mcp.editor');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('mcp/editor')
        ->and($route->getDomain())->toBeNull();

    $middleware = app('router')->gatherRouteMiddleware($route);

    expect($middleware)->toContain(ThrottleRequests::class.':mcp')
        ->and($middleware)->toContain(RequireActingUser::class)
        ->and($middleware)->toContain(ReorderJsonAccept::class)
        ->and($middleware)->toContain(AddWwwAuthenticateHeader::class);
});

it('keeps all MCP transport siblings on the editor URI', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (IlluminateRoute $route): bool => $route->uri() === 'mcp/editor');

    expect($routes)->toHaveCount(3)
        ->and($routes->every(fn (IlluminateRoute $route): bool => $route->getDomain() === null))->toBeTrue()
        ->and($routes->every(function (IlluminateRoute $route): bool {
            $resolved = app('router')->gatherRouteMiddleware($route);

            return in_array(RequireActingUser::class, $resolved, true);
        }))->toBeTrue()
        ->and(file_exists(base_path('routes/ai.php')))->toBeFalse()
        ->and($routes->contains(fn (IlluminateRoute $route): bool => in_array('POST', $route->methods(), true)))->toBeTrue()
        ->and($routes->contains(fn (IlluminateRoute $route): bool => in_array('GET', $route->methods(), true)))->toBeTrue()
        ->and($routes->contains(fn (IlluminateRoute $route): bool => in_array('DELETE', $route->methods(), true)))->toBeTrue();
});

it('registers the MCP rate limiter', function () {
    expect(RateLimiter::limiter('mcp'))->toBeCallable();
});

it('throttles MCP traffic before RequireActingUser', function () {
    $resolved = app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName('mcp.editor'));

    expect(array_search(ThrottleRequests::class.':mcp', $resolved, true))
        ->toBeLessThan(array_search(RequireActingUser::class, $resolved, true));
});
