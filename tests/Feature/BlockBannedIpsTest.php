<?php

namespace Tests\Feature;

use App\Models\IpEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Blocage applicatif des IP en liste noire (middleware BlockBannedIps).
 * Les tests tapent sur 127.0.0.1 (IP de REMOTE_ADDR en test) sauf override.
 */
class BlockBannedIpsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    public function test_une_ip_bloquee_recoit_403_sur_lapi_gateway(): void
    {
        IpEntry::create(['ip' => '203.0.113.50', 'status' => 'blocked']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->getJson('/api/v1/ping')
            ->assertStatus(403)
            ->assertJson(['error' => 'ip_blocked']);
    }

    public function test_une_ip_non_bloquee_passe(): void
    {
        IpEntry::create(['ip' => '203.0.113.50', 'status' => 'blocked']);

        // Autre IP : laissée passer (ping public = 200).
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])
            ->getJson('/api/v1/ping')
            ->assertOk();
    }

    public function test_localhost_nest_jamais_bloque_meme_si_classe_blocked(): void
    {
        // Garde-fou anti-verrouillage : 127.0.0.1 en blocked ne doit pas renvoyer 403.
        IpEntry::create(['ip' => '127.0.0.1', 'status' => 'blocked']);

        $this->getJson('/api/v1/ping')->assertOk();
    }

    public function test_une_ip_bloquee_ne_peut_pas_atteindre_le_back_office(): void
    {
        $admin = $this->admin();
        IpEntry::create(['ip' => '203.0.113.60', 'status' => 'blocked']);

        $this->actingAs($admin)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.60'])
            ->get('/admin/ip')
            ->assertStatus(403);
    }

    public function test_une_ip_bloquee_est_rejetee_sur_le_proxy_ebp(): void
    {
        IpEntry::create(['ip' => '203.0.113.70', 'status' => 'blocked']);

        // Chemin proxy EBP (préfixe gescom) : rejeté en 403 AVANT auth jeton.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.70'])
            ->getJson('/gescom/api/v1/Clients')
            ->assertStatus(403)
            ->assertJson(['error' => 'ip_blocked']);
    }
}
