<?php

namespace App\Providers;

use App\Models\AllowedIp;
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
     * IP présente dans allowed_ips -> illimité. Sinon -> gros bridage (429 au dépassement).
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('gateway', function (Request $request) {
            // IP autorisée -> illimité. En cas d'indisponibilité DB, on bride par défaut (sécurité).
            try {
                if (AllowedIp::where('ip', $request->ip())->exists()) {
                    return Limit::none();
                }
            } catch (\Throwable) {
                // on retombe sur le bridage ci-dessous
            }

            return Limit::perMinute((int) config('ebp.gateway.rate_limit_per_min'))
                ->by($request->ip());
        });
    }
}
