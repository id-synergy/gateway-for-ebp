<?php

namespace Tests\Feature;

use App\Models\EbpAccount;
use App\Models\EbpFolder;
use App\Services\Apps\AppTokenService;
use App\Services\Ebp\EbpTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GatewayReadTest extends TestCase
{
    use RefreshDatabase;

    private string $plain;

    private EbpFolder $folder;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('local');
        config(['ebp.quota.per_second' => 1000]);

        $account = EbpAccount::factory()->create(['is_active' => true]);
        Http::fake([config('ebp.token_url') => Http::response(['access_token' => 'A1', 'refresh_token' => 'R1', 'expires_in' => 3600])]);
        app(EbpTokenService::class)->exchangeCode($account, 'CODE');

        $this->folder = EbpFolder::factory()->create(['ebp_account_id' => $account->id, 'folder_id' => '3691']);
        [, $this->plain] = app(AppTokenService::class)->create('App', $account->fresh(), $this->folder);
    }

    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->plain}"];
    }

    public function test_folders_miroir_appelle_l_endpoint_ebp_officiel(): void
    {
        Http::fake(['*/gescom/api/v1/Folders*' => Http::response(['folders' => [['id' => '3691']], 'paging' => ['total' => 1]])]);

        $this->getJson('/gescom/api/v1/Folders', $this->auth())
            ->assertOk()
            ->assertJsonPath('paging.total', 1);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/gescom/api/v1/Folders'));
    }

    public function test_clients_appelle_le_bon_endpoint_ebp(): void
    {
        Http::fake(['*/Folders/3691/Clients*' => Http::response(['results' => [['id' => 'C1']], 'paging' => ['total' => 1]])]);

        $this->getJson('/gescom/api/v1/Clients?Limit=5', $this->auth())
            ->assertOk()
            ->assertJsonPath('folder', '3691')
            ->assertJsonPath('paging.total', 1);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/gescom/api/v1/Folders/3691/Clients'));
    }

    public function test_documents_ventes_transmet_duration(): void
    {
        Http::fake(['*/Folders/3691/Documents/SaleDocument*' => Http::response(['results' => []])]);

        $this->getJson('/gescom/api/v1/Documents/SaleDocument?Duration=30', $this->auth())->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'Documents/SaleDocument')
            && str_contains($r->url(), 'Duration=30'));
    }

    public function test_une_seconde_lecture_identique_vient_du_cache(): void
    {
        config(['ebp.gateway.cache.default_ttl' => 60]);
        Http::fake(['*/Folders/3691/Clients*' => Http::response(['results' => [['id' => 'C1']], 'paging' => ['total' => 1]])]);

        $this->getJson('/gescom/api/v1/Clients?Limit=5', $this->auth())->assertOk();
        $this->getJson('/gescom/api/v1/Clients?Limit=5', $this->auth())
            ->assertOk()
            ->assertJsonPath('cached', true);

        // Un seul appel EBP malgré deux requêtes gateway.
        Http::assertSentCount(1);
    }

    public function test_no_cache_contourne_le_cache(): void
    {
        config(['ebp.gateway.cache.default_ttl' => 60]);
        Http::fake(['*/Folders/3691/Clients*' => Http::response(['results' => [['id' => 'C1']]])]);

        $this->getJson('/gescom/api/v1/Clients', $this->auth())->assertOk();
        // ?noCache=1 force un nouvel appel EBP, sans drapeau cached.
        $this->getJson('/gescom/api/v1/Clients?noCache=1', $this->auth())
            ->assertOk()
            ->assertJsonMissingPath('cached');

        Http::assertSentCount(2);
    }

    public function test_statut_result_import_n_est_jamais_cache(): void
    {
        // StatutResultImport évolue côté EBP (Waiting → Completed) sans écriture de notre part :
        // le cacher figerait le statut. Deux lectures identiques doivent refrapper EBP.
        config(['ebp.gateway.cache.default_ttl' => 60]);
        Http::fake(['*/import/api/v1/StatutResultImport*' => Http::response(['status' => 'Waiting'])]);

        $this->getJson('/import/api/v1/StatutResultImport?importId=IMP1', $this->auth())
            ->assertOk()
            ->assertJsonMissingPath('cached');
        $this->getJson('/import/api/v1/StatutResultImport?importId=IMP1', $this->auth())
            ->assertOk()
            ->assertJsonMissingPath('cached');

        Http::assertSentCount(2);
    }

    public function test_une_ecriture_est_relayee_vers_ebp(): void
    {
        Http::fake(['*/Folders/3691/Documents/SaleDocument*' => Http::response(['id' => 'DOC1'], 201)]);

        $this->postJson('/gescom/api/v1/Documents/SaleDocument', ['number' => 'F1'], $this->auth())
            ->assertStatus(201);

        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && str_contains($r->url(), '/gescom/api/v1/Folders/3691/Documents/SaleDocument')
            && $r->data() === ['number' => 'F1']);
    }

    public function test_une_ecriture_invalide_le_cache_du_dossier(): void
    {
        config(['ebp.gateway.cache.default_ttl' => 60]);
        Http::fake([
            '*/Folders/3691/Clients*' => Http::response(['results' => [['id' => 'C1']]]),
            '*/Folders/3691/Documents/SaleDocument*' => Http::response(['id' => 'DOC1'], 201),
        ]);

        // Lecture cachée.
        $this->getJson('/gescom/api/v1/Clients', $this->auth())->assertOk();
        $this->getJson('/gescom/api/v1/Clients', $this->auth())->assertJsonPath('cached', true);

        // Écriture -> invalide le cache du dossier.
        $this->postJson('/gescom/api/v1/Documents/SaleDocument', ['number' => 'F1'], $this->auth())->assertStatus(201);

        // La lecture suivante refrappe EBP (plus de cached).
        $this->getJson('/gescom/api/v1/Clients', $this->auth())
            ->assertOk()
            ->assertJsonMissingPath('cached');
    }

    public function test_un_chemin_hors_prefixe_autorise_404(): void
    {
        $this->getJson('/autre/api/v1/Truc', $this->auth())->assertStatus(404);
    }

    public function test_cache_desactive_sur_le_compte_ne_cache_pas(): void
    {
        config(['ebp.gateway.cache.default_ttl' => 60]);
        $this->folder->account->update(['cache_enabled' => false]);
        Http::fake(['*/Folders/3691/Clients*' => Http::response(['results' => [['id' => 'C1']]])]);

        $this->getJson('/gescom/api/v1/Clients', $this->auth())->assertOk();
        $this->getJson('/gescom/api/v1/Clients', $this->auth())
            ->assertOk()
            ->assertJsonMissingPath('cached');

        Http::assertSentCount(2); // chaque appel refrappe EBP
    }

    public function test_ttl_du_compte_prime_sur_la_config(): void
    {
        config(['ebp.gateway.cache.default_ttl' => 0]); // config dirait "pas de cache"
        $this->folder->account->update(['cache_enabled' => true, 'cache_ttl' => 120]);
        Http::fake(['*/Folders/3691/Clients*' => Http::response(['results' => [['id' => 'C1']]])]);

        $this->getJson('/gescom/api/v1/Clients', $this->auth())->assertOk();
        $this->getJson('/gescom/api/v1/Clients', $this->auth())->assertJsonPath('cached', true);

        Http::assertSentCount(1); // le TTL du compte (120) l'emporte
    }

    public function test_un_filtre_different_est_une_requete_differente(): void
    {
        config(['ebp.gateway.cache.default_ttl' => 60]);
        Http::fake(['*/Folders/3691/Clients*' => Http::response(['results' => []])]);

        $this->getJson('/gescom/api/v1/Clients?SearchTerm=A', $this->auth())->assertOk();
        // Filtre différent -> pas le même cache, nouvel appel EBP.
        $this->getJson('/gescom/api/v1/Clients?SearchTerm=B', $this->auth())
            ->assertOk()
            ->assertJsonMissingPath('cached');

        Http::assertSentCount(2);
    }

    public function test_vider_le_cache_du_compte_depuis_l_admin(): void
    {
        config(['ebp.gateway.cache.default_ttl' => 60]);
        Http::fake(['*/Folders/3691/Clients*' => Http::response(['results' => [['id' => 'C1']]])]);

        // Lecture cachée.
        $this->getJson('/gescom/api/v1/Clients', $this->auth())->assertOk();
        $this->getJson('/gescom/api/v1/Clients', $this->auth())->assertJsonPath('cached', true);

        // L'admin vide le cache du compte.
        $admin = \App\Models\User::factory()->create(['is_active' => true]);
        $account = $this->folder->account;
        $this->actingAs($admin)->post("/admin/comptes/{$account->id}/clear-cache")->assertRedirect();

        // La lecture suivante refrappe EBP.
        $this->getJson('/gescom/api/v1/Clients', $this->auth())
            ->assertOk()
            ->assertJsonMissingPath('cached');
    }

    public function test_sans_dossier_une_ressource_de_dossier_renvoie_400(): void
    {
        // Jeton SANS dossier par défaut.
        $account = $this->folder->account;
        [, $plain] = app(AppTokenService::class)->create('SansDossier', $account, null);
        Http::fake(); // aucun appel ne doit partir

        $this->getJson('/gescom/api/v1/Clients', ['Authorization' => "Bearer {$plain}"])
            ->assertStatus(400)
            ->assertJsonPath('error', 'folder_required');

        Http::assertNothingSent();
    }

    public function test_sans_dossier_avec_folderid_valide_fonctionne(): void
    {
        $account = $this->folder->account;
        [, $plain] = app(AppTokenService::class)->create('SansDossier', $account, null);
        Http::fake(['*/Folders/3691/Clients*' => Http::response(['results' => []])]);

        $this->getJson('/gescom/api/v1/Clients?folderId=3691', ['Authorization' => "Bearer {$plain}"])
            ->assertOk();
    }

    public function test_sans_dossier_la_liste_des_dossiers_fonctionne(): void
    {
        // GET .../Folders ne requiert pas de dossier, même sans dossier par défaut.
        $account = $this->folder->account;
        [, $plain] = app(AppTokenService::class)->create('SansDossier', $account, null);
        Http::fake(['*/gescom/api/v1/Folders*' => Http::response(['folders' => [], 'paging' => ['total' => 0]])]);

        $this->getJson('/gescom/api/v1/Folders', ['Authorization' => "Bearer {$plain}"])
            ->assertOk();
    }

    public function test_une_erreur_ebp_est_relayee_en_502_propre(): void
    {
        // EBP répond 500 sur l'appel métier (token déjà valide via setUp).
        Http::fake(['*/Folders/3691/Clients*' => Http::response('oops', 500)]);

        $this->getJson('/gescom/api/v1/Clients', $this->auth())
            ->assertStatus(502)
            ->assertJsonPath('error', 'ebp_upstream_error')
            ->assertJsonMissingPath('file')   // pas de fuite de structure interne
            ->assertJsonMissingPath('trace');
    }

    public function test_un_compte_sans_token_renvoie_503_sans_trace(): void
    {
        // Compte sans token du tout : l'appel ne peut pas s'authentifier -> EbpAuthException -> 503.
        $account = EbpAccount::factory()->create(['is_active' => true, 'status' => 'error']);
        $folder = EbpFolder::factory()->create(['ebp_account_id' => $account->id, 'folder_id' => '7777']);
        [, $plain] = app(AppTokenService::class)->create('SansToken', $account, $folder);
        Http::preventStrayRequests(); // aucun appel EBP ne doit partir

        $this->getJson('/gescom/api/v1/Clients', ['Authorization' => "Bearer {$plain}"])
            ->assertStatus(503)
            ->assertJsonPath('error', 'ebp_account_unauthenticated')
            ->assertJsonMissingPath('file')
            ->assertJsonMissingPath('trace');
    }

    public function test_sans_jeton_401(): void
    {
        $this->getJson('/gescom/api/v1/Clients')->assertStatus(401);
    }
}
