<?php

namespace App\Services\Ebp;

use App\Models\EbpAccount;
use App\Models\EbpFolder;
use Illuminate\Support\Facades\Cache;

/**
 * Cache des lectures de la gateway : clé, versions, invalidation.
 *
 * Invalidation par VERSIONING : chaque niveau (global, compte, dossier) a un compteur
 * de version inclus dans la clé. L'incrémenter périme d'un coup toutes les entrées
 * concernées, sans avoir à énumérer les clés (compatible tous les stores de cache).
 *
 * La clé d'une réponse intègre la SIGNATURE COMPLÈTE de la requête envoyée à EBP
 * (endpoint final, FolderId inclus + query triée) : changer un paramètre ou un filtre
 * donne une clé différente, donc une entrée de cache distincte.
 */
class GatewayCache
{
    private const VERSION_GLOBAL = 'ebp:cachever:global';

    /**
     * Clé de cache d'une réponse, versionnée (global + compte + dossier).
     *
     * @param  array<string,mixed>  $query
     */
    public function key(EbpAccount $account, ?EbpFolder $folder, string $endpoint, array $query): string
    {
        ksort($query);
        $signature = sha1($endpoint.'?'.http_build_query($query));

        // Endpoint hors dossier (ex. liste des dossiers) : pas de version de dossier.
        $folderPart = $folder
            ? ':f'.$folder->id.'.'.$this->version($this->folderKey($account, $folder))
            : ':f0';

        return 'ebp:read:'
            .'g'.$this->version(self::VERSION_GLOBAL)
            .':a'.$account->id.'.'.$this->version($this->accountKey($account))
            .$folderPart
            .':'.$signature;
    }

    public function get(string $key): mixed
    {
        return Cache::get($key);
    }

    public function put(string $key, mixed $value, int $ttl): void
    {
        Cache::put($key, $value, $ttl);
    }

    /** Invalide tout le cache de lecture (tous comptes, tous dossiers). */
    public function flushAll(): void
    {
        $this->bump(self::VERSION_GLOBAL);
    }

    /** Invalide le cache de lecture d'un compte (tous ses dossiers). */
    public function flushAccount(EbpAccount $account): void
    {
        $this->bump($this->accountKey($account));
    }

    /** Invalide le cache de lecture d'un dossier précis. */
    public function flushFolder(EbpAccount $account, ?EbpFolder $folder): void
    {
        if ($folder) {
            $this->bump($this->folderKey($account, $folder));
        }
    }

    private function version(string $key): int
    {
        return (int) Cache::get($key, 0);
    }

    private function bump(string $key): void
    {
        // Init à 0 si absent : certains stores n'incrémentent pas une clé inexistante.
        if (Cache::get($key) === null) {
            Cache::forever($key, 0);
        }

        Cache::increment($key);
    }

    private function accountKey(EbpAccount $account): string
    {
        return 'ebp:cachever:a:'.$account->id;
    }

    private function folderKey(EbpAccount $account, EbpFolder $folder): string
    {
        return 'ebp:cachever:f:'.$account->id.':'.$folder->id;
    }
}
