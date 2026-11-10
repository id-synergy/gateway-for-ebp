<?php

namespace Tests\Feature;

use App\Models\EbpAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Déblocage manuel : l'admin colle le retour EBP (URL complète ou code) pour forcer
 * l'échange code→token sans que le redirect_uri whitelisté pointe sur la gateway.
 */
class ManualTokenExchangeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    private function fakeEbp(): void
    {
        Http::fake([
            config('ebp.token_url') => Http::response([
                'access_token' => 'A', 'refresh_token' => 'R', 'expires_in' => 3600,
            ]),
            '*/gescom/api/v1/Folders*' => Http::response(['folders' => []]),
        ]);
    }

    public function test_coller_lurl_complete_echange_le_code(): void
    {
        $this->fakeEbp();
        $account = EbpAccount::factory()->create();

        $this->actingAs($this->admin())
            ->post("/admin/comptes/{$account->id}/manual-token", [
                'callback' => 'http://localhost:3333/callback?code=CODE123&state=XYZ',
            ])
            ->assertRedirect("/admin/comptes/{$account->id}")
            ->assertSessionHas('success');

        $this->assertDatabaseHas('ebp_tokens', ['ebp_account_id' => $account->id, 'state' => 'healthy']);
        $this->assertSame('connected', $account->fresh()->status);
    }

    public function test_coller_le_code_seul_fonctionne(): void
    {
        $this->fakeEbp();
        $account = EbpAccount::factory()->create();

        $this->actingAs($this->admin())
            ->post("/admin/comptes/{$account->id}/manual-token", ['callback' => 'CODE_BRUT'])
            ->assertRedirect("/admin/comptes/{$account->id}")
            ->assertSessionHas('success');

        $this->assertDatabaseHas('ebp_tokens', ['ebp_account_id' => $account->id]);
    }

    public function test_state_present_en_session_doit_correspondre(): void
    {
        $this->fakeEbp();
        $account = EbpAccount::factory()->create();

        // Un state est en session (connect lancé dans ce navigateur) mais ne correspond pas.
        $this->actingAs($this->admin())
            ->withSession(['ebp_oauth_state' => 'BON', 'ebp_oauth_account' => $account->id])
            ->post("/admin/comptes/{$account->id}/manual-token", [
                'callback' => 'http://localhost:3333/callback?code=C&state=MAUVAIS',
            ])
            ->assertRedirect("/admin/comptes/{$account->id}")
            ->assertSessionHas('error');

        $this->assertDatabaseCount('ebp_tokens', 0);
    }

    public function test_code_obtenu_ailleurs_est_tolere_sans_state_en_session(): void
    {
        $this->fakeEbp();
        $account = EbpAccount::factory()->create();

        // Pas de state en session (code collé depuis une autre machine) : toléré.
        $this->actingAs($this->admin())
            ->post("/admin/comptes/{$account->id}/manual-token", [
                'callback' => 'http://localhost:3333/callback?code=C&state=PEU_IMPORTE',
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('ebp_tokens', ['ebp_account_id' => $account->id]);
    }

    public function test_un_retour_en_erreur_est_refuse(): void
    {
        $account = EbpAccount::factory()->create();

        $this->actingAs($this->admin())
            ->post("/admin/comptes/{$account->id}/manual-token", [
                'callback' => 'http://localhost:3333/callback?error=access_denied&state=X',
            ])
            ->assertSessionHas('error');

        $this->assertDatabaseCount('ebp_tokens', 0);
    }

    public function test_le_redirect_uri_personnalise_est_utilise_a_lechange(): void
    {
        Http::fake([
            config('ebp.token_url') => Http::response([
                'access_token' => 'A', 'refresh_token' => 'R', 'expires_in' => 3600,
            ]),
            '*/gescom/api/v1/Folders*' => Http::response(['folders' => []]),
        ]);

        $account = EbpAccount::factory()->create([
            'environment' => 'production',
            'redirect_mode' => 'custom',
            'custom_redirect_uri' => 'http://localhost:3333/callback',
        ]);

        $this->actingAs($this->admin())
            ->post("/admin/comptes/{$account->id}/manual-token", ['callback' => 'CODE']);

        // L'échange a bien envoyé le redirect_uri personnalisé du compte.
        Http::assertSent(fn ($request) => $request->url() === config('ebp.token_url')
            && ($request['redirect_uri'] ?? null) === 'http://localhost:3333/callback');
    }

    public function test_le_mode_localhost_utilise_le_redirect_de_config(): void
    {
        config(['ebp.localhost_redirect_uri' => 'http://localhost:3333/cb']);
        $this->fakeEbp();

        $account = EbpAccount::factory()->create([
            'environment' => 'production', 'redirect_mode' => 'localhost',
        ]);

        $this->actingAs($this->admin())
            ->post("/admin/comptes/{$account->id}/manual-token", ['callback' => 'CODE']);

        Http::assertSent(fn ($request) => $request->url() === config('ebp.token_url')
            && ($request['redirect_uri'] ?? null) === 'http://localhost:3333/cb');
    }

    public function test_le_mode_gateway_ignore_le_custom_redirect(): void
    {
        $this->fakeEbp();

        // Mode gateway : même si une URL custom traîne, on utilise le redirect_uri global.
        $account = EbpAccount::factory()->create([
            'environment' => 'production', 'redirect_mode' => 'gateway',
            'custom_redirect_uri' => 'http://localhost:9999/x',
        ]);

        $this->actingAs($this->admin())
            ->post("/admin/comptes/{$account->id}/manual-token", ['callback' => 'CODE']);

        Http::assertSent(fn ($request) => $request->url() === config('ebp.token_url')
            && ($request['redirect_uri'] ?? null) === config('ebp.redirect_uri'));
    }
}
