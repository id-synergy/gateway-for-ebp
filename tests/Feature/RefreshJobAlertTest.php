<?php

namespace Tests\Feature;

use App\Jobs\RefreshEbpTokens;
use App\Mail\TokenRefreshFailedMail;
use App\Models\EbpAccount;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Ebp\EbpTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RefreshJobAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_echec_de_refresh_alerte_les_admins_abonnes(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['is_active' => true]);
        NotificationPreference::create([
            'user_id' => $admin->id,
            'alert_type' => TokenRefreshFailedMail::ALERT_TYPE,
            'enabled' => true,
        ]);

        $account = EbpAccount::factory()->create();

        // Connexion initiale OK, puis refresh en échec.
        Http::fake([
            config('ebp.token_url') => Http::sequence()
                ->push(['access_token' => 'A1', 'refresh_token' => 'R1', 'expires_in' => 3600])
                ->push(['error' => 'invalid_grant'], 400),
        ]);
        app(EbpTokenService::class)->exchangeCode($account, 'CODE');

        (new RefreshEbpTokens)->handle(app(EbpTokenService::class), app(\App\Services\Alerts\AlertService::class));

        Mail::assertSent(TokenRefreshFailedMail::class, fn ($mail) => $mail->hasTo($admin->email));
    }
}
