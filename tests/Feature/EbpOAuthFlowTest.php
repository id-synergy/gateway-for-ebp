<?php

namespace Tests\Feature;

use App\Models\EbpAccount;
use App\Models\EbpToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EbpOAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_connect_redirige_vers_ebp_et_pose_un_state(): void
    {
        $account = EbpAccount::factory()->create(['client_id' => 'jupiter']);

        // connect est réservé à un admin connecté.
        $response = $this->actingAs(User::factory()->create(['is_active' => true]))
            ->get("/oauth/ebp/connect?account={$account->id}");

        $response->assertRedirect();
        $this->assertStringContainsString('client_id=jupiter', $response->headers->get('Location'));
        $response->assertSessionHas('ebp_oauth_state');
        $response->assertSessionHas('ebp_oauth_account', $account->id);
    }

    public function test_callback_echange_le_code_et_enregistre_les_dossiers(): void
    {
        Http::fake([
            config('ebp.token_url') => Http::response([
                'access_token' => 'ACCESS_1',
                'refresh_token' => 'REFRESH_1',
                'expires_in' => 3600,
            ]),
            '*/gescom/api/v1/Folders*' => Http::response([
                'folders' => [
                    ['id' => '3691', 'name' => 'Dossier principal', 'shortName' => 'INV40'],
                ],
            ]),
        ]);

        $account = EbpAccount::factory()->create();

        $response = $this
            ->withSession([
                'ebp_oauth_state' => 'STATE123',
                'ebp_oauth_account' => $account->id,
            ])
            ->get('/oauth/ebp/callback?code=AUTH_CODE&state=STATE123');

        $response->assertRedirect('/admin/comptes');
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('ebp_tokens', ['ebp_account_id' => $account->id, 'state' => 'healthy']);
        $this->assertDatabaseHas('ebp_folders', ['ebp_account_id' => $account->id, 'folder_id' => '3691']);
        $this->assertSame('connected', $account->fresh()->status);
    }

    public function test_callback_refuse_un_state_invalide(): void
    {
        $account = EbpAccount::factory()->create();

        $response = $this
            ->withSession([
                'ebp_oauth_state' => 'BON_STATE',
                'ebp_oauth_account' => $account->id,
            ])
            ->get('/oauth/ebp/callback?code=AUTH_CODE&state=MAUVAIS_STATE');

        $response->assertForbidden();
        $this->assertDatabaseCount('ebp_tokens', 0);
    }

    public function test_callback_refuse_un_state_vide(): void
    {
        $account = EbpAccount::factory()->create();

        // Un callback sans state (forgé) ne doit jamais passer, même si la session en a un.
        $this->withSession(['ebp_oauth_state' => 'BON_STATE', 'ebp_oauth_account' => $account->id])
            ->get('/oauth/ebp/callback?code=AUTH_CODE')
            ->assertForbidden();

        $this->assertDatabaseCount('ebp_tokens', 0);
    }

    public function test_callback_sans_code_affiche_une_erreur_sans_rien_enregistrer(): void
    {
        // L'utilisateur a refusé le consentement / EBP revient sans code.
        $account = EbpAccount::factory()->create();

        $this->withSession(['ebp_oauth_state' => 'S', 'ebp_oauth_account' => $account->id])
            ->get('/oauth/ebp/callback?state=S')
            ->assertRedirect('/admin/comptes')
            ->assertSessionHas('error');

        $this->assertDatabaseCount('ebp_tokens', 0);
        $this->assertNotSame('connected', $account->fresh()->status);
    }

    public function test_callback_echange_en_echec_affiche_une_erreur(): void
    {
        // EBP refuse le code (expiré / déjà utilisé) : 400 invalid_grant.
        Http::fake([config('ebp.token_url') => Http::response(['error' => 'invalid_grant'], 400)]);

        $account = EbpAccount::factory()->create();

        $this->withSession(['ebp_oauth_state' => 'S', 'ebp_oauth_account' => $account->id])
            ->get('/oauth/ebp/callback?code=CODE_EXPIRE&state=S')
            ->assertRedirect('/admin/comptes')
            ->assertSessionHas('error');

        // Aucun token stocké, le compte n'est pas marqué connecté.
        $this->assertDatabaseCount('ebp_tokens', 0);
        $this->assertNotSame('connected', $account->fresh()->status);
    }

    public function test_callback_sur_compte_inexistant_404(): void
    {
        $this->withSession(['ebp_oauth_state' => 'S', 'ebp_oauth_account' => 999999])
            ->get('/oauth/ebp/callback?code=CODE&state=S')
            ->assertNotFound();
    }

    public function test_connect_exige_un_admin_connecte(): void
    {
        $account = EbpAccount::factory()->create();

        // Sans authentification admin, on ne peut pas démarrer une connexion OAuth.
        $this->get("/oauth/ebp/connect?account={$account->id}")->assertRedirect('/login');
    }
}
