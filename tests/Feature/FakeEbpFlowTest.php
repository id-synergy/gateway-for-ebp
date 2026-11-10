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

    public function test_le_faux_portail_affiche_une_page_de_login(): void
    {
        // Le GET /connect/authorize affiche la page de connexion EBP simulée.
        $this->get('/fake-ebp/connect/authorize?redirect_uri='.urlencode('http://localhost/oauth/ebp/callback').'&state=ABC123')
            ->assertOk()
            ->assertSee('PORTAIL EBP SIMULÉ')
            ->assertSee('Identifiez-vous');
    }

    public function test_le_faux_portail_autorise_redirige_avec_code(): void
    {
        // « Autoriser » : redirige vers le callback avec un code et le state conservé.
        $this->post('/fake-ebp/connect/authorize', [
            'redirect_uri' => 'http://localhost/oauth/ebp/callback',
            'state' => 'ABC123',
            'decision' => 'authorize',
        ])->assertRedirect('http://localhost/oauth/ebp/callback?code=DEMO_CODE&state=ABC123');
    }

    public function test_le_faux_portail_refuser_redirige_avec_error(): void
    {
        // « Refuser » : retour sans code, avec error=access_denied (comme le vrai portail).
        $this->post('/fake-ebp/connect/authorize', [
            'redirect_uri' => 'http://localhost/oauth/ebp/callback',
            'state' => 'ABC123',
            'decision' => 'deny',
        ])->assertRedirect('http://localhost/oauth/ebp/callback?error=access_denied&state=ABC123');
    }

    public function test_le_faux_portail_identifiants_invalides_reaffiche_la_page(): void
    {
        $this->post('/fake-ebp/connect/authorize', [
            'redirect_uri' => 'http://localhost/oauth/ebp/callback',
            'state' => 'ABC123',
            'decision' => 'invalid',
        ])->assertOk()->assertSee('Identifiants EBP refusés');
    }

    public function test_le_faux_serveur_simule_les_endpoints_metier(): void
    {
        $this->postJson('/fake-ebp/connect/token')
            ->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token', 'expires_in']);

        $this->getJson('/fake-ebp/gescom/api/v1/Folders/1001/Clients')
            ->assertOk()->assertJsonPath('results.0.id', 'C0001');

        $this->getJson('/fake-ebp/gescom/api/v1/Folders/1001/Items/ItemsExtentedQuery')
            ->assertOk()->assertJsonPath('results.0.Id', 'ART001');

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

    public function test_la_bascule_globale_route_un_compte_normal_vers_le_faux_serveur(): void
    {
        // Compte de production, mais le faux portail global est activé (dev/test).
        config(['ebp.fake_portal' => true]);
        $account = EbpAccount::factory()->create(['environment' => 'production']);

        $this->assertTrue($account->usesFakeEbp());
        $this->assertStringContainsString('/fake-ebp', $account->apiBaseUrl());
        $this->assertStringContainsString('/fake-ebp/connect/token', $account->tokenUrl());
    }

    public function test_sans_bascule_un_compte_production_vise_le_vrai_ebp(): void
    {
        config(['ebp.fake_portal' => false]);
        $account = EbpAccount::factory()->create(['environment' => 'production']);

        $this->assertFalse($account->usesFakeEbp());
        $this->assertStringNotContainsString('/fake-ebp', $account->apiBaseUrl());
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
