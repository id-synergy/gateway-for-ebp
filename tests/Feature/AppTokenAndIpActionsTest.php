<?php

namespace Tests\Feature;

use App\Models\AllowedIp;
use App\Models\AppToken;
use App\Models\EbpAccount;
use App\Models\EbpFolder;
use App\Models\User;
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

    public function test_regeneration_dun_jeton_change_la_cle_et_invalide_l_ancienne(): void
    {
        $account = EbpAccount::factory()->create();
        $folder = EbpFolder::factory()->create(['ebp_account_id' => $account->id]);
        [$token, $ancienneCle] = app(\App\Services\Apps\AppTokenService::class)->create('App', $account, $folder);

        $this->actingAs($this->admin())
            ->post("/admin/jetons/{$token->id}/regenerate")
            ->assertRedirect()
            ->assertSessionHas('newToken');

        $service = app(\App\Services\Apps\AppTokenService::class);
        // L'ancienne clé ne résout plus ; une nouvelle a été émise.
        $this->assertNull($service->resolve($ancienneCle));
        $this->assertNotSame('h', $token->fresh()->token_hash);
        // Régénérer réactive et conserve l'app/compte/dossier.
        $this->assertTrue($token->fresh()->is_active);
        $this->assertSame($folder->id, $token->fresh()->default_folder_id);
    }

    public function test_regeneration_reactive_un_jeton_revoque(): void
    {
        $account = EbpAccount::factory()->create();
        [$token] = app(\App\Services\Apps\AppTokenService::class)->create('App', $account);
        app(\App\Services\Apps\AppTokenService::class)->revoke($token);
        $this->assertFalse($token->fresh()->is_active);

        $this->actingAs($this->admin())->post("/admin/jetons/{$token->id}/regenerate")->assertRedirect();

        $this->assertTrue($token->fresh()->is_active);
        $this->assertNull($token->fresh()->revoked_at);
    }

    public function test_ajout_et_suppression_dip(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/ip', ['ip' => '203.0.113.5', 'label' => 'Test'])->assertRedirect();
        $this->assertDatabaseHas('allowed_ips', ['ip' => '203.0.113.5']);

        $ip = AllowedIp::where('ip', '203.0.113.5')->first();
        $this->actingAs($admin)->delete("/admin/ip/{$ip->id}")->assertRedirect();
        $this->assertDatabaseMissing('allowed_ips', ['ip' => '203.0.113.5']);
    }
}
