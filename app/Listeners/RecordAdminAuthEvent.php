<?php

namespace App\Listeners;

use App\Models\AdminAuthEvent;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Request;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

/**
 * Journalise les événements d'authentification des admins du back-office
 * dans la table admin_auth_events (voir App\Models\AdminAuthEvent).
 *
 * Branché sur les events Laravel/Fortify (cf. AppServiceProvider::boot) :
 *  - Illuminate\Auth\Events\Login                               -> login
 *  - Illuminate\Auth\Events\Logout                              -> logout
 *  - Illuminate\Auth\Events\Failed                              -> failed (e-mail saisi, jamais le mot de passe)
 *  - Fortify\ValidTwoFactorAuthenticationCodeProvided           -> two_factor_passed
 *  - Fortify\TwoFactorAuthenticationFailed                      -> two_factor_failed
 *
 * Les secrets ne sont JAMAIS stockés : pour un échec, on ne retient que l'e-mail
 * saisi (credentials['email']), jamais credentials['password'].
 */
class RecordAdminAuthEvent
{
    public function handleLogin(Login $event): void
    {
        $this->record(AdminAuthEvent::EVENT_LOGIN, $event->user->getKey(), $this->emailOf($event->user));
    }

    public function handleLogout(Logout $event): void
    {
        // Logout « explicite » (bouton Déconnexion). $event->user peut être null
        // si la session était déjà vidée ; dans ce cas on ne journalise rien d'utile.
        if ($event->user === null) {
            return;
        }

        $this->record(AdminAuthEvent::EVENT_LOGOUT, $event->user->getKey(), $this->emailOf($event->user));
    }

    public function handleFailed(Failed $event): void
    {
        // Un échec peut viser un user connu (mauvais mot de passe) ou un e-mail inconnu.
        $email = $event->credentials['email'] ?? null;

        $this->record(AdminAuthEvent::EVENT_FAILED, $event->user?->getKey(), $email);
    }

    public function handleTwoFactorPassed(ValidTwoFactorAuthenticationCodeProvided $event): void
    {
        $this->record(AdminAuthEvent::EVENT_TWO_FACTOR_PASSED, $event->user->getKey(), $this->emailOf($event->user));
    }

    public function handleTwoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        $this->record(AdminAuthEvent::EVENT_TWO_FACTOR_FAILED, $event->user->getKey(), $this->emailOf($event->user));
    }

    /**
     * Enregistre un événement en captant l'IP et le user-agent du moment.
     */
    protected function record(string $event, ?int $userId, ?string $email): void
    {
        AdminAuthEvent::create([
            'user_id' => $userId,
            'email' => $email,
            'event' => $event,
            'ip' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'occurred_at' => now(),
        ]);
    }

    protected function emailOf(mixed $user): ?string
    {
        return is_object($user) && isset($user->email) ? $user->email : null;
    }
}
