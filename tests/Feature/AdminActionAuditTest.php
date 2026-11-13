<?php

namespace Tests\Feature;

use App\Models\AdminAction;
use App\Models\AppToken;
use App\Models\EbpAccount;
use App\Models\IpEntry;
use App\Models\User;
use App\Services\Apps\AppTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit trail des actions d'administration : chaque écriture sensible journalise une
 * ligne admin_actions avec le bon type d'action, le sujet/label, l'admin et l'IP.
 * Aucun secret n'y figure jamais.
 */
class AdminActionAuditTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    public function test_creation_de_compte_est_journalisee(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/comptes', [
                'label' => 'Client Démo',
                'client_id' => 'cid-123',
                'client_secret' => 'super-secret-xyz',
                'subscription_key_primary' => 'sub-key-abc',
                'environment' => 'production',
            ], ['REMOTE_ADDR' => '203.0.113.50'])
            ->assertRedirect();

        $account = EbpAccount::where('label', 'Client Démo')->firstOrFail();
        $this->assertDatabaseHas('admin_actions', [
            'user_id' => $admin->id,
            'action' => 'account.created',
            'subject_type' => $account->getMorphClass(),
            'subject_id' => $account->id,
            'label' => 'Client Démo',
            'ip' => '203.0.113.50',
        ]);
    }

    public function test_suppression_de_compte_est_journalisee_avec_label_fige(): void
    {
        $admin = $this->admin();
        $account = EbpAccount::factory()->create(['label' => 'À supprimer']);

        $this->actingAs($admin)->delete("/admin/comptes/{$account->id}")->assertRedirect();

        // Le sujet est détaché (null) mais le label survit à la suppression.
        $this->assertDatabaseHas('admin_actions', [
            'user_id' => $admin->id,
            'action' => 'account.deleted',
            'subject_id' => null,
            'label' => 'À supprimer',
        ]);
    }

    public function test_revocation_de_jeton_est_journalisee(): void
    {
        $admin = $this->admin();
        $account = EbpAccount::factory()->create();
        $token = AppToken::create([
            'name' => 'App Y', 'token_hash' => 'h', 'ebp_account_id' => $account->id, 'is_active' => true,
        ]);

        $this->actingAs($admin)->delete("/admin/jetons/{$token->id}")->assertRedirect();

        $this->assertDatabaseHas('admin_actions', [
            'user_id' => $admin->id,
            'action' => 'token.revoked',
            'subject_type' => $token->getMorphClass(),
            'subject_id' => $token->id,
            'label' => 'App Y',
        ]);
    }

    public function test_blocage_dip_est_journalise(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/ip', ['ip' => '198.51.100.7', 'label' => 'Scan', 'status' => 'blocked'])
            ->assertRedirect();

        $ip = IpEntry::where('ip', '198.51.100.7')->firstOrFail();
        $this->assertDatabaseHas('admin_actions', [
            'user_id' => $admin->id,
            'action' => 'ip.blocked',
            'subject_type' => $ip->getMorphClass(),
            'subject_id' => $ip->id,
        ]);
    }

    public function test_desactivation_dun_admin_est_journalisee(): void
    {
        $admin = $this->admin();
        $cible = User::factory()->create(['is_active' => true, 'name' => 'Admin Cible']);

        $this->actingAs($admin)->patch("/admin/admins/{$cible->id}/toggle")->assertRedirect();

        $this->assertDatabaseHas('admin_actions', [
            'user_id' => $admin->id,
            'action' => 'admin.deactivated',
            'subject_type' => $cible->getMorphClass(),
            'subject_id' => $cible->id,
            'label' => 'Admin Cible',
        ]);
    }

    public function test_aucun_secret_dans_laudit_apres_creation_de_compte_et_regeneration_de_jeton(): void
    {
        $admin = $this->admin();

        // Création de compte avec des credentials bien identifiables.
        $this->actingAs($admin)->post('/admin/comptes', [
            'label' => 'Secret Co',
            'client_id' => 'SECRET_CLIENT_ID',
            'client_secret' => 'SECRET_CLIENT_SECRET',
            'subscription_key_primary' => 'SECRET_SUB_KEY',
            'environment' => 'production',
        ])->assertRedirect();

        // Régénération d'un jeton : la nouvelle clé en clair ne doit jamais atterrir dans l'audit.
        $account = EbpAccount::where('label', 'Secret Co')->firstOrFail();
        $service = app(AppTokenService::class);
        [$token, $ancienneCle] = $service->create('App Z', $account);
        $this->actingAs($admin)
            ->post("/admin/jetons/{$token->id}/regenerate")
            ->assertSessionHas('newToken');
        $nouvelleCle = session('newToken');

        // On concatène tout l'audit (label + properties sérialisées) et on vérifie l'absence
        //  de chaque secret connu.
        $haystack = AdminAction::all()
            ->map(fn (AdminAction $a) => $a->label.' '.json_encode($a->properties))
            ->implode(' ');

        foreach (['SECRET_CLIENT_SECRET', 'SECRET_SUB_KEY', $ancienneCle, $nouvelleCle] as $secret) {
            $this->assertStringNotContainsString($secret, $haystack);
        }

        // On s'est bien assuré qu'il y a quelque chose à inspecter.
        $this->assertNotEmpty($nouvelleCle);
        $this->assertTrue(AdminAction::where('action', 'token.regenerated')->exists());
    }
}
