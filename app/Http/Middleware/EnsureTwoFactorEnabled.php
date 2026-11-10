<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Impose la double authentification (login fort) : un admin sans 2FA confirmée est
 * redirigé vers son profil pour l'activer, sauf sur les pages nécessaires à l'activation.
 * Désactivable via config ebp.security.enforce_2fa (utile en dev / premier admin).
 */
class EnsureTwoFactorEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('ebp.security.enforce_2fa', false)) {
            return $next($request);
        }

        $user = $request->user();

        // Déjà en 2FA, ou pas connecté (géré par le middleware auth) : on laisse passer.
        if (! $user || $user->two_factor_confirmed_at !== null) {
            return $next($request);
        }

        // Pages autorisées sans 2FA : la page de profil et TOUTES les actions de compte
        // de Fortify (changer son e-mail, son mot de passe, activer la 2FA). Cela permet
        // au premier admin de configurer entièrement son compte avant d'activer la 2FA,
        // sans ouvrir le reste du back-office.
        $allowed = $request->is('admin/profil')
            || $request->is('user/*')          // profile-information, password, two-factor-*…
            || $request->is('logout');

        if ($allowed) {
            return $next($request);
        }

        return redirect('/admin/profil')
            ->with('error', 'Activez la double authentification pour accéder au back-office.');
    }
}
