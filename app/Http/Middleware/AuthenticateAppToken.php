<?php

namespace App\Http\Middleware;

use App\Models\EbpFolder;
use App\Services\Apps\AppTokenService;
use App\Services\Apps\RouteRuleMatcher;
use App\Services\Ebp\EbpPath;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentifie une app cliente par son jeton (en-tête Authorization: Bearer <jeton gw_...>).
 * Résout le compte EBP (via le jeton) et le dossier (folderId de la requête > défaut du jeton),
 * et les attache à la requête : $request->attributes 'app_token', 'ebp_account', 'ebp_folder'.
 */
class AuthenticateAppToken
{
    public function __construct(
        protected AppTokenService $tokens,
        protected RouteRuleMatcher $routeRules,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->tokens->resolve($request->bearerToken());

        if (! $token) {
            return response()->json(['message' => "Jeton d'application invalide ou révoqué."], 401);
        }

        $account = $token->account;

        if (! $account || ! $account->is_active) {
            return response()->json(['message' => 'Compte EBP indisponible pour ce jeton.'], 403);
        }

        // Résolution du dossier : paramètre de requête > dossier par défaut du jeton.
        $folder = $this->resolveFolder($request, $token);

        // Le dossier n'est exigé que si l'endpoint en a besoin. Les endpoints proxy
        // « hors dossier » (racine du module, GET .../Folders qui LISTE les dossiers)
        // passent sans dossier ; le proxy renvoie lui-même un 400 clair pour une
        // ressource de dossier appelée sans dossier résolu.
        if (! $folder && $this->folderRequired($request)) {
            return response()->json([
                'error' => 'folder_required',
                'message' => "Aucun dossier ciblé : précisez 'folderId' ou configurez un dossier par défaut sur le jeton.",
            ], 400);
        }

        // Contexte d'accès par app : si le jeton porte des règles, l'appel proxy EBP doit
        // matcher l'une d'elles, sinon 403. Les fonctions internes de la gateway (/api/v1 :
        // whoami, jobs, compose, batch) ne sont JAMAIS filtrées — elles ne ciblent aucun
        // préfixe EBP. Un jeton SANS règle garde l'accès global (défaut ouvert).
        if ($denied = $this->routeRuleDenied($request, $token)) {
            return $denied;
        }

        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        $request->attributes->set('app_token', $token);
        $request->attributes->set('ebp_account', $account);
        $request->attributes->set('ebp_folder', $folder);

        return $next($request);
    }

    /**
     * L'endpoint appelé exige-t-il un dossier résolu ?
     *
     * Le dossier est exigé par défaut (routes composite, etc.). SEULE exception :
     * un chemin proxy EBP qui n'a pas besoin de dossier — la racine d'un module et
     * GET .../Folders (qui LISTE les dossiers). Ces appels doivent fonctionner même
     * quand le jeton n'a pas de dossier par défaut ; le proxy renvoie lui-même un 400
     * clair si une ressource de dossier est appelée sans dossier.
     */
    private function folderRequired(Request $request): bool
    {
        $path = ltrim($request->path(), '/');
        $prefix = EbpPath::matchPrefix($path);

        // Chemin proxy sans besoin de dossier -> pas d'exigence ici.
        if ($prefix !== null && ! EbpPath::needsFolder($path, $prefix)) {
            return false;
        }

        // Tout le reste (ressources de dossier, /whoami, /compose, /batch…) exige un dossier.
        return true;
    }

    /**
     * Les règles d'accès du jeton refusent-elles cet appel ? Retourne la réponse 403 si oui.
     *
     * Ne filtre QUE les chemins proxy EBP (préfixés par un module EBP). Les fonctions gateway
     * (/api/v1/…) n'ont pas de préfixe EBP et passent toujours. Un jeton sans règle passe aussi
     * (défaut ouvert). L'intersection avec le périmètre global est garantie « gratuitement » :
     * un chemin hors préfixe est déjà rejeté en 404 par le proxy, donc une règle ne peut pas
     * élargir au-delà de ce que la gateway autorise déjà.
     */
    private function routeRuleDenied(Request $request, $token): ?Response
    {
        $path = ltrim($request->path(), '/');

        // Pas un chemin proxy EBP (ex. /api/v1/whoami) -> pas de filtrage par règles.
        if (EbpPath::matchPrefix($path) === null) {
            return null;
        }

        $rules = $token->routeRules()->get();

        // Aucune règle -> accès global inchangé (défaut ouvert).
        if ($rules->isEmpty()) {
            return null;
        }

        if ($this->routeRules->allows($rules, $request->method(), $path)) {
            return null;
        }

        return response()->json([
            'error' => 'route_not_allowed',
            'message' => "Cette application n'est pas autorisée à appeler cette route EBP ({$request->method()} {$path}).",
        ], 403);
    }

    private function resolveFolder(Request $request, $token): ?EbpFolder
    {
        $folderId = $request->query('folderId') ?? $request->input('folderId');

        if ($folderId) {
            return EbpFolder::where('ebp_account_id', $token->ebp_account_id)
                ->where('folder_id', (string) $folderId)
                ->where('is_active', true)
                ->first();
        }

        return $token->defaultFolder;
    }
}
