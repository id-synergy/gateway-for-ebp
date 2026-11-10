<?php

namespace Tests\Feature;

use App\Models\EbpAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountCrudTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    public function test_creation_dun_compte_ebp(): void
    {
        $this->actingAs($this->admin())->post('/admin/comptes', [
            'label' => 'Prod',
            'client_id' => 'cid',
            'client_secret' => 'csecret',
            'subscription_key_primary' => 'key1',
            'environment' => 'production',
        ])->assertRedirect();

        $account = EbpAccount::where('label', 'Prod')->first();
        $this->assertNotNull($account);
        $this->assertSame('cid', $account->client_id);          // déchiffré par le cast
        $this->assertSame('disconnected', $account->status);
    }

    public function test_modification_dun_compte_ne_touche_pas_aux_secrets_vides(): void
    {
        $account = EbpAccount::factory()->create(['label' => 'Avant', 'client_secret' => 'SECRET_ORIG']);

        $this->actingAs($this->admin())->patch("/admin/comptes/{$account->id}", [
            'label' => 'Après',
            'active_subscription_key' => 'secondary',
            'environment' => 'production',
            // secrets laissés vides -> inchangés
        ])->assertRedirect();

        $account->refresh();
        $this->assertSame('Après', $account->label);
        $this->assertSame('secondary', $account->active_subscription_key);
        $this->assertSame('SECRET_ORIG', $account->client_secret);
    }

    public function test_suppression_dun_compte(): void
    {
        $account = EbpAccount::factory()->create();

        $this->actingAs($this->admin())->delete("/admin/comptes/{$account->id}")->assertRedirect('/admin/comptes');

        $this->assertDatabaseMissing('ebp_accounts', ['id' => $account->id]);
    }
}
