<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            \Laravel\Fortify\Contracts\LoginResponse::class,
            \App\Http\Responses\LoginResponse::class,
        );

        $this->app->singleton(
            \Laravel\Fortify\Contracts\LogoutResponse::class,
            \App\Http\Responses\LogoutResponse::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);

        Fortify::authenticateUsing(function (Request $request) {
            $username = $request->input(Fortify::username());
            $password = (string) $request->input('password');
            $user = User::where(Fortify::username(), $username)->first();

            // Equalise timing: always run Hash::check so a missing user does
            // not leak account existence via response time. SSO-created staff
            // rows get Hash::make(random) in the callback so Hash::check
            // against a user-supplied password can never succeed for them.
            //
            // Staff password login stays available for accounts that hold a real
            // password hash; Fortify's login rate limiter applies and timing is equalised.
            $placeholderHash = '$2y$12$'.str_repeat('a', 53);
            $passwordValid = Hash::check($password, $user?->password ?? $placeholderHash);

            $authed = ($user && $passwordValid) ? $user : null;

            // Audit trail for password auth. Captures whether the attempt
            // succeeded, the user's role (if any), the source IP (post-
            // TrustProxies resolution), and the forwarded chain. Operators
            // inspecting the auth channel can see staff-via-password vs
            // client-via-password splits + repeated failures per IP.
            Log::channel(config('logging.auth_channel', 'stack'))->info('auth.password.attempt', [
                'success' => $authed !== null,
                'user_id' => $user?->id,
                'role' => $user?->role?->value,
                'ip' => $request->ip(),
                'x_forwarded_for' => $request->header('X-Forwarded-For'),
                'cf_connecting_ip' => $request->header('CF-Connecting-IP'),
                'host' => $request->getHost(),
                'user_agent' => substr((string) $request->userAgent(), 0, 200),
            ]);

            return $authed;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn () => view('pages::auth.login'));
        Fortify::verifyEmailView(fn () => view('pages::auth.verify-email'));
        Fortify::twoFactorChallengeView(fn () => view('pages::auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('pages::auth.confirm-password'));
        Fortify::registerView(fn () => view('pages::auth.register'));
        Fortify::resetPasswordView(fn () => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('pages::auth.forgot-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('sso', fn (Request $req) => Limit::perMinute(10)->by($req->ip()));
    }
}
