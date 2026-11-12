<?php

namespace App\Services\Ebp;

/**
 * Analyse d'un chemin EBP relayé par la gateway (proxy transparent).
 *
 * Source unique de vérité partagée par le middleware d'auth (faut-il un dossier ?)
 * et le proxy (injection du FolderId, champ 'folder' de la réponse).
 */
class EbpPath
{
    /** Retourne le préfixe EBP autorisé qui matche le chemin, ou null. */
    public static function matchPrefix(string $path): ?string
    {
        foreach ((array) config('ebp.gateway.proxy.allowed_prefixes', []) as $prefix) {
            $prefix = trim($prefix, '/');
            if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix.'/'))) {
                return $prefix;
            }
        }

        return null;
    }

    /**
     * L'endpoint cible-t-il une ressource DANS un dossier, avec FolderId dans l'URL ?
     *
     * Vrai seulement pour les modules qui suivent le modèle …/Folders/{FolderId}/… (gescom,
     * batiment, maintenance) ET quand l'endpoint n'est pas la racine du module ni `.../Folders`
     * (qui LISTE les dossiers). Pour les modules « plats » (gctpe, cpttpe, import, export), le
     * dossier passe par un header ou le corps : jamais d'injection de FolderId dans l'URL.
     */
    public static function needsFolder(string $path, string $prefix): bool
    {
        if (! self::usesFolderUrl($prefix)) {
            return false;
        }

        $rest = ltrim(substr($path, strlen($prefix)), '/');

        return $rest !== '' && $rest !== 'Folders';
    }

    /**
     * Applique les réécritures de compatibilité au chemin avant relai vers EBP.
     *
     * L'API EBP expose certains endpoints sous une orthographe qui ne correspond pas à sa
     * propre spec (ex. « ItemsExtentedQuery » au lieu de « ItemsExtendedQuery ») : l'URL
     * documentée renvoie alors un 404. On corrige ici vers ce qu'EBP attend réellement, à
     * partir de la table config ebp.gateway.proxy.path_rewrites {motif regex => remplacement}.
     */
    public static function applyRewrites(string $path): string
    {
        foreach ((array) config('ebp.gateway.proxy.path_rewrites', []) as $pattern => $replacement) {
            $rewritten = preg_replace($pattern, $replacement, $path);
            if ($rewritten !== null) {
                $path = $rewritten;
            }
        }

        return $path;
    }

    /** Le module (préfixe) place-t-il le FolderId dans l'URL (modèle …/Folders/{id}/…) ? */
    public static function usesFolderUrl(string $prefix): bool
    {
        $prefix = trim($prefix, '/');

        foreach ((array) config('ebp.gateway.proxy.folder_url_prefixes', []) as $fp) {
            if (trim((string) $fp, '/') === $prefix) {
                return true;
            }
        }

        return false;
    }
}
