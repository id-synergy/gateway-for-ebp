<?php

namespace App\Providers;

use App\Listeners\RecordAdminAuthEvent;
use App\Services\Security\IpAccessControl;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->registerAdminAuthEventListeners();
    }

    /**
     * Journal de connexion des admins : on branche un listener sur les events
     * d'authentification Laravel/Fortify (cf. App\Listeners\RecordAdminAuthEvent).
     */
    protected function registerAdminAuthEventListeners(): void
    {
        Event::listen(Login::class, [RecordAdminAuthEvent::class, 'handleLogin']);
        Event::listen(Logout::class, [RecordAdminAuthEvent::class, 'handleLogout']);
        Event::listen(Failed::class, [RecordAdminAuthEvent::class, 'handleFailed']);
        Event::listen(ValidTwoFactorAuthenticationCodeProvided::class, [RecordAdminAuthEvent::class, 'handleTwoFactorPassed']);
        Event::listen(TwoFactorAuthenticationFailed::class, [RecordAdminAuthEvent::class, 'handleTwoFactorFailed']);
    }

    /**
     * Rate-limit applicatif de la gateway.
     * IP en status=allowed (ip_entries) -> illimité. Sinon -> gros bridage (429 au dépassement).
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('gateway', function (Request $request) {
            // IP autorisée -> illimité (lookup mis en cache court via IpAccessControl).
            if (app(IpAccessControl::class)->isAllowed($request->ip())) {
                return Limit::none();
            }

            return Limit::perMinute((int) config('ebp.gateway.rate_limit_per_min'))
                ->by($request->ip());
        });
    }
}
