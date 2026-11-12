<?php

namespace Tests\Feature;

use App\Models\AppToken;
use App\Models\AppTokenRouteRule;
use App\Models\EbpAccount;
use App\Models\EbpFolder;
use App\Models\User;
use App\Services\Apps\AppTokenService;
use App\Services\Ebp\EbpTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Contexte d'accès par app : les règles de route d'un jeton filtrent les appels proxy EBP,
 * sans toucher aux fonctions internes de la gateway (/api/v1).
 */
class AppTokenRouteRulesTest extends TestCase
{
    use RefreshDatabase;

    private EbpAccount $account;

    private EbpFolder $folder;

    private string $plain;

    private AppToken $token;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['ebp.quota.per_second' => 1000]);

        $this->account = EbpAccount::factory()->create(['is_active' => true]);
        Http::fake([config('ebp.token_url') => Http::response(['access_token' => 'A1', 'refresh_token' => 'R1', 'expires_in' => 3600])]);
        app(EbpTokenService::class)->exchangeCode($this->account, 'CODE');

        $this->folder = EbpFolder::factory()->create(['ebp_account_id' => $this->account->id, 'folder_id' => '3691']);
        [$this->token, $this->plain] = app(AppTokenService::class)->create('App', $this->account->fresh(), $this->folder);
    }

    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->plain}"];
    }

    private function addRule(array $methods, string $pattern, int $pos = 0): void
    {
        AppTokenRouteRule::create([
            'app_token_id' => $this->token->id,
            'methods' => $methods,
            'pattern' => $pattern,
            'position' => $pos,
        ]);
    }

    // --- Défaut OUVERT : jeton sans règle = accès global inchangé ---

    public function test_jeton_sans_regle_accede_a_tout_le_perimetre(): void
    {
        Http::fake(['*' => Http::response(['results' => []], 200)]);

        $this->getJson('/gescom/api/v1/Clients', $this->auth())->assertOk();
        $this->getJson('/batiment/api/v1/Clients', $this->auth())->assertOk();
    }

    // --- Jeton restreint ---

    public function test_route_dans_le_perimetre_autorisee(): void
    {
        Http::fake(['*' => Http::response(['results' => []], 200)]);
        $this->addRule(['GET'], 'gescom/api/v1/Clients');

        $this->getJson('/gescom/api/v1/Clients', $this->auth())->assertOk();
    }

    public function test_route_hors_perimetre_403(): void
    {
        Http::fake(['*' => Http::response(['results' => []], 200)]);
        $this->addRule(['GET'], 'gescom/api/v1/Clients');

        $this->getJson('/gescom/api/v1/Items/ItemsExtendedQuery', $this->auth())
            ->assertStatus(403)
            ->assertJson(['error' => 'route_not_allowed']);
    }

    public function test_verbe_non_autorise_403(): void
    {
        Http::fake(['*' => Http::response(['results' => []], 200)]);
        // Lecture seule sur les clients.
        $this->addRule(['GET'], 'gescom/api/v1/Clients');

        $this->postJson('/gescom/api/v1/Clients', ['Name' => 'X'], $this->auth())
            ->assertStatus(403)
            ->assertJson(['error' => 'route_not_allowed']);
    }

    public function test_joker_etoile_dans_une_regle(): void
    {
        Http::fake(['*' => Http::response(['results' => []], 200)]);
        $this->addRule(['GET'], 'gescom/api/v1/Items/**');

        $this->getJson('/gescom/api/v1/Items/ItemsExtendedQuery', $this->auth())->assertOk();
        $this->getJson('/gescom/api/v1/Clients', $this->auth())->assertStatus(403);
    }

    public function test_plusieurs_regles_en_ou(): void
    {
        Http::fake(['*' => Http::response(['results' => []], 200)]);
        $this->addRule(['GET'], 'gescom/api/v1/Clients', 0);
        $this->addRule(['GET', 'POST'], 'gescom/api/v1/Documents/**', 1);

        $this->getJson('/gescom/api/v1/Clients', $this->auth())->assertOk();
        $this->getJson('/gescom/api/v1/Documents/SaleDocument', $this->auth())->assertOk();
        $this->postJson('/gescom/api/v1/Documents/SaleDocument', [], $this->auth())->assertOk();
        $this->getJson('/gescom/api/v1/Deals/DealsExtendedQuery', $this->auth())->assertStatus(403);
    }

    // --- Les fonctions gateway /api/v1 ne sont JAMAIS filtrées par les règles ---

    public function test_les_fonctions_gateway_ne_sont_pas_filtrees(): void
    {
        // Jeton restreint à une seule route EBP : whoami doit malgré tout répondre.
        $this->addRule(['GET'], 'gescom/api/v1/Clients');

        $this->getJson('/api/v1/whoami', $this->auth())->assertOk();
    }

    public function test_jobs_accessibles_meme_restreint(): void
    {
        $this->addRule(['GET'], 'gescom/api/v1/Clients');

        // La liste des jobs est une fonction gateway : pas un chemin proxy EBP.
        $this->getJson('/api/v1/jobs', $this->auth())->assertOk();
    }

    // --- CRUD back-office des règles ---

    private function admin(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    public function test_enregistrement_des_regles_depuis_le_back_office(): void
    {
        $this->actingAs($this->admin())
            ->put("/admin/jetons/{$this->token->id}/regles", [
                'rules' => [
                    ['methods' => ['GET'], 'pattern' => 'gescom/api/v1/Clients'],
                    ['methods' => ['GET', 'POST'], 'pattern' => '/gescom/api/v1/Items/**/'],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('app_token_route_rules', [
            'app_token_id' => $this->token->id,
            'pattern' => 'gescom/api/v1/Clients',
        ]);
        // Le pattern est débarrassé de ses slashs de tête/queue.
        $this->assertDatabaseHas('app_token_route_rules', [
            'app_token_id' => $this->token->id,
            'pattern' => 'gescom/api/v1/Items/**',
        ]);
        $this->assertSame(2, $this->token->routeRules()->count());
    }

    public function test_liste_vide_rouvre_le_jeton(): void
    {
        $this->addRule(['GET'], 'gescom/api/v1/Clients');

        $this->actingAs($this->admin())
            ->put("/admin/jetons/{$this->token->id}/regles", ['rules' => []])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(0, $this->token->routeRules()->count());
        $this->assertFalse($this->token->fresh()->isRouteRestricted());
    }

    public function test_remplacement_complet_des_regles(): void
    {
        $this->addRule(['GET'], 'gescom/api/v1/Clients');

        $this->actingAs($this->admin())
            ->put("/admin/jetons/{$this->token->id}/regles", [
                'rules' => [['methods' => ['GET'], 'pattern' => 'batiment/api/v1/Clients']],
            ])
            ->assertRedirect();

        // L'ancienne règle a disparu, seule la nouvelle subsiste.
        $this->assertDatabaseMissing('app_token_route_rules', [
            'app_token_id' => $this->token->id,
            'pattern' => 'gescom/api/v1/Clients',
        ]);
        $this->assertSame(1, $this->token->routeRules()->count());
    }

    public function test_verbe_invalide_rejete(): void
    {
        $this->actingAs($this->admin())
            ->putJson("/admin/jetons/{$this->token->id}/regles", [
                'rules' => [['methods' => ['FETCH'], 'pattern' => 'gescom/api/v1/Clients']],
            ])
            ->assertStatus(422);
    }

    public function test_pattern_vide_rejete(): void
    {
        $this->actingAs($this->admin())
            ->putJson("/admin/jetons/{$this->token->id}/regles", [
                'rules' => [['methods' => ['GET'], 'pattern' => '']],
            ])
            ->assertStatus(422);
    }

    public function test_page_jetons_expose_regles_et_catalogue(): void
    {
        $this->addRule(['GET'], 'gescom/api/v1/Clients');

        $this->actingAs($this->admin())
            ->get('/admin/jetons')
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Jetons')
                ->has('tokens.0.rules', 1)
                ->has('routeCatalog')
            );
    }
}
