<?php

namespace Tests\Feature;

use App\Mail\GenericIncidentMail;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Alerts\AlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AlertServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): AlertService
    {
        return app(AlertService::class);
    }

    public function test_les_destinataires_sont_les_admins_abonnes_actifs(): void
    {
        $abonne = User::factory()->create(['is_active' => true]);
        $nonAbonne = User::factory()->create(['is_active' => true]);
        $inactif = User::factory()->create(['is_active' => false]);

        NotificationPreference::create(['user_id' => $abonne->id, 'alert_type' => 'generic_incident', 'enabled' => true]);
        NotificationPreference::create(['user_id' => $nonAbonne->id, 'alert_type' => 'generic_incident', 'enabled' => false]);
        NotificationPreference::create(['user_id' => $inactif->id, 'alert_type' => 'generic_incident', 'enabled' => true]);

        $recipients = $this->service()->resolveRecipients('generic_incident');

        $this->assertSame([$abonne->email], $recipients);
    }

    public function test_repli_sur_le_contact_par_defaut_si_aucun_abonne(): void
    {
        User::factory()->create(['is_active' => true, 'is_default_contact' => false]);
        $defaut = User::factory()->create(['is_active' => true, 'is_default_contact' => true]);

        $recipients = $this->service()->resolveRecipients('token_refresh_failed');

        $this->assertSame([$defaut->email], $recipients);
    }

    public function test_repli_ultime_sur_ladresse_de_secours(): void
    {
        config(['ebp.alerts.fallback_email' => 'secours@example.test']);

        $recipients = $this->service()->resolveRecipients('token_refresh_failed');

        $this->assertSame(['secours@example.test'], $recipients);
    }

    public function test_send_envoie_le_mail_et_journalise(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['is_active' => true]);
        NotificationPreference::create(['user_id' => $admin->id, 'alert_type' => 'generic_incident', 'enabled' => true]);

        $this->service()->send(
            'generic_incident',
            new GenericIncidentMail('Titre', 'test', 'objet', 'detail', 'agir'),
            'ebp_account:1',
            ['k' => 'v'],
        );

        Mail::assertSent(GenericIncidentMail::class, fn ($mail) => $mail->hasTo($admin->email));
        $this->assertDatabaseHas('alert_logs', ['alert_type' => 'generic_incident', 'subject_ref' => 'ebp_account:1']);
    }

    public function test_aucun_envoi_si_aucun_destinataire(): void
    {
        Mail::fake();
        config(['ebp.alerts.fallback_email' => null]);

        $this->service()->send('generic_incident', new GenericIncidentMail('T', 't', 'o', 'd', 'a'));

        Mail::assertNothingSent();
        $this->assertDatabaseCount('alert_logs', 0);
    }
}
