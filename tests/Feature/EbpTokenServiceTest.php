<?php

namespace Tests\Feature;

use App\Models\EbpAccount;
use App\Services\Ebp\EbpTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EbpTokenServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): EbpTokenService
    {
        return app(EbpTokenService::class);
    }

    public function test_authorize_url_contient_les_parametres_oauth(): void
    {
        $account = EbpAccount::factory()->create(['client_id' => 'jupiter']);

        $url = $this->service()->buildAuthorizeUrl($account, 'STATE123');

        $this->assertStringStartsWith(config('ebp.authorize_url'), $url);
        $this->assertStringContainsString('client_id=jupiter', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('state=STATE123', $url);
        $this->assertStringContainsString('offline_access', $url);
        $this->assertStringContainsString('response_mode=query', $url);
    }

    public function test_exchange_code_stocke_les_tokens_chiffres(): void
    {
        Http::fake([
            config('ebp.token_url') => Http::response([
                'access_token' => 'ACCESS_1',
                'refresh_token' => 'REFRESH_1',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
        ]);

        $account = EbpAccount::factory()->create();

        $token = $this->service()->exchangeCode($account, 'AUTH_CODE');

        $this->assertSame('ACCESS_1', $token->access_token);
        $this->assertSame('REFRESH_1', $token->refresh_token);
        $this->assertSame('healthy', $token->state);
        $this->assertSame('connected', $account->fresh()->status);

        // Chiffré en base (le cast déchiffre ; la valeur brute ne doit pas être en clair).
        $raw = \DB::table('ebp_tokens')->where('ebp_account_id', $account->id)->value('access_token');
        $this->assertNotSame('ACCESS_1', $raw);
    }

    public function test_refresh_remplace_lancien_refresh_token(): void
    {
        $account = EbpAccount::factory()->create();

        Http::fake([
            config('ebp.token_url') => Http::sequence()
                ->push(['access_token' => 'ACCESS_1', 'refresh_token' => 'REFRESH_1', 'expires_in' => 3600])
                ->push(['access_token' => 'ACCESS_2', 'refresh_token' => 'REFRESH_2', 'expires_in' => 3600]),
        ]);

        $this->service()->exchangeCode($account, 'AUTH_CODE');
        $token = $this->service()->refresh($account->fresh());

        $this->assertSame('ACCESS_2', $token->access_token);
        $this->assertSame('REFRESH_2', $token->refresh_token);
    }

    public function test_refresh_echoue_marque_le_token_broken(): void
    {
        $account = EbpAccount::factory()->create();

        Http::fake([
            config('ebp.token_url') => Http::sequence()
                ->push(['access_token' => 'ACCESS_1', 'refresh_token' => 'REFRESH_1', 'expires_in' => 3600])
                ->push(['error' => 'invalid_grant'], 400),
        ]);

        $this->service()->exchangeCode($account, 'AUTH_CODE');

        $this->expectException(\RuntimeException::class);

        try {
            $this->service()->refresh($account->fresh());
        } finally {
            $this->assertSame('broken', $account->token->fresh()->state);
            $this->assertSame('error', $account->fresh()->status);
        }
    }

    public function test_valid_access_token_renouvelle_si_expire(): void
    {
        $account = EbpAccount::factory()->create();

        Http::fake([
            config('ebp.token_url') => Http::sequence()
                ->push(['access_token' => 'ACCESS_1', 'refresh_token' => 'REFRESH_1', 'expires_in' => 3600])
                ->push(['access_token' => 'ACCESS_2', 'refresh_token' => 'REFRESH_2', 'expires_in' => 3600]),
        ]);

        $this->service()->exchangeCode($account, 'AUTH_CODE');

        // Force l'expiration de l'access token courant.
        $account->token->update(['access_token_expires_at' => now()->subMinute()]);

        $access = $this->service()->validAccessToken($account->fresh());

        $this->assertSame('ACCESS_2', $access);
    }

    public function test_valid_access_token_renouvelle_si_echeance_absente(): void
    {
        $account = EbpAccount::factory()->create();

        Http::fake([
            config('ebp.token_url') => Http::sequence()
                ->push(['access_token' => 'ACCESS_1', 'refresh_token' => 'REFRESH_1', 'expires_in' => 3600])
                ->push(['access_token' => 'ACCESS_2', 'refresh_token' => 'REFRESH_2', 'expires_in' => 3600]),
        ]);

        $this->service()->exchangeCode($account, 'AUTH_CODE');

        // Bug observé en prod : échéance nulle en base => le token doit être considéré expiré.
        $account->token->update(['access_token_expires_at' => null]);

        $access = $this->service()->validAccessToken($account->fresh());

        $this->assertSame('ACCESS_2', $access);
    }

    public function test_valid_access_token_attend_le_verrou_et_ne_declenche_pas_un_second_refresh(): void
    {
        $account = EbpAccount::factory()->create();

        Http::fake([
            config('ebp.token_url') => Http::response([
                'access_token' => 'ACCESS_1',
                'refresh_token' => 'REFRESH_1',
                'expires_in' => 3600,
            ]),
        ]);

        $this->service()->exchangeCode($account, 'AUTH_CODE');
        $account->token->update(['access_token_expires_at' => now()->subMinute()]);

        // Simule un refresh concurrent : le verrou du compte est déjà détenu, et le token
        // a déjà été rafraîchi par cet autre process pendant que notre appel attend.
        $lock = Cache::lock("ebp-token-refresh:{$account->id}", 60);
        $this->assertTrue($lock->get());
        $account->token->update(['access_token' => 'ACCESS_FRESH', 'access_token_expires_at' => now()->addHour()]);

        try {
            $access = $this->service()->validAccessToken($account->fresh());
        } finally {
            $lock->release();
        }

        // On relit le token frais au lieu de rejouer un refresh (qui aurait invalidé le refresh token).
        $this->assertSame('ACCESS_FRESH', $access);
        Http::assertSentCount(1); // uniquement l'échange initial, aucun refresh
    }
}
