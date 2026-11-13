<?php

namespace Tests\Feature;

use App\Models\AdminAuthEvent;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Tests\TestCase;

/**
 * Captation des événements d'authentification des admins via les listeners
 * branchés sur les events Laravel/Fortify (cf. App\Listeners\RecordAdminAuthEvent).
 */
class AdminAuthEventLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_login_est_journalise_avec_user_ip_et_user_agent(): void
    {
        $user = User::factory()->create();

        // On remplace la requête courante par une requête dont on maîtrise l'IP et le
        // user-agent : le listener les capte via la façade Request.
        $request = Request::create('/login', 'POST', server: [
            'REMOTE_ADDR' => '203.0.113.42',
            'HTTP_USER_AGENT' => 'PHPUnit/Test',
        ]);
        $this->app->instance('request', $request);

        event(new Login('web', $user, false));

        $event = AdminAuthEvent::where('event', AdminAuthEvent::EVENT_LOGIN)->firstOrFail();
        $this->assertSame($user->id, $event->user_id);
        $this->assertSame('203.0.113.42', $event->ip);
        $this->assertSame('PHPUnit/Test', $event->user_agent);
        $this->assertNotNull($event->occurred_at);
    }

    public function test_un_logout_est_journalise(): void
    {
        $user = User::factory()->create();

        event(new Logout('web', $user));

        $this->assertDatabaseHas('admin_auth_events', [
            'user_id' => $user->id,
            'event' => AdminAuthEvent::EVENT_LOGOUT,
        ]);
    }

    public function test_un_echec_journalise_l_email_saisi_et_jamais_le_mot_de_passe(): void
    {
        event(new Failed('web', null, [
            'email' => 'intrus@exemple.fr',
            'password' => 'SuperSecret123',
        ]));

        $event = AdminAuthEvent::where('event', AdminAuthEvent::EVENT_FAILED)->firstOrFail();
        $this->assertSame('intrus@exemple.fr', $event->email);
        $this->assertNull($event->user_id);

        // Aucune colonne ne doit contenir le secret.
        foreach ($event->getAttributes() as $value) {
            $this->assertStringNotContainsString('SuperSecret123', (string) $value);
        }
    }

    public function test_le_passage_2fa_reussi_est_journalise(): void
    {
        $user = User::factory()->create();

        event(new ValidTwoFactorAuthenticationCodeProvided($user));

        $this->assertDatabaseHas('admin_auth_events', [
            'user_id' => $user->id,
            'event' => AdminAuthEvent::EVENT_TWO_FACTOR_PASSED,
        ]);
    }

    public function test_un_echec_2fa_est_journalise(): void
    {
        $user = User::factory()->create();

        event(new TwoFactorAuthenticationFailed($user));

        $this->assertDatabaseHas('admin_auth_events', [
            'user_id' => $user->id,
            'event' => AdminAuthEvent::EVENT_TWO_FACTOR_FAILED,
        ]);
    }
}
