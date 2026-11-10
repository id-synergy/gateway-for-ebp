<?php

namespace Tests\Feature;

use App\Models\AppToken;
use App\Models\EbpAccount;
use App\Models\EbpFolder;
use App\Models\PairingCode;
use App\Models\User;
use App\Services\Apps\AppTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Appairage sécurisé : le QR ne transmet qu'un code à usage unique (10 min), échangé contre
 * le vrai jeton via l'endpoint public. Génération protégée par mot de passe admin.
 */
class PairingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $password = 'password-admin-123'): User
    {
        return User::factory()->create(['is_active' => true, 'password' => Hash::make($password)]);
    }

    private function token(): AppToken
    {
        $account = EbpAccount::factory()->create();
        $folder = EbpFolder::factory()->create(['ebp_account_id' => $account->id]);
        [$token] = app(AppTokenService::class)->create('App mobile', $account, $folder);

        return $token;
    }

    public function test_generer_un_appairage_exige_le_bon_mot_de_passe(): void
    {
        $admin = $this->admin('secret-123456');
        $token = $this->token();

        // Mauvais mot de passe : refusé, aucun code créé.
        $this->actingAs($admin)
            ->postJson("/admin/jetons/{$token->id}/pairing", ['password' => 'faux'])
            ->assertStatus(422);
        $this->assertDatabaseCount('pairing_codes', 0);

        // Bon mot de passe : code créé, réponse avec endpoint + code.
        $this->actingAs($admin)
            ->postJson("/admin/jetons/{$token->id}/pairing", ['password' => 'secret-123456'])
            ->assertOk()
            ->assertJsonStructure(['endpoint', 'code', 'expiresInSeconds']);

        $this->assertDatabaseCount('pairing_codes', 1);
    }

    public function test_claim_renvoie_le_jeton_puis_invalide_le_code(): void
    {
        $admin = $this->admin('secret-123456');
        $token = $this->token();

        $gen = $this->actingAs($admin)
            ->postJson("/admin/jetons/{$token->id}/pairing", ['password' => 'secret-123456'])
            ->json();

        // 1re réclamation : renvoie le jeton.
        $claim = $this->postJson('/api/v1/pairing/claim', ['code' => $gen['code']]);
        $claim->assertOk()->assertJsonStructure(['token', 'endpoint', 'app']);
        $this->assertStringStartsWith('gw_', $claim->json('token'));

        // 2e réclamation du même code : refusée (usage unique).
        $this->postJson('/api/v1/pairing/claim', ['code' => $gen['code']])->assertStatus(410);
    }

    public function test_le_jeton_renvoye_authentifie_sur_lapi(): void
    {
        $admin = $this->admin('secret-123456');
        $token = $this->token();

        $gen = $this->actingAs($admin)
            ->postJson("/admin/jetons/{$token->id}/pairing", ['password' => 'secret-123456'])
            ->json();

        $plain = $this->postJson('/api/v1/pairing/claim', ['code' => $gen['code']])->json('token');

        // Le jeton obtenu authentifie bien sur l'API (endpoint whoami).
        $this->withHeaders(['Authorization' => "Bearer {$plain}"])
            ->getJson('/api/v1/whoami')
            ->assertOk()
            ->assertJsonPath('app', 'App mobile');
    }

    public function test_deux_appairages_donnent_le_meme_jeton_sans_le_regenerer(): void
    {
        $admin = $this->admin('secret-123456');
        $token = $this->token();
        $hashAvant = $token->token_hash;

        // Deux appairages successifs (ex. deux téléphones).
        $gen1 = $this->actingAs($admin)->postJson("/admin/jetons/{$token->id}/pairing", ['password' => 'secret-123456'])->json();
        $plain1 = $this->postJson('/api/v1/pairing/claim', ['code' => $gen1['code']])->json('token');

        $gen2 = $this->actingAs($admin)->postJson("/admin/jetons/{$token->id}/pairing", ['password' => 'secret-123456'])->json();
        $plain2 = $this->postJson('/api/v1/pairing/claim', ['code' => $gen2['code']])->json('token');

        // Même jeton transmis aux deux appareils.
        $this->assertSame($plain1, $plain2);
        // Le jeton n'a PAS été régénéré (hash inchangé) : les appareils déjà connectés restent valides.
        $this->assertSame($hashAvant, $token->fresh()->token_hash);
    }

    public function test_un_jeton_sans_clair_stocke_demande_une_regeneration(): void
    {
        $admin = $this->admin('secret-123456');
        $account = EbpAccount::factory()->create();
        // Jeton « ancien » : créé sans encrypted_token (comme avant la migration).
        $token = AppToken::create([
            'name' => 'Ancien', 'token_hash' => 'h', 'ebp_account_id' => $account->id, 'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->postJson("/admin/jetons/{$token->id}/pairing", ['password' => 'secret-123456'])
            ->assertStatus(409)
            ->assertJsonPath('needs_regeneration', true);
    }

    public function test_un_code_expire_est_refuse(): void
    {
        $token = $this->token();
        $plain = Str::random(48);

        PairingCode::create([
            'app_token_id' => $token->id,
            'code' => PairingCode::hashCode($plain),
            'encrypted_token' => 'gw_peu_importe',
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/v1/pairing/claim', ['code' => $plain])->assertStatus(410);
    }

    public function test_un_code_inconnu_est_refuse(): void
    {
        $this->postJson('/api/v1/pairing/claim', ['code' => Str::random(48)])->assertStatus(410);
    }

    public function test_un_jeton_revoque_ne_peut_pas_etre_appaire(): void
    {
        $admin = $this->admin('secret-123456');
        $token = $this->token();
        $token->update(['is_active' => false]);

        $this->actingAs($admin)
            ->postJson("/admin/jetons/{$token->id}/pairing", ['password' => 'secret-123456'])
            ->assertStatus(422);
    }
}
