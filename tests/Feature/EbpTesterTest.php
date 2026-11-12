<?php

namespace Tests\Feature;

use App\Models\EbpAccount;
use App\Models\EbpFolder;
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
        $account = EbpAccount::factory()->create(['label' => 'DEMO']);
        EbpFolder::factory()->create(['ebp_account_id' => $account->id, 'folder_id' => '405032', 'label' => 'SRM']);

        $this->actingAs($this->admin())
            ->get('/admin/testeur')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Testeur')
                ->has('accounts', 1)
                ->where('accounts.0.label', 'DEMO')
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
}
