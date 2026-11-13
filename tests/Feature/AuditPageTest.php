<?php

namespace Tests\Feature;

use App\Models\AdminAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Page back-office « Journal d'actions » (audit trail) : rendu du composant, props,
 * filtres et protection par authentification.
 */
class AuditPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    public function test_un_invite_est_redirige_vers_login(): void
    {
        $this->get('/admin/audit')->assertRedirect('/login');
    }

    public function test_la_page_rend_le_composant_avec_les_actions_en_props(): void
    {
        $admin = $this->admin();
        AdminAction::factory()->create(['user_id' => $admin->id, 'action' => 'account.created']);
        AdminAction::factory()->create(['user_id' => $admin->id, 'action' => 'token.revoked']);

        $this->actingAs($admin)
            ->get('/admin/audit')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Audit')
                ->has('actions.data', 2)
                ->has('userOptions')
                ->has('actionOptions'));
    }

    public function test_la_page_filtre_par_admin(): void
    {
        $a = $this->admin();
        $b = $this->admin();
        AdminAction::factory()->create(['user_id' => $a->id]);
        AdminAction::factory()->create(['user_id' => $b->id]);

        $this->actingAs($a)
            ->get('/admin/audit?user='.$a->id)
            ->assertInertia(fn ($page) => $page
                ->has('actions.data', 1)
                ->where('actions.data.0.adminName', $a->name));
    }

    public function test_la_page_filtre_par_action(): void
    {
        $admin = $this->admin();
        AdminAction::factory()->create(['user_id' => $admin->id, 'action' => 'account.created']);
        AdminAction::factory()->create(['user_id' => $admin->id, 'action' => 'ip.blocked']);

        $this->actingAs($admin)
            ->get('/admin/audit?action=ip.blocked')
            ->assertInertia(fn ($page) => $page
                ->has('actions.data', 1)
                ->where('actions.data.0.action', 'ip.blocked'));
    }

    public function test_la_page_recherche_par_label_ou_ip(): void
    {
        $admin = $this->admin();
        AdminAction::factory()->create(['user_id' => $admin->id, 'label' => 'Client Alpha', 'ip' => '203.0.113.5']);
        AdminAction::factory()->create(['user_id' => $admin->id, 'label' => 'Client Beta', 'ip' => '198.51.100.9']);

        $this->actingAs($admin)
            ->get('/admin/audit?search=Alpha')
            ->assertInertia(fn ($page) => $page
                ->has('actions.data', 1)
                ->where('actions.data.0.label', 'Client Alpha'));
    }
}
