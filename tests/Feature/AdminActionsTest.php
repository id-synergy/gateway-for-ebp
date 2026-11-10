<?php

namespace Tests\Feature;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminActionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    public function test_creation_dun_admin(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/admins', [
                'name' => 'Nouvel Admin',
                'email' => 'nouveau@gateway.test',
                'password' => 'motdepasse12',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'nouveau@gateway.test', 'is_active' => true]);
    }

    public function test_desactivation_dun_admin(): void
    {
        $admin = $this->admin();
        $cible = User::factory()->create(['is_active' => true]);

        $this->actingAs($admin)->patch("/admin/admins/{$cible->id}/toggle")->assertRedirect();

        $this->assertFalse($cible->fresh()->is_active);
    }

    public function test_on_ne_desactive_pas_le_dernier_admin(): void
    {
        $seul = $this->admin();

        $this->actingAs($seul)->patch("/admin/admins/{$seul->id}/toggle");

        $this->assertTrue($seul->fresh()->is_active);
    }

    public function test_bascule_dune_preference(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/preferences', [
            'user_id' => $admin->id,
            'alert_type' => 'logs_purge_failed',
            'enabled' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $admin->id,
            'alert_type' => 'logs_purge_failed',
            'enabled' => true,
        ]);
    }
}
