<?php

namespace Tests\Feature;

use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    public function test_un_admin_peut_inviter_et_un_mail_part(): void
    {
        Mail::fake();

        $this->actingAs($this->admin())
            ->post('/admin/invitations', ['name' => 'Jean Dupont', 'email' => 'jean@example.com'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('invitations', ['email' => 'jean@example.com', 'accepted_at' => null]);
        Mail::assertSent(InvitationMail::class);
    }

    public function test_inviter_un_email_deja_admin_est_refuse(): void
    {
        Mail::fake();
        $this->admin(); // crée un user
        $existing = User::factory()->create(['email' => 'pris@example.com']);

        $this->actingAs($this->admin())
            ->post('/admin/invitations', ['name' => 'X', 'email' => 'pris@example.com'])
            ->assertSessionHas('error');

        Mail::assertNothingSent();
    }

    public function test_la_page_dacceptation_saffiche_pour_un_token_valide(): void
    {
        $plain = Str::random(48);
        Invitation::create([
            'name' => 'Alice', 'email' => 'alice@example.com',
            'token' => Invitation::hashToken($plain),
            'expires_at' => now()->addDay(),
        ]);

        $this->get("/invitation/{$plain}")
            ->assertInertia(fn ($page) => $page->component('Auth/AcceptInvitation')
                ->where('email', 'alice@example.com'));
    }

    public function test_un_token_expire_montre_la_page_invalide(): void
    {
        $plain = Str::random(48);
        Invitation::create([
            'name' => 'Bob', 'email' => 'bob@example.com',
            'token' => Invitation::hashToken($plain),
            'expires_at' => now()->subHour(),
        ]);

        $this->get("/invitation/{$plain}")
            ->assertInertia(fn ($page) => $page->component('Auth/InvitationInvalid'));
    }

    public function test_un_token_inconnu_montre_la_page_invalide(): void
    {
        $this->get('/invitation/'.Str::random(48))
            ->assertInertia(fn ($page) => $page->component('Auth/InvitationInvalid'));
    }

    public function test_accepter_cree_le_compte_et_connecte(): void
    {
        $plain = Str::random(48);
        $inv = Invitation::create([
            'name' => 'Carol', 'email' => 'carol@example.com',
            'token' => Invitation::hashToken($plain),
            'is_default_contact' => true,
            'expires_at' => now()->addDay(),
        ]);

        $this->post("/invitation/{$plain}", [
            'password' => 'MotDePasseFort1',
            'password_confirmation' => 'MotDePasseFort1',
        ])->assertRedirect('/admin');

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'email' => 'carol@example.com', 'is_active' => true, 'is_default_contact' => true,
        ]);
        $this->assertNotNull($inv->fresh()->accepted_at);
    }

    public function test_un_mot_de_passe_faible_est_refuse(): void
    {
        $plain = Str::random(48);
        Invitation::create([
            'name' => 'Dan', 'email' => 'dan@example.com',
            'token' => Invitation::hashToken($plain),
            'expires_at' => now()->addDay(),
        ]);

        $this->post("/invitation/{$plain}", [
            'password' => '123', 'password_confirmation' => '123',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'dan@example.com']);
    }

    public function test_un_token_deja_accepte_ne_recree_pas_de_compte(): void
    {
        $plain = Str::random(48);
        Invitation::create([
            'name' => 'Eve', 'email' => 'eve@example.com',
            'token' => Invitation::hashToken($plain),
            'expires_at' => now()->addDay(),
            'accepted_at' => now(),
        ]);

        $this->post("/invitation/{$plain}", [
            'password' => 'MotDePasseFort1', 'password_confirmation' => 'MotDePasseFort1',
        ])->assertRedirect('/login');

        $this->assertDatabaseMissing('users', ['email' => 'eve@example.com']);
    }
}
