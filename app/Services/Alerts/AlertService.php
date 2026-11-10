<?php

namespace App\Services\Alerts;

use App\Models\AlertLog;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Envoie les alertes d'exploitation aux admins concernés.
 *
 * Destinataires d'un type d'alerte :
 *   1. admins actifs abonnés à ce type (notification_preferences.enabled = true) ;
 *   2. sinon, admins actifs marqués "contact par défaut" ;
 *   3. sinon, adresse de repli en config (ebp.alerts.fallback_email).
 * Chaque envoi est journalisé dans alert_logs.
 */
class AlertService
{
    /**
     * @param  array<string,mixed>  $context  variables passées au mail (sans secret)
     */
    public function send(string $alertType, Mailable $mail, ?string $subjectRef = null, array $context = []): void
    {
        $recipients = $this->resolveRecipients($alertType);

        if (empty($recipients)) {
            return;
        }

        Mail::to($recipients)->send($mail);

        AlertLog::create([
            'alert_type' => $alertType,
            'subject_ref' => $subjectRef,
            'recipients' => $recipients,
            'sent_at' => Carbon::now(),
            'context' => $context,
        ]);
    }

    /**
     * Résout la liste d'e-mails destinataires pour un type d'alerte.
     *
     * @return list<string>
     */
    public function resolveRecipients(string $alertType): array
    {
        // 1. Admins actifs abonnés à ce type.
        $subscribedIds = NotificationPreference::where('alert_type', $alertType)
            ->where('enabled', true)
            ->pluck('user_id');

        $emails = User::where('is_active', true)
            ->whereIn('id', $subscribedIds)
            ->pluck('email')
            ->all();

        if (! empty($emails)) {
            return array_values(array_unique($emails));
        }

        // 2. Repli : admins "contact par défaut".
        $defaults = User::where('is_active', true)
            ->where('is_default_contact', true)
            ->pluck('email')
            ->all();

        if (! empty($defaults)) {
            return array_values(array_unique($defaults));
        }

        // 3. Repli ultime : adresse de secours en config.
        $fallback = config('ebp.alerts.fallback_email');

        return $fallback ? [$fallback] : [];
    }
}
