<?php

namespace App\Jobs;

use App\Mail\AccountExpiringMail;
use App\Mail\TokenRefreshFailedMail;
use App\Models\EbpAccount;
use App\Services\Alerts\AlertService;
use App\Services\Ebp\EbpTokenService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Renouvelle les tokens de TOUS les comptes EBP connectés.
 * Planifié toutes les 6 h (voir routes/console.php). Verrou PAR COMPTE pour éviter
 * deux renouvellements simultanés (qui s'invalideraient mutuellement).
 */
class RefreshEbpTokens implements ShouldQueue
{
    use Queueable;

    public function handle(EbpTokenService $tokens, AlertService $alerts): void
    {
        foreach (EbpAccount::where('is_active', true)->get() as $account) {
            $lock = Cache::lock("ebp-token-refresh:{$account->id}", 60);

            if (! $lock->get()) {
                continue; // un renouvellement de ce compte est déjà en cours
            }

            try {
                $tokens->refresh($account);
            } catch (\Throwable $e) {
                Log::error('Renouvellement du token EBP échoué', [
                    'account' => $account->id,
                    'message' => $e->getMessage(),
                ]);

                $alerts->send(
                    TokenRefreshFailedMail::ALERT_TYPE,
                    new TokenRefreshFailedMail($account->fresh(), $e->getMessage()),
                    "ebp_account:{$account->id}",
                    ['account' => $account->label],
                );
            } finally {
                $lock->release();
            }

            $this->alertIfExpiring($account->fresh(), $alerts);
        }
    }

    /**
     * Alerte si le refresh token approche de l'expiration (seuil configurable),
     * pour prévenir avant que la chaîne ne casse (reconnexion manuelle).
     */
    protected function alertIfExpiring(EbpAccount $account, AlertService $alerts): void
    {
        $expiresAt = $account->token?->refresh_token_expires_at;

        if (! $expiresAt) {
            return;
        }

        $daysLeft = (int) now()->diffInDays($expiresAt, false);
        $threshold = (int) config('ebp.token_refresh.expiring_alert_days');

        if ($daysLeft < 0 || $daysLeft > $threshold) {
            return;
        }

        $alerts->send(
            AccountExpiringMail::ALERT_TYPE,
            new AccountExpiringMail($account, max(0, $daysLeft)),
            "ebp_account:{$account->id}",
            ['account' => $account->label, 'daysLeft' => $daysLeft],
        );
    }
}
