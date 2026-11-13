<?php

namespace Tests\Feature;

use App\Models\AdminAuthEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Page back-office « Connexions » (journal d'authentification des admins)
 * et détection best-effort de l'expiration de session.
 */
class ConnexionLogPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    public function test_un_invite_est_redirige_vers_login(): void
    {
        $this->get('/admin/connexions')->assertRedirect('/login');
    }

    public function test_la_page_rend_le_composant_avec_les_evenements_en_props(): void
    {
        $admin = $this->admin();
        AdminAuthEvent::factory()->login()->create(['user_id' => $admin->id, 'ip' => '203.0.113.5']);
        AdminAuthEvent::factory()->failed()->create(['email' => 'intrus@exemple.fr']);

        $this->actingAs($admin)
            ->get('/admin/connexions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Connexions')
                ->has('events.data', 2)
                ->has('userOptions')
                ->has('eventOptions', 6));
    }

    public function test_la_page_filtre_par_admin(): void
    {
        $a = $this->admin();
        $b = $this->admin();
        AdminAuthEvent::factory()->login()->create(['user_id' => $a->id]);
        AdminAuthEvent::factory()->login()->create(['user_id' => $b->id]);

        $this->actingAs($a)
            ->get('/admin/connexions?user='.$a->id)
            ->assertInertia(fn ($page) => $page
                ->has('events.data', 1)
                ->where('events.data.0.adminName', $a->name));
    }

    public function test_la_page_filtre_par_type_d_evenement(): void
    {
        $admin = $this->admin();
        AdminAuthEvent::factory()->login()->create(['user_id' => $admin->id]);
        AdminAuthEvent::factory()->logout()->create(['user_id' => $admin->id]);

        $this->actingAs($admin)
            ->get('/admin/connexions?event='.AdminAuthEvent::EVENT_LOGOUT)
            ->assertInertia(fn ($page) => $page
                ->has('events.data', 1)
                ->where('events.data.0.event', AdminAuthEvent::EVENT_LOGOUT));
    }

    public function test_la_page_filtre_par_ip(): void
    {
        $admin = $this->admin();
        AdminAuthEvent::factory()->login()->create(['user_id' => $admin->id, 'ip' => '203.0.113.5']);
        AdminAuthEvent::factory()->login()->create(['user_id' => $admin->id, 'ip' => '198.51.100.9']);

        $this->actingAs($admin)
            ->get('/admin/connexions?ip=203.0.113')
            ->assertInertia(fn ($page) => $page
                ->has('events.data', 1)
                ->where('events.data.0.ip', '203.0.113.5'));
    }

    // L'expiration de session (session_expired) est couverte par le test unitaire
    // du middleware : Tests\Unit\TrackAdminSessionExpiryTest. On ne la teste pas au
    // travers d'une requête HTTP car le driver de session de test (array) ne conserve
    // pas l'état entre requêtes, ce qui rend l'aller-retour non pertinent ici.
}
