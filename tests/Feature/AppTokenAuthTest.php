<?php

namespace Tests\Feature;

use App\Models\AppToken;
use App\Models\EbpAccount;
use App\Models\EbpFolder;
use App\Services\Apps\AppTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppTokenAuthTest extends TestCase
{
    use RefreshDatabase;

    private function service(): AppTokenService
    {
        return app(AppTokenService::class);
    }

    public function test_sans_jeton_401(): void
    {
        $this->getJson('/api/v1/whoami')->assertStatus(401);
    }

    public function test_jeton_invalide_401(): void
    {
        $this->getJson('/api/v1/whoami', ['Authorization' => 'Bearer gw_inexistant'])->assertStatus(401);
    }

    public function test_jeton_avec_dossier_par_defaut_resout_compte_et_dossier(): void
    {
        $account = EbpAccount::factory()->create(['label' => 'Compte A', 'is_active' => true]);
        $folder = EbpFolder::factory()->create(['ebp_account_id' => $account->id, 'folder_id' => '3691', 'label' => 'Principal']);

        [, $plain] = $this->service()->create('Tickets', $account, $folder);

        $this->getJson('/api/v1/whoami', ['Authorization' => "Bearer {$plain}"])
            ->assertOk()
            ->assertJson(['app' => 'Tickets', 'account' => 'Compte A', 'folder' => ['id' => '3691']]);
    }

    public function test_sans_dossier_par_defaut_et_sans_param_400(): void
    {
        $account = EbpAccount::factory()->create(['is_active' => true]);
        EbpFolder::factory()->create(['ebp_account_id' => $account->id, 'folder_id' => '3691']);

        [, $plain] = $this->service()->create('Sans défaut', $account, null);

        $this->getJson('/api/v1/whoami', ['Authorization' => "Bearer {$plain}"])->assertStatus(400);
    }

    public function test_folder_id_en_parametre_est_prioritaire(): void
    {
        $account = EbpAccount::factory()->create(['is_active' => true]);
        $defaut = EbpFolder::factory()->create(['ebp_account_id' => $account->id, 'folder_id' => '1000']);
        EbpFolder::factory()->create(['ebp_account_id' => $account->id, 'folder_id' => '2000', 'label' => 'Ciblé']);

        [, $plain] = $this->service()->create('App', $account, $defaut);

        $this->getJson('/api/v1/whoami?folderId=2000', ['Authorization' => "Bearer {$plain}"])
            ->assertOk()
            ->assertJson(['folder' => ['id' => '2000']]);
    }

    public function test_jeton_revoque_401(): void
    {
        $account = EbpAccount::factory()->create(['is_active' => true]);
        $folder = EbpFolder::factory()->create(['ebp_account_id' => $account->id]);

        [$token, $plain] = $this->service()->create('App', $account, $folder);
        $this->service()->revoke($token);

        $this->getJson('/api/v1/whoami', ['Authorization' => "Bearer {$plain}"])->assertStatus(401);
    }

    public function test_le_hash_est_stocke_pas_le_jeton_en_clair(): void
    {
        $account = EbpAccount::factory()->create();
        [$token, $plain] = $this->service()->create('App', $account, null);

        $this->assertNotSame($plain, $token->token_hash);
        $this->assertDatabaseMissing('app_tokens', ['token_hash' => $plain]);
    }
}
