<?php

namespace Tests\Feature;

use App\Models\EbpAccount;
use App\Models\EbpFolder;
use App\Models\RequestLog;
use App\Models\User;
use App\Services\Ebp\EbpTokenService;
use App\Services\Logging\RequestLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EbpTesterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['ebp.quota.per_second' => 1000]); // pas d'attente du régulateur dans les tests
    }

    private function admin(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    /** Compte EBP connecté (token OAuth échangé via le faux endpoint). */
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

    public function test_la_page_testeur_exige_une_connexion(): void
    {
        $this->get('/admin/testeur')->assertRedirect('/login');
    }

    public function test_la_page_testeur_liste_les_comptes(): void
    {
        $account = EbpAccount::factory()->create(['label' => 'SRMOTORISATION']);
        EbpFolder::factory()->create(['ebp_account_id' => $account->id, 'folder_id' => '405032', 'label' => 'SRM']);

        $this->actingAs($this->admin())
            ->get('/admin/testeur')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Testeur')
                ->has('accounts', 1)
                ->where('accounts.0.label', 'SRMOTORISATION')
                ->has('accounts.0.folders', 1));
    }

    public function test_la_route_run_exige_une_connexion(): void
    {
        $this->postJson('/admin/testeur/run', [
            'ebp_account_id' => 1,
            'method' => 'GET',
            'endpoint' => 'gescom/api/v1/Clients',
        ])->assertStatus(401);
    }

    public function test_un_get_relaye_appelle_ebp_avec_les_bons_arguments(): void
    {
        $account = $this->connectedAccount();
        $folder = EbpFolder::factory()->create(['ebp_account_id' => $account->id, 'folder_id' => '405032']);

        Http::fake([
            '*/gescom/api/v1/Folders/405032/Clients*' => Http::response(['results' => [['id' => 'C1']], 'paging' => ['total' => 1]]),
        ]);

        $response = $this->actingAs($this->admin())->postJson('/admin/testeur/run', [
            'ebp_account_id' => $account->id,
            'folder_id' => $folder->id,
            'method' => 'GET',
            'endpoint' => 'gescom/api/v1/Folders/405032/Clients',
            'query' => ['Limit' => 10],
        ]);

        $response->assertOk()
            ->assertJson(['ok' => true, 'status' => 200, 'method' => 'GET'])
            ->assertJsonPath('url', fn ($url) => str_contains($url, 'gescom/api/v1/Folders/405032/Clients'));

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_contains($request->url(), 'gescom/api/v1/Folders/405032/Clients')
            && $request->hasHeader('Authorization', 'Bearer ACCESS_1')
            && $request->hasHeader('ebp-subscription-key', 'KEY_PRIMARY'));
    }

    public function test_un_get_est_journalise_dans_les_logs(): void
    {
        $account = $this->connectedAccount();

        Http::fake([
            '*/gescom/api/v1/Clients*' => Http::response(['results' => []]),
        ]);

        $this->actingAs($this->admin())->postJson('/admin/testeur/run', [
            'ebp_account_id' => $account->id,
            'method' => 'GET',
            'endpoint' => 'gescom/api/v1/Clients',
        ])->assertOk();

        $this->assertDatabaseHas('request_logs', [
            'ebp_account_id' => $account->id,
            'method' => 'GET',
            'ebp_endpoint' => 'gescom/api/v1/Clients',
            'http_status' => 200,
            'success' => true,
        ]);
    }

    public function test_une_ecriture_sans_confirmation_est_refusee(): void
    {
        $account = $this->connectedAccount();

        Http::fake(['*' => Http::response(['id' => 'NEW'], 201)]);

        $this->actingAs($this->admin())->postJson('/admin/testeur/run', [
            'ebp_account_id' => $account->id,
            'method' => 'POST',
            'endpoint' => 'gescom/api/v1/Folders/405032/Items',
            'body' => '{"Caption":"Test"}',
            // confirmed absent
        ])->assertStatus(422)
            ->assertJsonValidationErrors('confirmed');

        // Aucune requête n'a été envoyée à EBP.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'gescom/api/v1/Folders/405032/Items'));
    }

    public function test_une_ecriture_confirmee_part_avec_le_corps_json(): void
    {
        $account = $this->connectedAccount();

        Http::fake([
            '*/gescom/api/v1/Folders/405032/Items*' => Http::response(['id' => 'NEW'], 201),
        ]);

        $this->actingAs($this->admin())->postJson('/admin/testeur/run', [
            'ebp_account_id' => $account->id,
            'method' => 'POST',
            'endpoint' => 'gescom/api/v1/Folders/405032/Items',
            'body' => '{"Caption":"Mon article"}',
            'confirmed' => true,
        ])->assertOk()->assertJson(['ok' => true, 'status' => 201]);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), 'gescom/api/v1/Folders/405032/Items')
            && $request['Caption'] === 'Mon article');
    }

    public function test_un_corps_json_invalide_est_rejete(): void
    {
        $account = $this->connectedAccount();

        $this->actingAs($this->admin())->postJson('/admin/testeur/run', [
            'ebp_account_id' => $account->id,
            'method' => 'POST',
            'endpoint' => 'gescom/api/v1/Folders/405032/Items',
            'body' => '{ pas du json',
            'confirmed' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('body');
    }

    public function test_une_erreur_ebp_renvoie_le_corps_sans_lever(): void
    {
        $account = $this->connectedAccount();

        Http::fake([
            '*/gescom/api/v1/Bad*' => Http::response(['error' => 'not found'], 404),
        ]);

        $this->actingAs($this->admin())->postJson('/admin/testeur/run', [
            'ebp_account_id' => $account->id,
            'method' => 'GET',
            'endpoint' => 'gescom/api/v1/Bad',
        ])->assertOk()
            ->assertJson(['ok' => false, 'status' => 404])
            ->assertJsonPath('body', fn ($body) => $body !== null && str_contains($body, 'not found'));
    }

    public function test_le_rejeu_pre_remplit_le_testeur_depuis_un_log(): void
    {
        Storage::fake('local');
        $account = EbpAccount::factory()->create();
        $folder = EbpFolder::factory()->create(['ebp_account_id' => $account->id, 'folder_id' => '405032']);

        $log = app(RequestLogger::class)->record([
            'ebp_account_id' => $account->id,
            'ebp_folder_id' => $folder->id,
            'method' => 'GET',
            'ebp_endpoint' => 'gescom/api/v1/Folders/405032/Items',
            'params' => ['Limit' => 50],
            'http_status' => 404,
            'success' => false,
        ], '{"error":"boom"}');

        // Le détail du log expose les éléments nécessaires au rejeu.
        $this->actingAs($this->admin())
            ->get("/admin/logs/{$log->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/LogDetail')
                ->where('canReplay', true)
                ->where('log.accountId', $account->id)
                ->where('log.folderId', $folder->id)
                ->where('log.method', 'GET')
                ->where('log.endpoint', 'gescom/api/v1/Folders/405032/Items'));

        // Le testeur accepte le pré-remplissage par query params.
        $this->actingAs($this->admin())
            ->get("/admin/testeur?account={$account->id}&folder={$folder->id}&method=GET&endpoint=gescom/api/v1/Folders/405032/Items&params[Limit]=50")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Testeur')
                ->where('prefill.accountId', $account->id)
                ->where('prefill.folderId', $folder->id)
                ->where('prefill.method', 'GET')
                ->where('prefill.endpoint', 'gescom/api/v1/Folders/405032/Items')
                ->where('prefill.query.Limit', '50'));
    }

    public function test_les_suggestions_exigent_une_connexion(): void
    {
        $this->getJson('/admin/testeur/suggestions')->assertStatus(401);
    }

    public function test_les_suggestions_exposent_catalogue_et_historique(): void
    {
        $account = EbpAccount::factory()->create();

        RequestLog::create([
            'occurred_at' => now()->subDays(2),
            'ebp_account_id' => $account->id,
            'method' => 'GET',
            'ebp_endpoint' => 'gescom/api/v1/Folders/405032/Clients',
            'params' => ['Limit' => 10],
            'http_status' => 200,
            'success' => true,
        ]);

        $this->actingAs($this->admin())
            ->getJson("/admin/testeur/suggestions?account={$account->id}")
            ->assertOk()
            ->assertJsonStructure([
                'history' => [['method', 'endpoint', 'query', 'lastCall']],
                'catalog' => [['module', 'prefix', 'endpoints']],
            ])
            ->assertJsonPath('history.0.endpoint', 'gescom/api/v1/Folders/405032/Clients')
            ->assertJsonPath('history.0.query.Limit', 10);
    }

    public function test_lhistorique_est_distinct_et_borne_a_30_jours(): void
    {
        $account = EbpAccount::factory()->create();

        // Deux appels sur le même endpoint : seul le plus récent (avec ses params) doit rester.
        RequestLog::create([
            'occurred_at' => now()->subDays(10),
            'ebp_account_id' => $account->id,
            'method' => 'GET', 'ebp_endpoint' => 'gescom/api/v1/Folders/405032/Items',
            'params' => ['Limit' => 10], 'http_status' => 200, 'success' => true,
        ]);
        RequestLog::create([
            'occurred_at' => now()->subDays(1),
            'ebp_account_id' => $account->id,
            'method' => 'GET', 'ebp_endpoint' => 'gescom/api/v1/Folders/405032/Items',
            'params' => ['Limit' => 99], 'http_status' => 200, 'success' => true,
        ]);
        // Appel hors fenêtre : ignoré.
        RequestLog::create([
            'occurred_at' => now()->subDays(40),
            'ebp_account_id' => $account->id,
            'method' => 'GET', 'ebp_endpoint' => 'gescom/api/v1/Folders/405032/OldOne',
            'params' => [], 'http_status' => 200, 'success' => true,
        ]);

        $response = $this->actingAs($this->admin())
            ->getJson("/admin/testeur/suggestions?account={$account->id}")
            ->assertOk();

        $endpoints = collect($response->json('history'))->pluck('endpoint');
        $this->assertSame(1, $endpoints->filter(fn ($e) => $e === 'gescom/api/v1/Folders/405032/Items')->count());
        $this->assertFalse($endpoints->contains('gescom/api/v1/Folders/405032/OldOne'));
        // Le plus récent garde ses params.
        $this->assertSame(99, collect($response->json('history'))
            ->firstWhere('endpoint', 'gescom/api/v1/Folders/405032/Items')['query']['Limit']);
    }

    public function test_le_folderid_est_injecte_dans_les_deux_sources(): void
    {
        $account = EbpAccount::factory()->create();
        $folder = EbpFolder::factory()->create(['ebp_account_id' => $account->id, 'folder_id' => '999999']);

        // Historique sur un AUTRE dossier : doit être normalisé vers le dossier courant.
        RequestLog::create([
            'occurred_at' => now()->subDays(1),
            'ebp_account_id' => $account->id,
            'method' => 'GET', 'ebp_endpoint' => 'gescom/api/v1/Folders/111111/Clients',
            'params' => [], 'http_status' => 200, 'success' => true,
        ]);

        $response = $this->actingAs($this->admin())
            ->getJson("/admin/testeur/suggestions?account={$account->id}&folder={$folder->id}")
            ->assertOk();

        // Historique normalisé.
        $this->assertSame('gescom/api/v1/Folders/999999/Clients', $response->json('history.0.endpoint'));

        // Catalogue : au moins un chemin gescom contient le dossier injecté, et plus de {FolderId}.
        $catalogPaths = collect($response->json('catalog'))
            ->flatMap(fn ($g) => collect($g['endpoints'])->pluck('path'));
        $this->assertTrue($catalogPaths->contains(fn ($p) => str_contains($p, 'Folders/999999/')));
        $this->assertFalse($catalogPaths->contains(fn ($p) => str_contains($p, '{FolderId}')));
    }

    public function test_sans_dossier_le_folderid_reste_dans_le_catalogue(): void
    {
        $account = EbpAccount::factory()->create();

        $response = $this->actingAs($this->admin())
            ->getJson("/admin/testeur/suggestions?account={$account->id}")
            ->assertOk();

        $catalogPaths = collect($response->json('catalog'))
            ->flatMap(fn ($g) => collect($g['endpoints'])->pluck('path'));
        $this->assertTrue($catalogPaths->contains(fn ($p) => str_contains($p, '{FolderId}')));
    }
}
