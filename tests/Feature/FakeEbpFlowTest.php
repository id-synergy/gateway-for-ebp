<?php

namespace Tests\Feature;

use App\Models\EbpAccount;
use App\Services\Ebp\EbpFolderDiscovery;
use App\Services\Ebp\EbpTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FakeEbpFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['ebp.quota.per_second' => 1000]);
    }

    public function test_le_faux_serveur_repond_sur_les_dossiers(): void
    {
        $this->get('/fake-ebp/gescom/api/v1/Folders')
            ->assertOk()
            ->assertJsonPath('folders.0.shortName', 'DEMO40');
    }

    public function test_le_faux_portail_oauth_redirige_avec_code_et_state(): void
    {
        // Le faux /connect/authorize simule le portail EBP : consentement réussi ->
        // redirection vers redirect_uri avec un code et le state conservé.
        $this->get('/fake-ebp/connect/authorize?redirect_uri='.urlencode('http://localhost/oauth/ebp/callback').'&state=ABC123')
            ->assertRedirect('http://localhost/oauth/ebp/callback?code=DEMO_CODE&state=ABC123');
    }

    public function test_le_faux_serveur_simule_les_endpoints_metier(): void
    {
        $this->postJson('/fake-ebp/connect/token')
            ->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token', 'expires_in']);

        $this->getJson('/fake-ebp/gescom/api/v1/Folders/1001/Clients')
            ->assertOk()->assertJsonPath('results.0.id', 'C0001');

        $this->getJson('/fake-ebp/gescom/api/v1/Folders/1001/Items/ItemsExtentedQuery')
            ->assertOk()->assertJsonPath('results.0.code', 'ART001');

        $this->getJson('/fake-ebp/gescom/api/v1/Folders/1001/Documents/SaleDocument')
            ->assertOk()->assertJsonPath('results.0.number', 'FA-2026-001');
    }

    public function test_un_compte_demo_cible_le_faux_serveur(): void
    {
        $account = EbpAccount::factory()->create(['environment' => 'demo']);

        $this->assertStringContainsString('/fake-ebp', $account->apiBaseUrl());
        $this->assertStringContainsString('/fake-ebp/connect/token', $account->tokenUrl());
        $this->assertTrue($account->isDemo());
    }

    public function test_le_flux_demo_ne_declenche_aucun_appel_reseau(): void
    {
        // Toute requête HTTP sortante fait échouer le test : le mode démo doit tout court-circuiter.
        Http::preventStrayRequests();

        $account = EbpAccount::factory()->create(['environment' => 'demo']);

        app(EbpTokenService::class)->exchangeCode($account, 'DEMO_CODE');
        $count = app(EbpFolderDiscovery::class)->sync($account);

        $this->assertSame(2, $count);
        $this->assertSame('connected', $account->fresh()->status);
        $this->assertDatabaseHas('ebp_folders', ['ebp_account_id' => $account->id, 'short_name' => 'DEMO40']);
        $this->assertDatabaseHas('ebp_folders', ['ebp_account_id' => $account->id, 'short_name' => 'DEMO30']);
    }
}
