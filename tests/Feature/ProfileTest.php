<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_profil_est_accessible_et_indique_l_etat_2fa(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)->get('/admin/profil')
            ->assertInertia(fn ($page) => $page->component('Admin/Profile')
                ->where('profile.email', $user->email)
                ->where('profile.twoFactorEnabled', false));
    }

    public function test_le_profil_exige_une_connexion(): void
    {
        $this->get('/admin/profil')->assertRedirect('/login');
    }
}
