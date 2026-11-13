<?php

namespace App\Providers;

use App\Services\Security\IpAccessControl;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
