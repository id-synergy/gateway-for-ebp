<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_back_office_exige_une_connexion(): void
    {
        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_un_admin_connecte_accede_au_back_office(): void
    {
        $admin = User::factory()->create(['is_active' => true]);

        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->actingAs($admin)->get('/admin/comptes')->assertOk();
    }
}
