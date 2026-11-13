<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Miroir des endpoints EBP officiels, à la racine (/gescom/api/v1/...), stateless.
        then: fn () => Illuminate\Support\Facades\Route::group([], base_path('routes/mirror.php')),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'app.token' => \App\Http\Middleware\AuthenticateAppToken::class,
            '2fa' => \App\Http\Middleware\EnsureTwoFactorEnabled::class,
            'block.ip' => \App\Http\Middleware\BlockBannedIps::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Une requête qui cible l'API (sous /api, ou le proxy racine d'un préfixe EBP autorisé)
        // reçoit TOUJOURS une réponse JSON, jamais une page d'erreur HTML/Inertia.
        $isApiRequest = static function (Request $request): bool {
            if ($request->is('api/*') || $request->expectsJson()) {
                return true;
            }
            $path = ltrim($request->path(), '/');
            foreach ((array) config('ebp.gateway.proxy.allowed_prefixes', []) as $prefix) {
                $prefix = trim((string) $prefix, '/');
                if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix.'/'))) {
                    return true;
                }
            }

            return false;
        };

        $exceptions->shouldRenderJsonWhen($isApiRequest);

        // L'API EBP a répondu une erreur : on la relaie en 502 Bad Gateway propre
        // (la gateway est un intermédiaire), sans exposer la trace. Le statut EBP d'origine
        // est donné pour diagnostic ; 429 (quota EBP) est relayé tel quel.
        $exceptions->render(function (\App\Exceptions\Ebp\EbpApiException $e, Request $request) {
            return response()->json([
                'error' => 'ebp_upstream_error',
                'message' => "L'API EBP a renvoyé une erreur.",
                'ebpStatus' => $e->status,
            ], $e->isRateLimited() ? 429 : 502);
        });

        // Quota journalier EBP épuisé côté gateway.
        $exceptions->render(function (\App\Exceptions\Ebp\DailyQuotaExceededException $e, Request $request) {
            return response()->json([
                'error' => 'daily_quota_exceeded',
                'message' => 'Quota journalier EBP atteint pour la gateway.',
            ], 429);
        });

        // Compte EBP non authentifié (token absent, ou renouvellement OAuth échoué) :
        // ce n'est pas une faute de l'app, le compte doit être reconnecté par un admin.
        $exceptions->render(function (\App\Exceptions\Ebp\EbpAuthException $e, Request $request) {
            return response()->json([
                'error' => 'ebp_account_unauthenticated',
                'message' => $e->getMessage(),
            ], 503);
        });
    })->create();
