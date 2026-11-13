<?php

namespace Tests\Feature;

use App\Models\AdminAction;
use App\Models\AdminAuthEvent;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Alerts\AlertType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fiche détail d'un administrateur (/admin/admins/{user}) : rendu du composant, props,
 * isolation par personne (connexions et actions filtrées sur le seul admin visé) et
 * protection par authentification.
 */
class AdminDetailPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    public function test_un_invite_est_redirige_vers_login(): void
    {
        $user = $this->admin();

        $this->get("/admin/admins/{$user->id}")->assertRedirect('/login');
    }

    public function test_la_fiche_rend_le_composant_avec_le_bon_admin(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get("/admin/admins/{$admin->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/AdminDetail')
                ->where('admin.id', $admin->id)
                ->where('admin.email', $admin->email)
                ->has('summary')
                ->has('events')
                ->has('actions')
                ->has('preferences'));
    }

    public function test_les_connexions_et_actions_sont_filtrees_sur_cet_admin(): void
    {
        $alice = $this->admin();
        $bob = $this->admin();

        // Alice : 2 connexions + 1 action. Bob : 1 connexion + 1 action.
        AdminAuthEvent::factory()->count(2)->create(['user_id' => $alice->id, 'event' => AdminAuthEvent::EVENT_LOGIN]);
        AdminAuthEvent::factory()->create(['user_id' => $bob->id, 'event' => AdminAuthEvent::EVENT_LOGIN]);
        AdminAction::factory()->create(['user_id' => $alice->id, 'action' => 'account.created']);
        AdminAction::factory()->create(['user_id' => $bob->id, 'action' => 'ip.blocked']);

        // La fiche d'Alice ne montre QUE ses événements et actions à elle.
        $this->actingAs($alice)
            ->get("/admin/admins/{$alice->id}")
            ->assertInertia(fn ($page) => $page
                ->has('events', 2)
                ->has('actions', 1)
                ->where('actions.0.action', 'account.created')
                ->where('summary.loginCount', 2));

        // La fiche de Bob, ouverte par Alice, ne montre QUE les événements de Bob.
        $this->actingAs($alice)
            ->get("/admin/admins/{$bob->id}")
            ->assertInertia(fn ($page) => $page
                ->has('events', 1)
                ->has('actions', 1)
                ->where('actions.0.action', 'ip.blocked')
                ->where('summary.loginCount', 1));
    }

    public function test_le_resume_remonte_la_derniere_connexion_et_la_derniere_action(): void
    {
        $admin = $this->admin();

        AdminAuthEvent::factory()->create([
            'user_id' => $admin->id,
            'event' => AdminAuthEvent::EVENT_LOGIN,
            'occurred_at' => now()->subDays(2),
        ]);
        AdminAuthEvent::factory()->create([
            'user_id' => $admin->id,
            'event' => AdminAuthEvent::EVENT_LOGIN,
            'occurred_at' => now()->subHour(),
        ]);
        AdminAction::factory()->create([
            'user_id' => $admin->id,
            'label' => 'Client Alpha',
            'occurred_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($admin)
            ->get("/admin/admins/{$admin->id}")
            ->assertInertia(fn ($page) => $page
                ->where('summary.loginCount', 2)
                ->where('summary.lastActionLabel', 'Client Alpha')
                ->whereNot('summary.lastLoginAt', null));
    }

    public function test_la_liste_des_admins_expose_l_identifiant_pour_le_lien_detail(): void
    {
        $admin = $this->admin();

        // Le href côté Vue est construit à partir de admins[].id : on vérifie que la prop est fournie.
        $this->actingAs($admin)
            ->get('/admin/admins')
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Admins')
                ->where('admins.0.id', fn ($id) => is_int($id) && $id > 0));
    }

    public function test_les_preferences_de_cet_admin_sont_exposees(): void
    {
        $admin = $this->admin();
        $type = AlertType::configurable()[0];

        NotificationPreference::create([
            'user_id' => $admin->id,
            'alert_type' => $type,
            'enabled' => true,
        ]);

        $this->actingAs($admin)
            ->get("/admin/admins/{$admin->id}")
            ->assertInertia(fn ($page) => $page
                ->has('preferences')
                ->where('preferences', fn ($prefs) => collect($prefs)
                    ->firstWhere('type', $type)['enabled'] === true));
    }
}
