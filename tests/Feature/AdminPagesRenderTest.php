<?php

namespace Tests\Feature;

use App\Models\AppToken;
use App\Models\EbpAccount;
use App\Models\EbpFolder;
use App\Models\IpEntry;
use App\Models\RequestLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vérifie que les pages back-office qui affichent de vraies données passent bien le CONTENU
 * attendu en props Inertia (pas seulement qu'elles répondent). Complète les tests d'actions,
 * qui couvraient surtout les écritures.
 */
class AdminPagesRenderTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    public function test_le_tableau_de_bord_expose_stats_comptes_et_activite(): void
    {
        $account = EbpAccount::factory()->create(['label' => 'SRM', 'status' => 'connected']);
        RequestLog::create([
            'occurred_at' => now(), 'ebp_account_id' => $account->id,
            'method' => 'GET', 'ebp_endpoint' => 'gescom/api/v1/Clients',
            'http_status' => 200, 'success' => true,
        ]);

        $this->actingAs($this->admin())
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Dashboard')
                ->where('stats.accounts', 1)
                ->where('stats.calls24h', 1)
                ->has('accounts', 1)
                ->where('accounts.0.label', 'SRM')
                ->has('activity', 7) // 7 derniers jours
                ->has('recentCalls', 1));
    }

    public function test_la_page_comptes_liste_les_comptes_avec_leurs_dossiers(): void
    {
        $account = EbpAccount::factory()->create(['label' => 'DEMO']);
        EbpFolder::factory()->create([
            'ebp_account_id' => $account->id, 'folder_id' => '405032',
            'short_name' => 'SRM', 'label' => 'Dossier principal',
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/comptes')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Comptes')
                ->has('accounts', 1)
                ->where('accounts.0.label', 'DEMO')
                ->where('accounts.0.key', 'primaire')
                ->has('accounts.0.folders', 1)
                ->where('accounts.0.folders.0.id', '405032'));
    }

    public function test_la_page_ip_separe_autorisees_et_bloquees(): void
    {
        $author = $this->admin();
        IpEntry::create(['ip' => '203.0.113.7', 'label' => 'Bureau', 'status' => 'allowed', 'created_by' => $author->id]);
        IpEntry::create(['ip' => '198.51.100.9', 'label' => 'Scan', 'status' => 'blocked', 'created_by' => $author->id]);

        $this->actingAs($author)
            ->get('/admin/ip')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Ip')
                ->has('allowed', 1)
                ->where('allowed.0.ip', '203.0.113.7')
                ->where('allowed.0.label', 'Bureau')
                ->where('allowed.0.by', $author->name)
                ->has('blocked', 1)
                ->where('blocked.0.ip', '198.51.100.9')
                ->has('seen'));
    }

    public function test_la_page_admins_liste_les_utilisateurs(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/admin/admins')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Admins')
                ->has('admins')
                ->where('admins', fn ($admins) => collect($admins)->contains('email', $admin->email)));
    }

    public function test_la_page_jetons_expose_jetons_et_comptes(): void
    {
        $account = EbpAccount::factory()->create(['label' => 'SRM']);
        $folder = EbpFolder::factory()->create(['ebp_account_id' => $account->id, 'folder_id' => '405032']);
        AppToken::create([
            'name' => 'App tickets', 'token_hash' => hash('sha256', 'x'),
            'ebp_account_id' => $account->id, 'default_folder_id' => $folder->id, 'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/jetons')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Jetons')
                ->has('tokens', 1)
                ->where('tokens.0.name', 'App tickets')
                ->has('accounts', 1));
    }
}
