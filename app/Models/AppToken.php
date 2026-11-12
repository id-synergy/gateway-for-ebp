<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppToken extends Model
{
    protected $fillable = [
        'name',
        'token_hash',
        'encrypted_token',
        'ebp_account_id',
        'default_folder_id',
        'is_active',
        'revoked_at',
        'last_used_at',
    ];

    protected $hidden = [
        'token_hash',
        'encrypted_token',
    ];

    protected function casts(): array
    {
        return [
            'encrypted_token' => 'encrypted',
            'is_active' => 'boolean',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /** Jeton en clair (déchiffré), ou null pour un jeton créé avant le stockage chiffré. */
    public function plainToken(): ?string
    {
        return $this->encrypted_token ?: null;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EbpAccount::class, 'ebp_account_id');
    }

    public function defaultFolder(): BelongsTo
    {
        return $this->belongsTo(EbpFolder::class, 'default_folder_id');
    }

    /** Règles d'accès (liste blanche de routes EBP), triées par position. */
    public function routeRules(): HasMany
    {
        return $this->hasMany(AppTokenRouteRule::class)->orderBy('position');
    }

    public function isUsable(): bool
    {
        return $this->is_active && $this->revoked_at === null;
    }

    /**
     * Le jeton est-il restreint à une liste blanche de routes ?
     *
     * Faux = défaut OUVERT (accès au périmètre global). Vrai = au moins une règle configurée,
     * l'accès proxy EBP est alors limité à l'union de ces règles.
     */
    public function isRouteRestricted(): bool
    {
        return $this->routeRules()->exists();
    }
}
