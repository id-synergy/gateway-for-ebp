<?php

namespace Tests\Feature;

use App\Mail\GenericIncidentMail;
use App\Models\AdminAuthEvent;
use App\Models\AlertLog;
use App\Models\BlockedRequestEvent;
use App\Models\RequestLog;
use App\Models\User;
use App\Services\Alerts\AlertType;
use App\Services\Alerts\AnomalyDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AnomalyDetectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seuils déterministes pour les tests.
        config([
            'ebp.anomaly.window_minutes' => 15,
            'ebp.anomaly.cooldown_minutes' => 15,
            'ebp.anomaly.rate_limit_spike.threshold' => 20,
            'ebp.anomaly.blocked_ip.global_threshold' => 50,
            'ebp.anomaly.blocked_ip.per_ip_threshold' => 20,
            'ebp.anomaly.login_failures.per_ip_threshold' => 5,
            'ebp.anomaly.login_failures.per_email_threshold' => 5,
        ]);
    }

    private function detector(): AnomalyDetector
    {
        return app(AnomalyDetector::class);
    }

    /** Un admin abonné à tous les types, pour avoir un destinataire. */
    private function adminAbonne(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        foreach (AlertType::configurable() as $type) {
            $admin->notificationPreferences()->create(['alert_type' => $type, 'enabled' => true]);
        }

        return $admin;
    }

    private function makeRequestLogs(int $count, int $status, string $when = 'now'): void
    {
        $at = $when === 'old' ? now()->subMinutes(60) : now();
        for ($i = 0; $i < $count; $i++) {
            RequestLog::create([
                'occurred_at' => $at,
                'ip' => '203.0.113.10',
                'method' => 'GET',
                'ebp_endpoint' => 'gescom/api/v1/Items',
                'http_status' => $status,
                'success' => $status < 400,
            ]);
        }
    }

    public function test_un_pic_de_429_au_dessus_du_seuil_declenche_lalerte(): void
    {
        Mail::fake();
        $this->adminAbonne();

        $this->makeRequestLogs(25, 429);

        $emitted = $this->detector()->run();

        $this->assertContains(AlertType::RATE_LIMIT_SPIKE, $emitted);
        Mail::assertSent(GenericIncidentMail::class, 1);
        $this->assertDatabaseHas('alert_logs', ['alert_type' => AlertType::RATE_LIMIT_SPIKE]);
    }

    public function test_un_pic_de_429_sous_le_seuil_ne_declenche_pas(): void
    {
        Mail::fake();
        $this->adminAbonne();

        $this->makeRequestLogs(10, 429);

        $emitted = $this->detector()->run();

        $this->assertNotContains(AlertType::RATE_LIMIT_SPIKE, $emitted);
        Mail::assertNothingSent();
    }

    public function test_les_429_hors_fenetre_sont_ignores(): void
    {
        Mail::fake();
        $this->adminAbonne();

        $this->makeRequestLogs(30, 429, 'old');     // hors fenêtre
        $this->makeRequestLogs(5, 429);             // dans la fenêtre, sous le seuil

        $emitted = $this->detector()->run();

        $this->assertNotContains(AlertType::RATE_LIMIT_SPIKE, $emitted);
    }

    public function test_les_200_ne_comptent_pas_comme_429(): void
    {
        Mail::fake();
        $this->adminAbonne();

        $this->makeRequestLogs(100, 200);

        $emitted = $this->detector()->run();

        $this->assertNotContains(AlertType::RATE_LIMIT_SPIKE, $emitted);
    }

    public function test_echecs_login_repetes_depuis_une_ip_declenchent_lalerte(): void
    {
        Mail::fake();
        $this->adminAbonne();

        AdminAuthEvent::factory()->failed()->count(5)->create([
            'ip' => '198.51.100.7',
            'email' => null,
            'occurred_at' => now(),
        ]);

        $emitted = $this->detector()->run();

        $this->assertContains(AlertType::LOGIN_FAILURES, $emitted);
        $this->assertDatabaseHas('alert_logs', ['alert_type' => AlertType::LOGIN_FAILURES]);
    }

    public function test_echecs_login_repetes_sur_un_meme_email_declenchent_lalerte(): void
    {
        Mail::fake();
        $this->adminAbonne();

        AdminAuthEvent::factory()->failed()->count(5)->create([
            'ip' => null,
            'email' => 'cible@example.test',
            'occurred_at' => now(),
        ]);

        $emitted = $this->detector()->run();

        $this->assertContains(AlertType::LOGIN_FAILURES, $emitted);
    }

    public function test_echecs_login_sous_le_seuil_ne_declenchent_pas(): void
    {
        Mail::fake();
        $this->adminAbonne();

        AdminAuthEvent::factory()->failed()->count(3)->create([
            'ip' => '198.51.100.7',
            'email' => null,
            'occurred_at' => now(),
        ]);

        $emitted = $this->detector()->run();

        $this->assertNotContains(AlertType::LOGIN_FAILURES, $emitted);
        Mail::assertNothingSent();
    }

    public function test_une_ip_bloquee_insistante_declenche_lalerte(): void
    {
        Mail::fake();
        $this->adminAbonne();

        BlockedRequestEvent::factory()->count(20)->create([
            'ip' => '192.0.2.44',
            'occurred_at' => now(),
        ]);

        $emitted = $this->detector()->run();

        $this->assertContains(AlertType::BLOCKED_IP_HAMMERING, $emitted);
    }

    public function test_lanti_spam_empeche_une_2e_alerte_identique_dans_la_fenetre(): void
    {
        Mail::fake();
        $this->adminAbonne();

        $this->makeRequestLogs(25, 429);

        // 1re passe : alerte émise.
        $first = $this->detector()->run();
        $this->assertContains(AlertType::RATE_LIMIT_SPIKE, $first);

        // 2e passe immédiate : même signal, étouffé par le cooldown.
        $second = $this->detector()->run();
        $this->assertNotContains(AlertType::RATE_LIMIT_SPIKE, $second);

        // Un seul envoi au total.
        Mail::assertSent(GenericIncidentMail::class, 1);
        $this->assertSame(1, AlertLog::where('alert_type', AlertType::RATE_LIMIT_SPIKE)->count());
    }

    public function test_lanti_spam_re_alerte_apres_expiration_du_cooldown(): void
    {
        Mail::fake();
        $this->adminAbonne();

        $this->makeRequestLogs(25, 429);
        $this->detector()->run();

        // Après expiration du cooldown, le même signal persistant ré-alerte.
        $this->travel(16)->minutes();
        // Re-crée les 429 dans la nouvelle fenêtre glissante.
        $this->makeRequestLogs(25, 429);

        $again = $this->detector()->run();
        $this->assertContains(AlertType::RATE_LIMIT_SPIKE, $again);
        Mail::assertSent(GenericIncidentMail::class, 2);
    }

    public function test_un_admin_qui_a_desactive_le_type_ne_recoit_pas_lalerte(): void
    {
        Mail::fake();

        // Admin avec le type EXPLICITEMENT désactivé, aucun autre destinataire possible.
        $admin = User::factory()->create(['is_active' => true, 'is_default_contact' => false]);
        $admin->notificationPreferences()->create([
            'alert_type' => AlertType::RATE_LIMIT_SPIKE,
            'enabled' => false,
        ]);
        config(['ebp.alerts.fallback_email' => null]);

        $this->makeRequestLogs(25, 429);

        $emitted = $this->detector()->run();

        // Le signal est détecté (type listé) mais aucun mail ne part faute de destinataire.
        $this->assertContains(AlertType::RATE_LIMIT_SPIKE, $emitted);
        Mail::assertNothingSent();
    }

    public function test_aucun_secret_dans_le_contenu_de_lalerte(): void
    {
        Mail::fake();
        $this->adminAbonne();

        $this->makeRequestLogs(25, 429);
        $this->detector()->run();

        $log = AlertLog::where('alert_type', AlertType::RATE_LIMIT_SPIKE)->firstOrFail();
        $encoded = json_encode([$log->context, $log->subject_ref]);

        // Le contexte journalisé ne contient que le signal (pas de secret).
        $this->assertStringNotContainsString('secret', strtolower($encoded));
        $this->assertStringNotContainsString('password', strtolower($encoded));
        $this->assertStringNotContainsString('subscription', strtolower($encoded));
    }

    public function test_les_nouveaux_types_sont_configurables_en_preference(): void
    {
        $types = AlertType::configurable();

        $this->assertContains(AlertType::RATE_LIMIT_SPIKE, $types);
        $this->assertContains(AlertType::BLOCKED_IP_HAMMERING, $types);
        $this->assertContains(AlertType::LOGIN_FAILURES, $types);

        foreach ($types as $type) {
            $this->assertNotSame($type, AlertType::label($type), "Libellé manquant pour {$type}");
        }
    }
}
