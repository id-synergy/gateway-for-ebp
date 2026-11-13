<?php

namespace App\Services\Alerts;

/**
 * Registre central des types d'alerte de la gateway.
 *
 * Source unique de vérité : la clé technique (stockée dans notification_preferences.alert_type
 * et alert_logs.alert_type) et son libellé lisible. Toute alerte passe par AlertService::send()
 * avec l'un de ces types. Les écrans de supervision et de préférences admin lisent cette liste,
 * de sorte qu'ajouter un type ici l'expose partout (affichage + abonnement par admin).
 */
class AlertType
{
    // --- Exploitation (incidents techniques) ---
    public const TOKEN_REFRESH_FAILED = 'token_refresh_failed';

    public const LOGS_PURGE_FAILED = 'logs_purge_failed';

    public const ACCOUNT_EXPIRING = 'account_expiring';

    public const ACCOUNT_CONNECTED = 'account_connected';

    public const GENERIC_INCIDENT = 'generic_incident';

    // --- Détection d'activité anormale (sécurité / charge) ---
    public const RATE_LIMIT_SPIKE = 'rate_limit_spike';

    public const BLOCKED_IP_HAMMERING = 'blocked_ip_hammering';

    public const LOGIN_FAILURES = 'login_failures';

    /**
     * Libellés lisibles par clé technique, dans l'ordre d'affichage.
     *
     * @return array<string,string>
     */
    public static function labels(): array
    {
        return [
            self::TOKEN_REFRESH_FAILED => 'Échec renouvellement token',
            self::LOGS_PURGE_FAILED => 'Échec purge des logs',
            self::ACCOUNT_EXPIRING => 'Compte bientôt expiré',
            self::ACCOUNT_CONNECTED => 'Compte EBP connecté',
            self::GENERIC_INCIDENT => 'Incident générique',
            self::RATE_LIMIT_SPIKE => 'Pic de 429 (quota/abus)',
            self::BLOCKED_IP_HAMMERING => 'IP bloquée insistante',
            self::LOGIN_FAILURES => 'Tentatives de connexion',
        ];
    }

    /**
     * Types proposés à l'abonnement par admin (écran Admins).
     *
     * @return list<string>
     */
    public static function configurable(): array
    {
        return array_keys(self::labels());
    }

    public static function label(string $type): string
    {
        return self::labels()[$type] ?? $type;
    }
}
