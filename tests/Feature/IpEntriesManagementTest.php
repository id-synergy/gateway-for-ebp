<?php

namespace Tests\Feature;

use App\Models\AppToken;
use App\Models\EbpAccount;
use App\Models\IpEntry;
use App\Models\RequestLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Page « IP » unifiée : classement (allowed/blocked/neutral), persistance du label,
 * actions rapides, agrégation des IP vues, et protection des routes d'écriture.
 */
class IpEntriesManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    public function test_le_label_survit_a_un_changement_de_statut(): void
    {
        $admin = $this->admin();
        $entry = IpEntry::create(['ip' => '203.0.113.5', 'label' => 'Bureau Lyon', 'status' => 'allowed', 'created_by' => $admin->id]);

        // On bloque l'IP : le libellé doit rester.
        $this->actingAs($admin)->patch("/admin/ip/{$entry->id}", ['status' => 'blocked'])->assertRedirect();
        $entry->refresh();
        $this->assertSame('blocked', $entry->status);
        $this->assertSame('Bureau Lyon', $entry->label);

        // On repasse en neutral : libellé toujours là.
        $this->actingAs($admin)->patch("/admin/ip/{$entry->id}", ['status' => 'neutral'])->assertRedirect();
        $this->assertSame('Bureau Lyon', $entry->fresh()->label);
    }

    public function test_upsert_dune_ip_deja_classee_conserve_le_label_si_absent(): void
    {
        $admin = $this->admin();
        IpEntry::create(['ip' => '203.0.113.9', 'label' => 'Site A', 'status' => 'neutral', 'created_by' => $admin->id]);

        // POST sans label -> ne doit pas écraser le libellé existant.
        $this->actingAs($admin)->post('/admin/ip', ['ip' => '203.0.113.9', 'status' => 'blocked'])->assertRedirect();

        $entry = IpEntry::where('ip', '203.0.113.9')->first();
        $this->assertSame('blocked', $entry->status);
        $this->assertSame('Site A', $entry->label);
    }

    public function test_destroy_conserve_lentree_en_neutral_si_elle_a_un_label(): void
    {
        $admin = $this->admin();
        $entry = IpEntry::create(['ip' => '203.0.113.11', 'label' => 'Gardé', 'status' => 'allowed', 'created_by' => $admin->id]);

        $this->actingAs($admin)->delete("/admin/ip/{$entry->id}")->assertRedirect();

        $fresh = IpEntry::where('ip', '203.0.113.11')->first();
        $this->assertNotNull($fresh);
        $this->assertSame('neutral', $fresh->status);
        $this->assertSame('Gardé', $fresh->label);
    }

    public function test_nommer_une_ip_vue_cree_une_entree_neutral(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/ip', ['ip' => '198.51.100.20', 'status' => 'neutral', 'label' => 'Observée'])->assertRedirect();

        $this->assertDatabaseHas('ip_entries', ['ip' => '198.51.100.20', 'status' => 'neutral', 'label' => 'Observée']);
    }

    public function test_format_ip_invalide_rejete(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/ip', ['ip' => 'pas-une-ip', 'status' => 'allowed'])
            ->assertSessionHasErrors('ip');

        $this->assertDatabaseMissing('ip_entries', ['label' => null]);
    }

    public function test_statut_invalide_rejete(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/ip', ['ip' => '203.0.113.30', 'status' => 'nimporte'])
            ->assertSessionHasErrors('status');
    }

    public function test_la_section_ip_vues_agrege_les_logs(): void
    {
        $admin = $this->admin();
        $account = EbpAccount::factory()->create();
        $token = AppToken::create(['name' => 'App démo', 'token_hash' => 'h', 'ebp_account_id' => $account->id, 'is_active' => true]);

        // 3 appels de la même IP via la même app, à des dates différentes.
        RequestLog::create(['occurred_at' => now()->subDays(2), 'app_token_id' => $token->id, 'ebp_account_id' => $account->id, 'ip' => '203.0.113.99', 'method' => 'GET', 'http_status' => 200, 'success' => true]);
        RequestLog::create(['occurred_at' => now()->subDay(), 'app_token_id' => $token->id, 'ebp_account_id' => $account->id, 'ip' => '203.0.113.99', 'method' => 'GET', 'http_status' => 200, 'success' => true]);
        RequestLog::create(['occurred_at' => now(), 'app_token_id' => $token->id, 'ebp_account_id' => $account->id, 'ip' => '203.0.113.99', 'method' => 'GET', 'http_status' => 200, 'success' => true]);
        // Une autre IP, un seul appel.
        RequestLog::create(['occurred_at' => now(), 'app_token_id' => $token->id, 'ebp_account_id' => $account->id, 'ip' => '203.0.113.42', 'method' => 'GET', 'http_status' => 200, 'success' => true]);

        // Cette IP est déjà bloquée : la section doit le refléter.
        IpEntry::create(['ip' => '203.0.113.42', 'label' => 'Déjà classée', 'status' => 'blocked', 'created_by' => $admin->id]);

        $this->actingAs($admin)
            ->get('/admin/ip')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Ip')
                ->has('seen', 2)
                // La plus fréquente d'abord.
                ->where('seen.0.ip', '203.0.113.99')
                ->where('seen.0.hits', 3)
                ->where('seen.0.app', 'App démo')
                ->where('seen.0.status', 'neutral')
                ->where('seen.1.ip', '203.0.113.42')
                ->where('seen.1.status', 'blocked')
                ->where('seen.1.label', 'Déjà classée'));
    }

    public function test_les_routes_decriture_exigent_lauthentification(): void
    {
        $entry = IpEntry::create(['ip' => '203.0.113.5', 'status' => 'allowed']);

        $this->post('/admin/ip', ['ip' => '203.0.113.6', 'status' => 'allowed'])->assertRedirect('/login');
        $this->patch("/admin/ip/{$entry->id}", ['status' => 'blocked'])->assertRedirect('/login');
        $this->delete("/admin/ip/{$entry->id}")->assertRedirect('/login');

        // Rien n'a changé.
        $this->assertSame('allowed', $entry->fresh()->status);
        $this->assertDatabaseMissing('ip_entries', ['ip' => '203.0.113.6']);
    }
}
