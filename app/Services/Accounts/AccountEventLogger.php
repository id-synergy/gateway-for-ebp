<?php

namespace App\Services\Accounts;

use App\Models\AccountEvent;
use App\Models\EbpAccount;

/**
 * Journalise les événements du cycle de vie d'un compte EBP (connexion, renouvellement
 * de token, refus, synchronisation de dossiers, actions sur les jetons d'apps).
 *
 * Chaque helper enregistre une ligne dans account_events, affichée en timeline sur la
 * fiche du compte. Le logging ne doit jamais faire échouer l'opération métier : en cas
 * de souci d'écriture, on avale l'erreur (le journal est secondaire).
 */
class AccountEventLogger
{
    /**
     * @param  array<string,mixed>  $context
     */
    public function log(EbpAccount $account, string $type, string $level, string $message, array $context = []): void
    {
        try {
            AccountEvent::create([
                'ebp_account_id' => $account->id,
                'type' => $type,
                'level' => $level,
                'message' => $message,
                'context' => $context ?: null,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable) {
            // Journal secondaire : ne jamais casser l'opération métier pour un log.
        }
    }

    public function connected(EbpAccount $account, bool $reconnection = false): void
    {
        $this->log(
            $account,
            $reconnection ? 'reconnected' : 'connected',
            'success',
            $reconnection ? 'Compte reconnecté à EBP.' : 'Compte connecté à EBP.',
        );
    }

    public function tokenRefreshed(EbpAccount $account): void
    {
        $this->log($account, 'token_refreshed', 'info', 'Token d\'accès renouvelé automatiquement.');
    }

    public function refreshFailed(EbpAccount $account, string $error): void
    {
        $this->log($account, 'refresh_failed', 'error', 'Échec du renouvellement du token.', ['error' => $error]);
    }

    public function denied(EbpAccount $account, string $reason): void
    {
        $this->log($account, 'denied', 'warning', 'Autorisation refusée sur le portail EBP.', ['reason' => $reason]);
    }

    public function foldersSynced(EbpAccount $account, int $count): void
    {
        $this->log($account, 'folders_synced', 'info', "Dossiers synchronisés depuis EBP ({$count}).", ['count' => $count]);
    }

    public function tokenCreated(EbpAccount $account, string $appName): void
    {
        $this->log($account, 'app_token_created', 'info', "Jeton d'app créé : « {$appName} ».", ['app' => $appName]);
    }

    public function tokenRevoked(EbpAccount $account, string $appName): void
    {
        $this->log($account, 'app_token_revoked', 'warning', "Jeton d'app révoqué : « {$appName} ».", ['app' => $appName]);
    }

    public function tokenRegenerated(EbpAccount $account, string $appName): void
    {
        $this->log($account, 'app_token_regenerated', 'warning', "Clé régénérée pour le jeton « {$appName} ».", ['app' => $appName]);
    }
}
