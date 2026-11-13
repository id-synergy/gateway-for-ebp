<?php

namespace App\Services\Alerts;

use App\Mail\GenericIncidentMail;
use App\Models\AdminAuthEvent;
use App\Models\BlockedRequestEvent;
use App\Models\RequestLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Détection d'activité anormale (sécurité / charge) sur une fenêtre glissante.
 *
 * Évalue trois règles sur les journaux existants et déclenche une alerte par le
 * mécanisme standard (AlertService -> notification e-mail aux admins abonnés) :
 *
 *   1. rate_limit_spike     — pic de 429 relayés par EBP (quota amont) dans request_logs.
 *   2. blocked_ip_hammering — rafale de 403 ip_blocked (blocked_request_events), globale
 *                             ou concentrée sur une IP.
 *   3. login_failures       — échecs de login admin (admin_auth_events event=failed),
 *                             concentrés sur une IP ou sur un e-mail visé.
 *
 * ANTI-SPAM (dédup) : chaque signal distinct (type + clé, ex. l'IP concernée) ne
 * ré-alerte pas avant l'expiration d'un délai de silence (cooldown), mémorisé en cache.
 * Deux exécutions rapprochées de la commande ne produisent donc pas d'alertes en boucle.
 * Le cache utilisé est le store par défaut de l'application (persistant en prod) ;
 * la clé encode le type et la clé du signal, jamais une valeur sensible.
 *
 * Aucun secret n'entre dans le contenu des alertes : au plus une IP et un e-mail,
 * données déjà présentes dans les journaux.
 */
class AnomalyDetector
{
    private const CACHE_PREFIX = 'anomaly:cooldown:';

    public function __construct(private AlertService $alerts) {}

    /**
     * Évalue toutes les règles. Retourne la liste des types d'alerte effectivement émis
     * (hors signaux étouffés par l'anti-spam), pour l'affichage en console.
     *
     * @return list<string>
     */
    public function run(?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();
        $windowStart = $now->copy()->subMinutes($this->windowMinutes());

        $emitted = [];

        $emitted = array_merge($emitted, $this->checkRateLimitSpike($windowStart));
        $emitted = array_merge($emitted, $this->checkBlockedIpHammering($windowStart));
        $emitted = array_merge($emitted, $this->checkLoginFailures($windowStart));

        return $emitted;
    }

    /**
     * Règle 1 — pic de 429 (quota/abus) dans request_logs sur la fenêtre.
     *
     * @return list<string>
     */
    private function checkRateLimitSpike(Carbon $windowStart): array
    {
        $threshold = (int) config('ebp.anomaly.rate_limit_spike.threshold');
        if ($threshold <= 0) {
            return [];
        }

        $count = RequestLog::where('occurred_at', '>=', $windowStart)
            ->where('http_status', 429)
            ->count();

        if ($count < $threshold) {
            return [];
        }

        return $this->emit(
            AlertType::RATE_LIMIT_SPIKE,
            'global',
            title: 'Pic de réponses 429 (quota/abus)',
            subjectLabel: 'Trafic EBP',
            detail: "{$count} réponses 429 relayées par EBP sur les {$this->windowMinutes()} dernières minutes "
                ."(seuil : {$threshold}). Quota amont EBP possiblement saturé, ou app cliente en boucle.",
            actionExpected: 'Vérifier la consommation de quota et l\'activité des apps dans Supervision / Logs.',
        );
    }

    /**
     * Règle 2 — rafale de 403 ip_blocked, globale ou concentrée sur une IP.
     *
     * @return list<string>
     */
    private function checkBlockedIpHammering(Carbon $windowStart): array
    {
        $emitted = [];

        $perIpThreshold = (int) config('ebp.anomaly.blocked_ip.per_ip_threshold');
        if ($perIpThreshold > 0) {
            $topIps = BlockedRequestEvent::where('occurred_at', '>=', $windowStart)
                ->whereNotNull('ip')
                ->selectRaw('ip, COUNT(*) as hits')
                ->groupBy('ip')
                ->havingRaw('COUNT(*) >= ?', [$perIpThreshold])
                ->pluck('hits', 'ip');

            foreach ($topIps as $ip => $hits) {
                $emitted = array_merge($emitted, $this->emit(
                    AlertType::BLOCKED_IP_HAMMERING,
                    'ip:'.$ip,
                    title: 'IP bloquée insistante',
                    subjectLabel: "IP {$ip}",
                    detail: "L'IP bloquée {$ip} a été refusée {$hits} fois (403) sur les "
                        ."{$this->windowMinutes()} dernières minutes (seuil par IP : {$perIpThreshold}).",
                    actionExpected: 'Vérifier le blocage de cette IP ; envisager un blocage amont (pare-feu) si l\'insistance persiste.',
                ));
            }
        }

        $globalThreshold = (int) config('ebp.anomaly.blocked_ip.global_threshold');
        if ($globalThreshold > 0) {
            $count = BlockedRequestEvent::where('occurred_at', '>=', $windowStart)->count();

            if ($count >= $globalThreshold) {
                $emitted = array_merge($emitted, $this->emit(
                    AlertType::BLOCKED_IP_HAMMERING,
                    'global',
                    title: 'Rafale globale d\'IP bloquées',
                    subjectLabel: 'IP bloquées (global)',
                    detail: "{$count} requêtes d'IP bloquées refusées (403) sur les {$this->windowMinutes()} "
                        ."dernières minutes (seuil global : {$globalThreshold}).",
                    actionExpected: 'Vérifier s\'il s\'agit d\'un scan distribué ; renforcer le filtrage amont si besoin.',
                ));
            }
        }

        return $emitted;
    }

    /**
     * Règle 3 — échecs de login admin concentrés sur une IP ou un e-mail visé.
     *
     * @return list<string>
     */
    private function checkLoginFailures(Carbon $windowStart): array
    {
        $emitted = [];

        $base = AdminAuthEvent::where('event', AdminAuthEvent::EVENT_FAILED)
            ->where('occurred_at', '>=', $windowStart);

        $perIpThreshold = (int) config('ebp.anomaly.login_failures.per_ip_threshold');
        if ($perIpThreshold > 0) {
            $byIp = (clone $base)
                ->whereNotNull('ip')
                ->selectRaw('ip, COUNT(*) as hits')
                ->groupBy('ip')
                ->havingRaw('COUNT(*) >= ?', [$perIpThreshold])
                ->pluck('hits', 'ip');

            foreach ($byIp as $ip => $hits) {
                $emitted = array_merge($emitted, $this->emit(
                    AlertType::LOGIN_FAILURES,
                    'ip:'.$ip,
                    title: 'Tentatives de connexion répétées (IP)',
                    subjectLabel: "IP {$ip}",
                    detail: "{$hits} échecs de connexion admin depuis l'IP {$ip} sur les "
                        ."{$this->windowMinutes()} dernières minutes (seuil : {$perIpThreshold}).",
                    actionExpected: 'Vérifier l\'origine ; bloquer l\'IP depuis l\'écran IP si l\'activité est malveillante.',
                ));
            }
        }

        $perEmailThreshold = (int) config('ebp.anomaly.login_failures.per_email_threshold');
        if ($perEmailThreshold > 0) {
            $byEmail = (clone $base)
                ->whereNotNull('email')
                ->selectRaw('email, COUNT(*) as hits')
                ->groupBy('email')
                ->havingRaw('COUNT(*) >= ?', [$perEmailThreshold])
                ->pluck('hits', 'email');

            foreach ($byEmail as $email => $hits) {
                $emitted = array_merge($emitted, $this->emit(
                    AlertType::LOGIN_FAILURES,
                    'email:'.$email,
                    title: 'Tentatives de connexion répétées (compte visé)',
                    subjectLabel: "Compte {$email}",
                    detail: "{$hits} échecs de connexion admin visant {$email} sur les "
                        ."{$this->windowMinutes()} dernières minutes (seuil : {$perEmailThreshold}).",
                    actionExpected: 'Prévenir le titulaire du compte ; vérifier la robustesse du mot de passe et la 2FA.',
                ));
            }
        }

        return $emitted;
    }

    /**
     * Émet une alerte si le signal (type + clé) n'est pas en période de silence.
     * Pose le verrou de cooldown après émission.
     *
     * @return list<string> [$alertType] si émise, [] si étouffée par l'anti-spam.
     */
    private function emit(
        string $alertType,
        string $signalKey,
        string $title,
        string $subjectLabel,
        string $detail,
        string $actionExpected,
    ): array {
        $cacheKey = self::CACHE_PREFIX.$alertType.':'.$signalKey;

        if (Cache::has($cacheKey)) {
            return [];
        }

        $this->alerts->send(
            $alertType,
            new GenericIncidentMail(
                title: $title,
                type: AlertType::label($alertType),
                subjectLabel: $subjectLabel,
                detail: $detail,
                actionExpected: $actionExpected,
            ),
            subjectRef: $subjectLabel,
            context: ['signal' => $signalKey],
        );

        Cache::put($cacheKey, true, now()->addMinutes($this->cooldownMinutes()));

        return [$alertType];
    }

    private function windowMinutes(): int
    {
        return max(1, (int) config('ebp.anomaly.window_minutes'));
    }

    private function cooldownMinutes(): int
    {
        return max(1, (int) config('ebp.anomaly.cooldown_minutes'));
    }
}
