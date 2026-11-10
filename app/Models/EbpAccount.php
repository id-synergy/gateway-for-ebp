<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EbpAccount extends Model
{
    /** @use HasFactory<\Database\Factories\EbpAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'label',
        'client_id',
        'client_secret',
        'subscription_key_primary',
        'subscription_key_secondary',
        'active_subscription_key',
        'environment',
        'status',
        'is_active',
        'cache_enabled',
        'cache_ttl',
    ];

    // Secrets chiffrés au repos (clé APP_KEY). Jamais exposés tels quels.
    protected $hidden = [
        'client_id',
        'client_secret',
        'subscription_key_primary',
        'subscription_key_secondary',
    ];

    protected function casts(): array
    {
        return [
            'client_id' => 'encrypted',
            'client_secret' => 'encrypted',
            'subscription_key_primary' => 'encrypted',
            'subscription_key_secondary' => 'encrypted',
            'is_active' => 'boolean',
            'cache_enabled' => 'boolean',
            'cache_ttl' => 'integer',
        ];
    }

    /** Le cache des lectures est-il actif pour ce compte ? */
    public function cacheEnabled(): bool
    {
        return (bool) $this->cache_enabled;
    }

    /** TTL de cache (secondes) du compte, avec repli sur la config globale. */
    public function cacheTtl(): int
    {
        return $this->cache_ttl ?? (int) config('ebp.gateway.cache.default_ttl', 0);
    }

    public function token(): HasOne
    {
        return $this->hasOne(EbpToken::class);
    }

    public function folders(): HasMany
    {
        return $this->hasMany(EbpFolder::class);
    }

    public function events(): HasMany
    {
        // Tri secondaire par id : ordre stable quand plusieurs événements partagent occurred_at
        // (indispensable pour la pagination par curseur du journal d'activité).
        return $this->hasMany(AccountEvent::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }

    public function appTokens(): HasMany
    {
        return $this->hasMany(AppToken::class);
    }

    /** Clé d'abonnement active (primaire ou secondaire). */
    public function activeSubscriptionKey(): ?string
    {
        return $this->active_subscription_key === 'secondary'
            ? $this->subscription_key_secondary
            : $this->subscription_key_primary;
    }

    /** URL de base de l'API EBP selon l'environnement du compte (production / préproduction / démo). */
    public function apiBaseUrl(): string
    {
        if ($this->usesFakeEbp()) {
            return url('/fake-ebp');
        }

        $urls = config('ebp.api_base_urls');

        return $urls[$this->environment] ?? $urls['production'];
    }

    /** URL du endpoint OAuth token : faux serveur en démo (URL courante), EBP sinon. */
    public function tokenUrl(): string
    {
        return $this->usesFakeEbp()
            ? url('/fake-ebp/connect/token')
            : config('ebp.token_urls.default');
    }

    /** URL de redirection OAuth : callback local en démo, URL whitelistée EBP sinon. */
    public function redirectUri(): string
    {
        return $this->usesFakeEbp()
            ? url('/oauth/ebp/callback')
            : config('ebp.redirect_uri');
    }

    public function isDemo(): bool
    {
        return $this->environment === 'demo';
    }

    /**
     * Ce compte passe-t-il par le faux serveur EBP (routes fake-ebp) ?
     *
     * Vrai si le compte est en environnement « demo », OU si le faux portail global
     * est activé (config ebp.fake_portal — dev/test uniquement, jamais en production).
     */
    public function usesFakeEbp(): bool
    {
        return $this->isDemo() || (bool) config('ebp.fake_portal');
    }
}
