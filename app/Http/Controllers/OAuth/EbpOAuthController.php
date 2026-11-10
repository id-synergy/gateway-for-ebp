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

        // Page d'origine du clic « Reconnecter » : on y revient après le callback.
        // Conservée seulement si elle pointe vers le back-office des comptes (même app).
        $from = (string) $request->headers->get('referer');
        if ($from !== '' && str_starts_with($from, url('/admin/comptes'))) {
            $request->session()->put('ebp_oauth_return', $from);
        }

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

        // Retour vers la page d'origine du clic (fiche compte le plus souvent),
        // avec repli sur la fiche du compte puisqu'on le connaît.
        $return = $request->session()->pull('ebp_oauth_return')
            ?: route('admin.comptes.show', $account);

        // Le portail EBP renvoie error=... quand l'utilisateur refuse ou qu'une erreur survient.
        if ($error = (string) $request->query('error')) {
            $message = $error === 'access_denied'
                ? 'Autorisation refusée sur le portail EBP.'
                : "Erreur du portail EBP : {$error}.";

            $this->events->denied($account, $error);

            return redirect($return)->with('error', $message);
        }

        if ($code === '') {
            return redirect($return)->with('error', "Code d'autorisation manquant.");
        }

        return $this->finishConnection($account, $code, $return);
    }

    /**
     * POST /admin/comptes/{account}/manual-token
     *
     * Déblocage sans attendre la validation de domaine par EBP : l'admin a autorisé la
     * connexion sur le portail EBP, qui a renvoyé le `code` dans l'URL du redirect_uri
     * whitelisté (ex. localhost:3333) — même si rien n'y répond, l'URL est dans la barre
     * d'adresse. L'admin colle ici cette URL complète (ou juste le code) ; on fait
     * l'échange côté serveur avec le redirect_uri du compte.
     */
    public function manualExchange(Request $request, EbpAccount $account): RedirectResponse
    {
        $return = route('admin.comptes.show', $account);

        $raw = trim((string) $request->input('callback'));
        if ($raw === '') {
            return redirect($return)->with('error', 'Collez le retour EBP (URL complète ou code).');
        }

        [$code, $state] = $this->parseCallbackInput($raw);

        if ($code === '') {
            return redirect($return)->with('error', "Impossible de trouver le code dans ce que vous avez collé.");
        }

        // Le portail a pu renvoyer une erreur dans l'URL collée (refus, etc.).
        if ($code === null) {
            return redirect($return)->with('error', 'Le retour EBP collé contient une erreur, pas de code.');
        }

        // Vérif anti-CSRF tolérante : si un state est en session (même navigateur que le
        // connect), il doit correspondre ; sinon (code obtenu ailleurs) on tolère, car
        // c'est l'admin qui agit volontairement.
        $sessionState = $request->session()->pull('ebp_oauth_state');
        if ($sessionState !== null && $state !== null && $state !== '' && $state !== $sessionState) {
            return redirect($return)->with('error', 'Le state ne correspond pas à la session. Relancez « Reconnecter ».');
        }
        $request->session()->forget('ebp_oauth_account');

        return $this->finishConnection($account, $code, $return);
    }

    /**
     * Extrait [code, state] d'une saisie : URL complète de callback, fragment de query,
     * ou code brut. Retourne code = null si l'entrée contient un paramètre error.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function parseCallbackInput(string $raw): array
    {
        // Pas d'URL ni de query : on considère que c'est le code brut.
        if (! str_contains($raw, '?') && ! str_contains($raw, '=') && ! str_contains($raw, '://')) {
            return [$raw, null];
        }

        // Récupère la chaîne de requête, que ce soit une URL complète ou juste "code=...&state=...".
        $query = $raw;
        if (($pos = strpos($raw, '?')) !== false) {
            $query = substr($raw, $pos + 1);
        }
        parse_str($query, $params);

        if (! empty($params['error'])) {
            return [null, $params['state'] ?? null];
        }

        return [(string) ($params['code'] ?? ''), isset($params['state']) ? (string) $params['state'] : null];
    }

    /**
     * Échange le code, journalise, synchronise les dossiers et redirige.
     * Partagé entre le callback automatique et l'échange manuel.
     */
    private function finishConnection(EbpAccount $account, string $code, string $return): RedirectResponse
    {
        // Reconnexion = le compte possédait déjà un token avant cet échange.
        $reconnection = $account->token()->exists();

        try {
            $this->tokens->exchangeCode($account, $code);
            $this->events->connected($account, $reconnection);
            $this->folders->sync($account);
        } catch (\Throwable $e) {
            Log::error('Connexion EBP échouée', ['account' => $account->id, 'message' => $e->getMessage()]);
            $this->events->refreshFailed($account, $e->getMessage());

            return redirect($return)->with('error', $this->explainExchangeError($e->getMessage()));
        }

        return redirect($return)->with('success', "Compte EBP « {$account->label} » connecté.");
    }

    /**
     * Traduit le message d'erreur d'échange OAuth (souvent `{"error":"..."}` renvoyé par EBP)
     * en explication actionnable pour l'admin : on sait quoi corriger (code expiré, redirect_uri
     * incohérent, identifiants, etc.) plutôt qu'un message brut peu lisible.
     */
    private function explainExchangeError(string $raw): string
    {
        $r = strtolower($raw);

        return match (true) {
            str_contains($r, 'invalid_grant')
                => 'Code d\'autorisation invalide ou expiré. Le code EBP n\'est valable que quelques instants et à usage unique : relancez « Reconnecter » et collez le retour aussitôt.',
            str_contains($r, 'redirect_uri') && (str_contains($r, 'mismatch') || str_contains($r, 'invalid'))
                => 'Le redirect_uri ne correspond pas à celui autorisé chez EBP. Vérifiez qu\'il est identique au caractère près (mode de redirection du compte / EBP_LOCALHOST_REDIRECT).',
            str_contains($r, 'invalid_client') || str_contains($r, 'unauthorized_client')
                => 'ClientId ou ClientSecret EBP refusé. Vérifiez les identifiants du compte dans « Modifier ».',
            str_contains($r, 'invalid_scope')
                => 'Portées (scope) refusées par EBP. Vérifiez la configuration du scope (openid profile offline_access).',
            default
                => 'Connexion EBP échouée : '.$raw,
        };
    }
}
