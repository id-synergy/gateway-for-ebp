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
     * L'endpoint cible-t-il une ressource DANS un dossier (donc un dossier est requis) ?
     *
     * Non pour la racine du module et pour `.../Folders` (qui LISTE les dossiers) :
     * ces appels doivent fonctionner même sans dossier résolu.
     */
    public static function needsFolder(string $path, string $prefix): bool
    {
        $rest = ltrim(substr($path, strlen($prefix)), '/');

        return $rest !== '' && $rest !== 'Folders';
    }
}
