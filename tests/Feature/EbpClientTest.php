<?php

namespace Tests\Feature;

use App\Exceptions\Ebp\EbpApiException;
use App\Models\EbpAccount;
use App\Models\EbpFolder;
use App\Services\Ebp\EbpClient;
use App\Services\Ebp\EbpTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EbpClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['ebp.quota.per_second' => 1000]); // pas d'attente dans les tests
    }

    private function connectedAccount(): EbpAccount
    {
        $account = EbpAccount::factory()->create([
            'subscription_key_primary' => 'KEY_PRIMARY',
            'active_subscription_key' => 'primary',
        ]);

        Http::fake([
            config('ebp.token_url') => Http::response(['access_token' => 'ACCESS_1', 'refresh_token' => 'R1', 'expires_in' => 3600]),
        ]);
        app(EbpTokenService::class)->exchangeCode($account, 'CODE');

        return $account->fresh();
    }

    public function test_get_envoie_les_bons_entetes(): void
    {
        $account = $this->connectedAccount();
        $folder = EbpFolder::factory()->create(['ebp_account_id' => $account->id, 'folder_id' => '3691']);

        Http::fake([
            '*/gescom/api/v1/Clients*' => Http::response(['results' => [['id' => 'C1']], 'paging' => ['total' => 1]]),
        ]);

        app(EbpClient::class)->get($account, $folder, 'gescom/api/v1/Clients', ['Limit' => 10]);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer ACCESS_1')
                && $request->hasHeader('ebp-subscription-key', 'KEY_PRIMARY')
                && $request->hasHeader('Accept-Language', 'fr-FR');
        });
    }

    public function test_normalisation_extrait_data_et_paging(): void
    {
        $account = $this->connectedAccount();
        $folder = EbpFolder::factory()->create(['ebp_account_id' => $account->id]);

        Http::fake([
            '*/gescom/api/v1/Clients*' => Http::response([
                'results' => [['id' => 'C1'], ['id' => 'C2']],
                'paging' => ['offset' => 0, 'limit' => 100, 'total' => 2],
            ]),
        ]);

        $out = app(EbpClient::class)->get($account, $folder, 'gescom/api/v1/Clients');

        $this->assertCount(2, $out['data']);
        $this->assertSame(2, $out['paging']['total']);
    }

    public function test_preserve_la_precision_des_decimaux(): void
    {
        $account = $this->connectedAccount();
        $folder = EbpFolder::factory()->create(['ebp_account_id' => $account->id]);

        // Décimal à 28 chiffres renvoyé par EBP : ne doit pas être arrondi dans le corps brut.
        $decimal = '1545.0000000000000000000000000';
        Http::fake([
            '*/items*' => Http::response('{"results":[{"price":'.$decimal.'}]}'),
        ]);

        $out = app(EbpClient::class)->get($account, $folder, 'gescom/api/v1/items');

        $this->assertStringContainsString($decimal, $out['raw']);
    }

    public function test_une_erreur_ebp_leve_une_exception(): void
    {
        $account = $this->connectedAccount();
        $folder = EbpFolder::factory()->create(['ebp_account_id' => $account->id]);

        Http::fake(['*/items*' => Http::response(['error' => 'boom'], 500)]);

        $this->expectException(EbpApiException::class);
        app(EbpClient::class)->get($account, $folder, 'gescom/api/v1/items');
    }

    public function test_429_est_rejoue_puis_reussit(): void
    {
        $account = $this->connectedAccount();
        $folder = EbpFolder::factory()->create(['ebp_account_id' => $account->id]);

        Http::fake([
            '*/items*' => Http::sequence()
                ->push(['msg' => 'slow down'], 429, ['Retry-After' => '0'])
                ->push(['results' => [['id' => 'I1']]], 200),
        ]);

        $out = app(EbpClient::class)->get($account, $folder, 'gescom/api/v1/items');

        $this->assertCount(1, $out['data']);
    }
}
