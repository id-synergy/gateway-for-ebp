<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_oauth_connect_exige_une_connexion(): void
    {
        $this->get('/oauth/ebp/connect?account=1')->assertRedirect('/login');
    }

    public function test_2fa_impose_redirige_vers_le_profil(): void
    {
        config(['ebp.security.enforce_2fa' => true]);

        $user = User::factory()->create(['is_active' => true]); // pas de 2FA confirmée

        // Accès à un écran normal -> redirigé vers le profil.
        $this->actingAs($user)->get('/admin/comptes')->assertRedirect('/admin/profil');
        // Le profil reste accessible pour activer la 2FA.
        $this->actingAs($user)->get('/admin/profil')->assertOk();
    }

    public function test_2fa_non_impose_laisse_passer(): void
    {
        config(['ebp.security.enforce_2fa' => false]);

        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)->get('/admin/comptes')->assertOk();
    }
}
