<?php

namespace App\Http\Middleware;

use App\Models\AdminAuthEvent;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Détection best-effort d'une session admin expirée.
 *
 * Principe : à chaque requête du groupe admin, on mémorise en session l'instant de la
 * dernière activité et le dernier user connu. À la requête SUIVANTE, si l'utilisateur
 * n'est plus authentifié alors qu'on avait un user connu et que l'écart dépasse la durée
 * de vie de session configurée (config('session.lifetime'), en minutes), on journalise
 * un événement session_expired pour ce dernier user.
 *
 * LIMITE ASSUMÉE : c'est du best-effort. Une session simplement abandonnée (plus aucune
 * requête) ne produit AUCUN événement tant qu'une nouvelle requête n'arrive pas — le GC
 * de session de Laravel nettoie la session côté serveur sans repasser par ce middleware.
 * L'événement n'est donc fiable que lorsque l'utilisateur revient après le délai.
 *
 * Ce middleware n'OBSERVE que : il ne bloque jamais l'accès et ne force aucun logout.
 */
class TrackAdminSessionExpiry
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();

        $lastActivity = $session->get('admin_last_activity');
        $lastUserId = $session->get('admin_last_user_id');
        $lastEmail = $session->get('admin_last_email');

        // L'utilisateur n'est plus authentifié mais on avait un user connu et le délai
        // d'inactivité dépasse la durée de vie de session : on marque l'expiration, une
        // seule fois (on efface les marqueurs juste après).
        if ($lastActivity && $lastUserId && ! $request->user()) {
            $lifetimeSeconds = (int) config('session.lifetime') * 60;
            $idleSeconds = now()->timestamp - (int) $lastActivity;

            if ($lifetimeSeconds > 0 && $idleSeconds >= $lifetimeSeconds) {
                AdminAuthEvent::create([
                    'user_id' => $lastUserId,
                    'email' => $lastEmail,
                    'event' => AdminAuthEvent::EVENT_SESSION_EXPIRED,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'occurred_at' => now(),
                ]);
            }

            $session->forget(['admin_last_activity', 'admin_last_user_id', 'admin_last_email']);
        }

        $response = $next($request);

        // On rafraîchit les marqueurs tant que l'admin est authentifié.
        if ($user = $request->user()) {
            $session->put('admin_last_activity', now()->timestamp);
            $session->put('admin_last_user_id', $user->getKey());
            $session->put('admin_last_email', $user->email ?? null);
        }

        return $response;
    }
}
