<?php

namespace App\Http\Middleware;

use App\Services\Security\IpAccessControl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocage applicatif des IP en liste noire (ip_entries, status=blocked).
 *
 * Toute requête dont l'IP est bloquée reçoit un 403 JSON (error: ip_blocked).
 * Garde-fou anti-verrouillage : les boucles locales (127.0.0.1, ::1) ne sont
 * JAMAIS bloquées — cf. IpAccessControl::isBlocked(). Le lookup est mis en
 * cache court (60 s) pour ne pas toucher la DB à chaque requête.
 */
class BlockBannedIps
{
    public function __construct(private IpAccessControl $ipAccess) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->ipAccess->isBlocked($request->ip())) {
            return response()->json([
                'error' => 'ip_blocked',
                'message' => 'Accès refusé : cette adresse IP est bloquée.',
            ], 403);
        }

        return $next($request);
    }
}
