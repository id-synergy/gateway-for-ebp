<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Models\EbpAccount;
use App\Services\Accounts\AccountEventLogger;
use App\Services\Ebp\EbpFolderDiscovery;
use App\Services\Ebp\EbpTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Flux OAuth EBP (Authorization Code), multi-comptes.
 *
 *  - connect  : démarre la connexion d'UN compte (génère un state lié au compte, redirige vers EBP)
 *  - callback : URL whitelistée chez EBP ; vérifie le state, échange le code, stocke le refresh token
 */
class EbpOAuthController extends Controller
{
    public function __construct(
        protected EbpTokenService $tokens,
        protected EbpFolderDiscovery $folders,
        protected AccountEventLogger $events,
    ) {}

    /** GET /oauth/ebp/connect?account={id} */
    public function connect(Request $request): RedirectResponse
    {
        $account = EbpAccount::findOrFail($request->integer('account'));

        // state = valeur aléatoire liée au compte, stockée en session pour vérif au retour (anti-CSRF).
        $state = Str::random(40);
        $request->session()->put('ebp_oauth_state', $state);
        $request->session()->put('ebp_oauth_account', $account->id);

        return redirect()->away($this->tokens->buildAuthorizeUrl($account, $state));
    }

    /** GET /oauth/ebp/callback?code=...&state=... (URL whitelistée chez EBP) */
    public function callback(Request $request): RedirectResponse
    {
        $state = (string) $request->query('state');
        $code = (string) $request->query('code');

        // Vérification anti-CSRF du state.
        if ($state === '' || $state !== $request->session()->pull('ebp_oauth_state')) {
            abort(403, 'State OAuth invalide.');
        }

        $accountId = $request->session()->pull('ebp_oauth_account');
        $account = EbpAccount::findOrFail($accountId);

        // Le portail EBP renvoie error=... quand l'utilisateur refuse ou qu'une erreur survient.
        if ($error = (string) $request->query('error')) {
            $message = $error === 'access_denied'
                ? 'Autorisation refusée sur le portail EBP.'
                : "Erreur du portail EBP : {$error}.";

            $this->events->denied($account, $error);

            return redirect('/admin/comptes')->with('error', $message);
        }

        if ($code === '') {
            return redirect('/admin/comptes')->with('error', "Code d'autorisation manquant.");
        }

        // Reconnexion = le compte possédait déjà un token avant cet échange.
        $reconnection = $account->token()->exists();

        try {
            $this->tokens->exchangeCode($account, $code);
            $this->events->connected($account, $reconnection);
            $this->folders->sync($account);
        } catch (\Throwable $e) {
            Log::error('Connexion EBP échouée', ['account' => $account->id, 'message' => $e->getMessage()]);
            $this->events->refreshFailed($account, $e->getMessage());

            return redirect('/admin/comptes')->with('error', 'Connexion EBP échouée : '.$e->getMessage());
        }

        return redirect('/admin/comptes')->with('success', "Compte EBP « {$account->label} » connecté.");
    }
}
