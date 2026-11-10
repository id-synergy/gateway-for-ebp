<?php

namespace App\Services\Ebp;

use App\Models\EbpAccount;
use App\Models\EbpFolder;
use App\Services\Accounts\AccountEventLogger;
use Illuminate\Support\Facades\Http;

/**
 * Récupère les dossiers d'un compte EBP (GET /gescom/api/v1/Folders) et les enregistre.
 * Réponse EBP : { folders: [{ id, name, shortName }], paging }.
 */
class EbpFolderDiscovery
{
    public function __construct(
        protected EbpTokenService $tokens,
        protected QuotaRegulator $quota,
        protected AccountEventLogger $events,
    ) {}

    /**
     * Synchronise les dossiers du compte. Retourne le nombre de dossiers vus.
     * L'appel passe par le régulateur de quota (comme tous les appels EBP).
     */
    public function sync(EbpAccount $account): int
    {
        // Mode démo : dossiers simulés, sans appel réseau (ni quota consommé).
        if ($account->usesFakeEbp()) {
            $folders = FakeEbpData::folders()['folders'];
        } else {
            $response = $this->quota->run(fn () => Http::withHeaders([
                'Authorization' => 'Bearer '.$this->tokens->validAccessToken($account),
                'ebp-subscription-key' => $account->activeSubscriptionKey(),
                'Accept-Language' => 'fr-FR',
            ])->get(rtrim($account->apiBaseUrl(), '/').'/gescom/api/v1/Folders', [
                'Offset' => 0,
                'Limit' => config('ebp.quota.max_page_size'),
            ]));

            if ($response->failed()) {
                return 0;
            }

            $folders = $response->json('folders', []);
        }

        foreach ($folders as $folder) {
            if (empty($folder['id'])) {
                continue;
            }

            EbpFolder::updateOrCreate(
                ['ebp_account_id' => $account->id, 'folder_id' => (string) $folder['id']],
                [
                    'short_name' => $folder['shortName'] ?? null,
                    'label' => $folder['name'] ?? null,
                    'is_active' => true,
                ],
            );
        }

        $this->events->foldersSynced($account, count($folders));

        return count($folders);
    }
}
