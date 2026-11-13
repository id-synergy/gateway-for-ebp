<?php

namespace Tests\Feature;

use App\Models\AppToken;
use App\Models\EbpAccount;
use App\Models\EbpFolder;
use App\Models\IpEntry;
use App\Models\User;
use App\Services\Apps\AppTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppTokenAndIpActionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    public function test_creation_dun_jeton_depuis_le_back_office(): void
    {
        $account = EbpAccount::factory()->create();
        $folder = EbpFolder::factory()->create(['ebp_account_id' => $account->id]);

        $this->actingAs($this->admin())
            ->post('/admin/jetons', ['name' => 'App X', 'ebp_account_id' => $account->id, 'default_folder_id' => $folder->id])
            ->assertRedirect()
            ->assertSessionHas('newToken');

        $this->assertDatabaseHas('app_tokens', ['name' => 'App X', 'ebp_account_id' => $account->id]);
    }

    public function test_revocation_dun_jeton(): void
    {
        $account = EbpAccount::factory()->create();
        $token = AppToken::create([
            'name' => 'App', 'token_hash' => 'h', 'ebp_account_id' => $account->id, 'is_active' => true,
        ]);

        $this->actingAs($this->admin())->delete("/admin/jetons/{$token->id}")->assertRedirect();

        $this->assertFalse($token->fresh()->is_active);
    }

    public function test_regeneration_dun_jeton_actif_change_la_cle_et_invalide_l_ancienne(): void
    {
        $account = EbpAccount::factory()->create();
        $folder = EbpFolder::factory()->create(['ebp_account_id' => $account->id]);
        $service = app(AppTokenService::class);
        [$token, $ancienneCle] = $service->create('App', $account, $folder);

        $this->actingAs($this->admin())
            ->post("/admin/jetons/{$token->id}/regenerate")
            ->assertRedirect()
            ->assertSessionHas('newToken');

        // L'ancienne clé ne résout plus ; le jeton reste actif, même app/compte/dossier.
        $this->assertNull($service->resolve($ancienneCle));
        $this->assertTrue($token->fresh()->is_active);
        $this->assertSame($folder->id, $token->fresh()->default_folder_id);
    }

    public function test_un_jeton_revoque_ne_peut_pas_etre_regenere(): void
    {
        $account = EbpAccount::factory()->create();
        $service = app(AppTokenService::class);
        [$token] = $service->create('App', $account);
        $hashRevoque = $token->fresh()->token_hash;
        $service->revoke($token);

        $this->actingAs($this->admin())
            ->post("/admin/jetons/{$token->id}/regenerate")
            ->assertRedirect()
            ->assertSessionHas('error');

        // Toujours révoqué, clé inchangée, aucun nouveau jeton émis.
        $this->assertFalse($token->fresh()->is_active);
        $this->assertSame($hashRevoque, $token->fresh()->token_hash);
    }

    public function test_ajout_et_suppression_dip(): void
    {
        $admin = $this->admin();

        // Ajout sans libellé -> supprimable intégralement.
        $this->actingAs($admin)->post('/admin/ip', ['ip' => '203.0.113.5', 'status' => 'allowed'])->assertRedirect();
        $this->assertDatabaseHas('ip_entries', ['ip' => '203.0.113.5', 'status' => 'allowed']);

        $ip = IpEntry::where('ip', '203.0.113.5')->first();
        $this->actingAs($admin)->delete("/admin/ip/{$ip->id}")->assertRedirect();
        $this->assertDatabaseMissing('ip_entries', ['ip' => '203.0.113.5']);
    }
}
