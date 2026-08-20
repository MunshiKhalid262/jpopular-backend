<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Policies\UserPolicy;
use App\Support\ApiResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Registered explicitly rather than relying on auto-discovery, so the
        // mapping is greppable. See ARCHITECTURE-V1.md section 12.
        Gate::policy(User::class, UserPolicy::class);

        $this->configurePasswordPolicy();
        $this->configureRateLimiting();

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }

    /**
     * Minimum 12 characters with letters and numbers everywhere; the HIBP
     * breach check runs only outside testing so the suite stays offline and
     * deterministic.
     */
    private function configurePasswordPolicy(): void
    {
        Password::defaults(function (): Password {
            $rule = Password::min(12)->letters()->numbers();

            return $this->app->runningUnitTests()
                ? $rule
                : $rule->uncompromised();
        });
    }

    private function configureRateLimiting(): void
    {
        // Login: keyed on IP *and* submitted email, so neither brute-forcing a
        // single account nor spraying one password across many accounts is cheap.
        RateLimiter::for('login', function (Request $request): array {
            $email = (string) $request->input('email');

            $response = fn (): mixed => ApiResponse::error(
                message: 'Too many login attempts. Please try again in a minute.',
                status: 429,
                code: 'TOO_MANY_ATTEMPTS',
            );

            return [
                Limit::perMinute(5)->by('login|ip|'.$request->ip())->response($response),
                Limit::perMinute(5)->by('login|email|'.mb_strtolower($email))->response($response),
            ];
        });

        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(60)
                ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip()))
                ->response(fn (): mixed => ApiResponse::error(
                    message: 'Too many requests. Please slow down.',
                    status: 429,
                    code: 'RATE_LIMITED',
                ));
        });
    }
}
