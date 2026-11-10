<?php

namespace App\Services\Apps;

use App\Models\AppToken;
use App\Models\EbpAccount;
use App\Models\EbpFolder;
use Illuminate\Support\Str;

/**
 * Création et vérification des jetons d'apps clientes.
 *
 * Le jeton en clair n'est montré qu'une fois (à la création) ; seul son hash est stocké.
 * Format : "gw_<40 chars>". On hashe avec SHA-256 (lookup direct en base, contrairement à bcrypt).
 */
class AppTokenService
{
    public const PREFIX = 'gw_';

    /**
     * Crée un jeton pour une app. Retourne [AppToken, string $plainToken].
     *
     * @return array{0: AppToken, 1: string}
     */
    public function create(string $name, EbpAccount $account, ?EbpFolder $defaultFolder = null): array
    {
        $plain = self::PREFIX.Str::random(40);

        $token = AppToken::create([
            'name' => $name,
            'token_hash' => $this->hash($plain),
            'ebp_account_id' => $account->id,
            'default_folder_id' => $defaultFolder?->id,
            'is_active' => true,
        ]);

        return [$token, $plain];
    }

    /** Retrouve un jeton utilisable à partir de sa valeur en clair, ou null. */
    public function resolve(?string $plain): ?AppToken
    {
        if (! $plain) {
            return null;
        }

        $token = AppToken::where('token_hash', $this->hash($plain))->first();

        return $token && $token->isUsable() ? $token : null;
    }

    public function revoke(AppToken $token): void
    {
        $token->update(['is_active' => false, 'revoked_at' => now()]);
    }

    /**
     * Régénère la clé d'un jeton ACTIF : nouvelle valeur, même app/compte/dossier.
     * L'ancienne clé cesse immédiatement de fonctionner (irréversible).
     *
     * Ne réactive PAS un jeton révoqué : une révocation est volontaire et définitive,
     * on recrée un jeton si l'app doit retrouver l'accès.
     *
     * @return string Le nouveau jeton en clair (à montrer une seule fois).
     */
    public function regenerate(AppToken $token): string
    {
        $plain = self::PREFIX.Str::random(40);

        $token->update([
            'token_hash' => $this->hash($plain),
            'last_used_at' => null,
        ]);

        return $plain;
    }

    public function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
