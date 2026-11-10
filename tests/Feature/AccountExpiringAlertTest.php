<?php

namespace Tests\Feature;

use App\Jobs\RefreshEbpTokens;
use App\Mail\AccountExpiringMail;
use App\Models\EbpAccount;
use App\Models\EbpToken;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Alerts\AlertService;
use App\Services\Ebp\EbpTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccountExpiringAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_compte_proche_de_lexpiration_declenche_lalerte(): void
    {
        Mail::fake();
        config(['ebp.token_refresh.expiring_alert_days' => 5]);

        $admin = User::factory()->create(['is_active' => true]);
        NotificationPreference::create([
            'user_id' => $admin->id,
            'alert_type' => AccountExpiringMail::ALERT_TYPE,
            'enabled' => true,
        ]);

        $account = EbpAccount::factory()->create(['is_active' => true]);
        EbpToken::create([
            'ebp_account_id' => $account->id,
            'access_token' => 'A', 'access_token_expires_at' => now()->addHour(),
            'refresh_token' => 'R', 'refresh_token_expires_at' => now()->addDays(3), // < seuil
            'state' => 'expiring',
        ]);

        // Le refresh réussit mais renvoie un refresh qui expire aussi dans 3 j.
        Http::fake([
            config('ebp.token_url') => Http::response(['access_token' => 'A2', 'refresh_token' => 'R2', 'expires_in' => 3600, 'refresh_expires_in' => 3 * 24 * 3600]),
        ]);

        (new RefreshEbpTokens)->handle(app(EbpTokenService::class), app(AlertService::class));

        Mail::assertSent(AccountExpiringMail::class, fn ($mail) => $mail->hasTo($admin->email));
    }

    public function test_un_compte_loin_de_lexpiration_nalerte_pas(): void
    {
        Mail::fake();
        config(['ebp.token_refresh.expiring_alert_days' => 5]);

        $account = EbpAccount::factory()->create(['is_active' => true]);
        EbpToken::create([
            'ebp_account_id' => $account->id,
            'refresh_token' => 'R', 'refresh_token_expires_at' => now()->addDays(29),
            'state' => 'healthy',
        ]);

        Http::fake([
            config('ebp.token_url') => Http::response(['access_token' => 'A2', 'refresh_token' => 'R2', 'expires_in' => 3600, 'refresh_expires_in' => 29 * 24 * 3600]),
        ]);

        (new RefreshEbpTokens)->handle(app(EbpTokenService::class), app(AlertService::class));

        Mail::assertNotSent(AccountExpiringMail::class);
    }
}
