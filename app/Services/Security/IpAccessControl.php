<?php

namespace App\Services\Security;

use App\Models\IpEntry;
use Illuminate\Support\Facades\Cache;

/**
 * Point d'accès unique au statut d'une IP (ip_entries), avec cache court.
 *
 * Consommé par :
 *  - le rate limiter 'gateway' (IP allowed -> illimité) ;
 *  - le middleware BlockBannedIps (IP blocked -> 403).
 *
 * Le cache (défaut 60 s) évite une requête DB à chaque appel entrant. Il est
 * invalidé à l'écriture via forget().
 */
class IpAccessControl
{
    /** Durée de vie du cache de statut, en secondes. */
    public const CACHE_TTL = 60;

    private const CACHE_PREFIX = 'ip_status:';

    /** Boucles locales : jamais bloquées, garde-fou anti-verrouillage. */
    private const LOOPBACK = ['127.0.0.1', '::1'];

    /**
     * Statut effectif d'une IP : 'allowed', 'blocked' ou 'neutral'.
     * Une IP absente de ip_entries est 'neutral'. En cas d'indisponibilité DB,
     * on renvoie 'neutral' (ni exemption de quota, ni blocage) pour ne pas
     * verrouiller la gateway sur une panne de base.
     */
    public function statusFor(?string $ip): string
    {
        if ($ip === null || $ip === '') {
            return IpEntry::STATUS_NEUTRAL;
        }

        return Cache::remember(self::CACHE_PREFIX.$ip, self::CACHE_TTL, function () use ($ip) {
            try {
                return IpEntry::where('ip', $ip)->value('status') ?? IpEntry::STATUS_NEUTRAL;
            } catch (\Throwable) {
                return IpEntry::STATUS_NEUTRAL;
            }
        });
    }

    public function isAllowed(?string $ip): bool
    {
        return $this->statusFor($ip) === IpEntry::STATUS_ALLOWED;
    }

    /**
     * Une IP est bloquée si son statut est 'blocked' ET qu'elle n'est pas une
     * boucle locale (garde-fou : on ne se verrouille jamais en local).
     */
    public function isBlocked(?string $ip): bool
    {
        if ($ip === null || in_array($ip, self::LOOPBACK, true)) {
            return false;
        }

        return $this->statusFor($ip) === IpEntry::STATUS_BLOCKED;
    }

    /** Invalide le cache d'une IP (à appeler après toute écriture sur son entrée). */
    public function forget(?string $ip): void
    {
        if ($ip !== null && $ip !== '') {
            Cache::forget(self::CACHE_PREFIX.$ip);
        }
    }
}
