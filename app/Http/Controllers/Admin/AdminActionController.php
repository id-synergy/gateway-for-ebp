<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppToken;
use App\Models\AppTokenRouteRule;
use App\Models\EbpAccount;
use App\Models\EbpFolder;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Accounts\AccountEventLogger;
use App\Services\Apps\AppTokenService;
use App\Services\Audit\AdminActionLogger;
use App\Services\Ebp\EbpFolderDiscovery;
use App\Services\Ebp\GatewayCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Actions du back-office (création, révocation, suppression). Réservées aux admins.
 */
class AdminActionController extends Controller
{
    public function __construct(
        protected AppTokenService $tokens,
        protected AccountEventLogger $events,
        protected AdminActionLogger $audit,
    ) {}

    public function storeToken(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'ebp_account_id' => 'required|exists:ebp_accounts,id',
            'default_folder_id' => 'nullable|exists:ebp_folders,id',
        ]);

        $account = EbpAccount::findOrFail($data['ebp_account_id']);
        $folder = ! empty($data['default_folder_id']) ? EbpFolder::find($data['default_folder_id']) : null;

        [$token, $plain] = $this->tokens->create($data['name'], $account, $folder);
        $this->events->tokenCreated($account, $data['name']);
        // Audit : on loge le nom et le compte, JAMAIS la clé en clair.
        $this->audit->log('token.created', $token, $data['name'], ['account' => $account->label]);

        // Le jeton en clair n'est montré qu'une fois, via le flash.
        return back()->with('newToken', $plain);
    }

    public function revokeToken(AppToken $token): RedirectResponse
    {
        $this->tokens->revoke($token);
        $this->events->tokenRevoked($token->account, $token->name);
        $this->audit->log('token.revoked', $token, $token->name, ['account' => $token->account?->label]);

        return back()->with('success', 'Jeton révoqué.');
    }

    public function regenerateToken(AppToken $token): RedirectResponse
    {
        // Une clé révoquée ne se régénère pas : la révocation est définitive, on recrée un jeton.
        if (! $token->is_active) {
            return back()->with('error', 'Ce jeton est révoqué : créez-en un nouveau plutôt que de le régénérer.');
        }

        $plain = $this->tokens->regenerate($token);
        $this->events->tokenRegenerated($token->account, $token->name);
        // On loge « clé régénérée », JAMAIS la nouvelle clé.
        $this->audit->log('token.regenerated', $token, $token->name, ['account' => $token->account?->label]);

        // Le nouveau jeton en clair n'est montré qu'une fois, via le flash.
        return back()->with('newToken', $plain);
    }

    /**
     * Remplace l'intégralité des règles d'accès d'un jeton (liste blanche de routes EBP).
     *
     * Une liste vide « ouvre » le jeton (accès au périmètre global) ; au moins une règle le
     * restreint. Les verbes sont normalisés en MAJUSCULES, le pattern débarrassé de ses « / »
     * de tête/queue. L'ordre de saisie est conservé via `position`.
     */
    public function updateTokenRules(Request $request, AppToken $token): RedirectResponse
    {
        $data = $request->validate([
            'rules' => 'present|array',
            'rules.*.methods' => 'required|array|min:1',
            'rules.*.methods.*' => 'required|string|in:GET,POST,PUT,PATCH,DELETE',
            'rules.*.pattern' => 'required|string|max:255',
        ], [], [
            'rules.*.methods' => 'verbes',
            'rules.*.pattern' => 'motif de route',
        ]);

        $token->routeRules()->delete();

        foreach (array_values($data['rules']) as $i => $rule) {
            AppTokenRouteRule::create([
                'app_token_id' => $token->id,
                'methods' => array_values(array_unique(array_map('strtoupper', $rule['methods']))),
                'pattern' => trim($rule['pattern'], '/'),
                'position' => $i,
            ]);
        }

        $count = count($data['rules']);
        $message = $count === 0
            ? "Règles supprimées : le jeton « {$token->name} » a de nouveau accès à tout le périmètre EBP."
            : "{$count} règle(s) d'accès enregistrée(s) pour le jeton « {$token->name} ».";

        $this->audit->log('token.rules_updated', $token, $token->name, ['rules_count' => $count]);

        return back()->with('success', $message);
    }

    public function storeAdmin(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => ['required', Password::min(10)],
            'is_default_contact' => 'boolean',
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'is_default_contact' => $data['is_default_contact'] ?? false,
            'is_active' => true,
        ]);

        // On loge le nom et l'e-mail, JAMAIS le mot de passe.
        $this->audit->log('admin.created', $user, $user->name, ['email' => $user->email]);

        return back()->with('success', 'Admin créé.');
    }

    public function toggleAdmin(User $user): RedirectResponse
    {
        // On ne désactive pas le dernier admin actif.
        if ($user->is_active && User::where('is_active', true)->count() <= 1) {
            return back()->with('error', 'Impossible de désactiver le dernier admin actif.');
        }

        $user->update(['is_active' => ! $user->is_active]);

        $this->audit->log(
            $user->is_active ? 'admin.reactivated' : 'admin.deactivated',
            $user,
            $user->name,
            ['email' => $user->email],
        );

        return back()->with('success', $user->is_active ? 'Admin réactivé.' : 'Admin désactivé.');
    }

    public function togglePreference(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'alert_type' => 'required|string',
            'enabled' => 'required|boolean',
        ]);

        NotificationPreference::updateOrCreate(
            ['user_id' => $data['user_id'], 'alert_type' => $data['alert_type']],
            ['enabled' => $data['enabled']],
        );

        return back()->with('success', 'Préférence enregistrée.');
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'label' => 'required|string|max:255',
            'client_id' => 'required|string',
            'client_secret' => 'required|string',
            'subscription_key_primary' => 'required|string',
            'subscription_key_secondary' => 'nullable|string',
            'environment' => 'required|in:production,preproduction,demo',
        ]);

        $account = EbpAccount::create([
            'label' => $data['label'],
            'client_id' => $data['client_id'],
            'client_secret' => $data['client_secret'],
            'subscription_key_primary' => $data['subscription_key_primary'],
            'subscription_key_secondary' => $data['subscription_key_secondary'] ?? null,
            'active_subscription_key' => 'primary',
            'environment' => $data['environment'],
            'status' => 'disconnected',
            'is_active' => true,
        ]);

        // On loge le label et l'environnement, JAMAIS les credentials (client_secret, clés d'abonnement).
        $this->audit->log('account.created', $account, $account->label, ['environment' => $account->environment]);

        return back()->with('success', 'Compte EBP créé. Connectez-le pour initier le flux OAuth.');
    }

    public function updateAccount(Request $request, EbpAccount $account): RedirectResponse
    {
        $data = $request->validate([
            'label' => 'required|string|max:255',
            'client_id' => 'nullable|string',
            'client_secret' => 'nullable|string',
            'subscription_key_primary' => 'nullable|string',
            'subscription_key_secondary' => 'nullable|string',
            'active_subscription_key' => 'required|in:primary,secondary',
            'environment' => 'required|in:production,preproduction,demo',
            'redirect_mode' => 'nullable|in:gateway,localhost,custom',
            'custom_redirect_uri' => 'nullable|url|max:255|required_if:redirect_mode,custom',
            'cache_enabled' => 'nullable|boolean',
            'cache_ttl' => 'nullable|integer|min:0|max:86400',
        ]);

        // On ne remplace un secret que s'il est effectivement renseigné (champ laissé vide = inchangé).
        $update = [
            'label' => $data['label'],
            'active_subscription_key' => $data['active_subscription_key'],
            'environment' => $data['environment'],
        ];
        // Mode de redirection OAuth + redirect_uri personnalisé (gardé seulement en mode custom).
        if ($request->has('redirect_mode')) {
            $mode = $data['redirect_mode'] ?: 'gateway';
            $update['redirect_mode'] = $mode;
            $update['custom_redirect_uri'] = $mode === 'custom' ? ($data['custom_redirect_uri'] ?: null) : null;
        }
        // Réglages de cache : mis à jour seulement s'ils sont transmis par le formulaire.
        if ($request->has('cache_enabled')) {
            $update['cache_enabled'] = (bool) $data['cache_enabled'];
            $update['cache_ttl'] = $data['cache_ttl'] ?? null;
        }
        foreach (['client_id', 'client_secret', 'subscription_key_primary', 'subscription_key_secondary'] as $field) {
            if (! empty($data[$field])) {
                $update[$field] = $data[$field];
            }
        }
        $account->update($update);

        // Audit : on liste les champs touchés mais on masque toujours les credentials
        //  (on indique seulement qu'un secret a changé, jamais sa valeur).
        $secretFields = ['client_id', 'client_secret', 'subscription_key_primary', 'subscription_key_secondary'];
        $changed = array_keys($update);
        $credentialsChanged = array_values(array_intersect($changed, $secretFields));
        $props = ['fields' => array_values(array_diff($changed, $secretFields))];
        if ($credentialsChanged) {
            $props['credentials_changed'] = $credentialsChanged; // noms des champs uniquement
        }
        $this->audit->log('account.updated', $account, $account->label, $props);

        return back()->with('success', 'Compte EBP mis à jour.');
    }

    public function destroyAccount(EbpAccount $account): RedirectResponse
    {
        // Label figé AVANT suppression (le subject disparaît avec la ligne).
        $label = $account->label;
        $environment = $account->environment;
        $account->delete();

        $this->audit->log('account.deleted', null, $label, ['environment' => $environment]);

        return to_route('admin.comptes.index')->with('success', 'Compte EBP supprimé.');
    }

    public function syncFolders(EbpAccount $account, EbpFolderDiscovery $discovery): RedirectResponse
    {
        if ($account->status !== 'connected') {
            return back()->with('error', "Connectez d'abord le compte à EBP avant de synchroniser les dossiers.");
        }

        try {
            $count = $discovery->sync($account);
            $this->audit->log('account.folders_synced', $account, $account->label, ['count' => $count]);

            return back()->with('success', "{$count} dossier(s) synchronisé(s) depuis EBP.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Synchronisation échouée : '.$e->getMessage());
        }
    }

    /** Vide le cache de lecture d'un compte (tous ses dossiers). */
    public function clearAccountCache(EbpAccount $account, GatewayCache $cache): RedirectResponse
    {
        $cache->flushAccount($account);
        $this->events->cacheCleared($account);
        $this->audit->log('cache.cleared_account', $account, $account->label);

        return back()->with('success', "Cache de lecture du compte « {$account->label} » vidé.");
    }

    /** Vide tout le cache de lecture (tous les comptes). */
    public function clearAllCache(GatewayCache $cache): RedirectResponse
    {
        $cache->flushAll();

        // Purge globale : tracée dans le journal de chaque compte.
        EbpAccount::each(fn (EbpAccount $account) => $this->events->cacheCleared($account, global: true));
        $this->audit->log('cache.cleared_all', null, 'Tout le cache de lecture');

        return back()->with('success', 'Tout le cache de lecture a été vidé.');
    }
}
