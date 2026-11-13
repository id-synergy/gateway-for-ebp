<?php

namespace Tests\Feature;

use App\Mail\GenericIncidentMail;
use App\Models\RequestLog;
use App\Models\User;
use App\Services\Alerts\AlertType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DetectAnomaliesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_commande_sexecute_et_emet_une_alerte_sur_pic_de_429(): void
    {
        Mail::fake();
        config(['ebp.anomaly.rate_limit_spike.threshold' => 20]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->notificationPreferences()->create([
            'alert_type' => AlertType::RATE_LIMIT_SPIKE,
            'enabled' => true,
        ]);

        for ($i = 0; $i < 25; $i++) {
            RequestLog::create([
                'occurred_at' => now(),
                'ip' => '203.0.113.10',
                'http_status' => 429,
                'success' => false,
            ]);
        }

        $this->artisan('gateway:detect-anomalies')
            ->assertSuccessful();

        Mail::assertSent(GenericIncidentMail::class, 1);
    }

    public function test_la_commande_reussit_sans_anomalie(): void
    {
        Mail::fake();

        $this->artisan('gateway:detect-anomalies')
            ->expectsOutputToContain('Aucune anomalie détectée')
            ->assertSuccessful();

        Mail::assertNothingSent();
    }
}
