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
}
