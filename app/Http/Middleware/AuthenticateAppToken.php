<?php

namespace App\Http\Middleware;

use App\Models\EbpFolder;
use App\Services\Apps\AppTokenService;
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
    public function __construct(protected AppTokenService $tokens) {}

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
