<?php

namespace Tests\Feature;

use App\Models\AccountEvent;
use App\Models\AppToken;
use App\Models\EbpAccount;
use App\Models\User;
use App\Services\Accounts\AccountEventLogger;
use App\Services\Apps\AppTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Journal d'activité PAR compte : connexion/reconnexion, renouvellement de token,
 * refus du portail, synchronisation de dossiers, actions sur les jetons d'apps.
 * Affiché en timeline sur la fiche du compte (scroll infini + actualisation).
 */
class AccountEventsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    public function test_la_connexion_journalise_connected_et_folders_synced(): void
    {
        Http::fake([
            config('ebp.token_url') => Http::response([
                'access_token' => 'ACCESS_1', 'refresh_token' => 'REFRESH_1', 'expires_in' => 3600,
            ]),
            '*/gescom/api/v1/Folders*' => Http::response([
                'folders' => [['id' => '3691', 'name' => 'Principal', 'shortName' => 'INV40']],
            ]),
        ]);

        $account = EbpAccount::factory()->create();

        $this->withSession(['ebp_oauth_state' => 'S', 'ebp_oauth_account' => $account->id])
            ->get('/oauth/ebp/callback?code=AUTH&state=S')
            ->assertRedirect('/admin/comptes');

        $this->assertDatabaseHas('account_events', [
            'ebp_account_id' => $account->id, 'type' => 'connected', 'level' => 'success',
        ]);
        $this->assertDatabaseHas('account_events', [
            'ebp_account_id' => $account->id, 'type' => 'folders_synced', 'level' => 'info',
        ]);
    }

    public function test_une_deuxieme_connexion_journalise_reconnected(): void
    {
        Http::fake([
            config('ebp.token_url') => Http::response([
                'access_token' => 'A', 'refresh_token' => 'R', 'expires_in' => 3600,
            ]),
            '*/gescom/api/v1/Folders*' => Http::response(['folders' => []]),
        ]);

        $account = EbpAccount::factory()->create();

        // 1re connexion → connected
        $this->withSession(['ebp_oauth_state' => 'S1', 'ebp_oauth_account' => $account->id])
            ->get('/oauth/ebp/callback?code=A&state=S1');

        // 2e connexion (token déjà présent) → reconnected
        $this->withSession(['ebp_oauth_state' => 'S2', 'ebp_oauth_account' => $account->id])
            ->get('/oauth/ebp/callback?code=B&state=S2');

        $this->assertDatabaseHas('account_events', [
            'ebp_account_id' => $account->id, 'type' => 'reconnected',
        ]);
    }

    public function test_un_refus_du_portail_journalise_denied(): void
    {
        $account = EbpAccount::factory()->create();

        $this->withSession(['ebp_oauth_state' => 'S', 'ebp_oauth_account' => $account->id])
            ->get('/oauth/ebp/callback?error=access_denied&state=S')
            ->assertRedirect('/admin/comptes')
            ->assertSessionHas('error');

        $this->assertDatabaseHas('account_events', [
            'ebp_account_id' => $account->id, 'type' => 'denied', 'level' => 'warning',
        ]);
    }

    public function test_les_actions_sur_les_jetons_sont_journalisees(): void
    {
        $account = EbpAccount::factory()->create();
        $admin = $this->admin();

        // Création
        $this->actingAs($admin)->post('/admin/jetons', [
            'name' => 'App Facturation', 'ebp_account_id' => $account->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('account_events', [
            'ebp_account_id' => $account->id, 'type' => 'app_token_created',
        ]);

        // Révocation
        $token = AppToken::where('ebp_account_id', $account->id)->firstOrFail();
        $this->actingAs($admin)->delete("/admin/jetons/{$token->id}")->assertRedirect();

        $this->assertDatabaseHas('account_events', [
            'ebp_account_id' => $account->id, 'type' => 'app_token_revoked',
        ]);
    }

    public function test_le_renouvellement_du_token_journalise_token_refreshed(): void
    {
        Http::fake([
            config('ebp.token_url') => Http::response([
                'access_token' => 'A2', 'refresh_token' => 'R2', 'expires_in' => 3600,
            ]),
        ]);

        $account = EbpAccount::factory()->create();
        $account->token()->create([
            'access_token' => 'OLD', 'refresh_token' => 'OLDR', 'state' => 'healthy',
            'access_token_expires_at' => now()->subMinute(),
            'refresh_token_expires_at' => now()->addDays(30),
        ]);

        app(\App\Services\Ebp\EbpTokenService::class)->refresh($account->fresh());

        $this->assertDatabaseHas('account_events', [
            'ebp_account_id' => $account->id, 'type' => 'token_refreshed',
        ]);
    }

    public function test_le_detail_du_compte_expose_la_premiere_page_devenements(): void
    {
        $account = EbpAccount::factory()->create();
        AccountEvent::factory()->count(3)->create(['ebp_account_id' => $account->id]);

        $this->actingAs($this->admin())
            ->get("/admin/comptes/{$account->id}")
            ->assertInertia(fn ($page) => $page->component('Admin/CompteDetail')
                ->has('events.items', 3)
                ->where('events.hasMore', false));
    }

    public function test_lendpoint_pagine_les_evenements_avec_un_curseur(): void
    {
        $account = EbpAccount::factory()->create();
        // 25 événements > 20 par page → 2e page via curseur.
        AccountEvent::factory()->count(25)->create(['ebp_account_id' => $account->id]);

        $admin = $this->admin();

        $first = $this->actingAs($admin)->getJson("/admin/comptes/{$account->id}/events");
        $first->assertOk()->assertJsonCount(20, 'items')->assertJsonPath('hasMore', true);

        $cursor = $first->json('nextCursor');
        $this->assertNotNull($cursor);

        $second = $this->actingAs($admin)->getJson("/admin/comptes/{$account->id}/events?before={$cursor}");
        $second->assertOk()->assertJsonCount(5, 'items')->assertJsonPath('hasMore', false);
    }
}
