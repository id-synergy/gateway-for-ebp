<?php

namespace Database\Seeders;

use App\Models\AllowedIp;
use App\Models\EbpAccount;
use App\Models\EbpFolder;
use App\Models\EbpToken;
use App\Models\NotificationPreference;
use App\Models\RequestLog;
use App\Models\User;
use App\Services\Apps\AppTokenService;
use Illuminate\Database\Seeder;

/**
 * Jeu de données de démonstration pour explorer le back-office en local.
 * À NE PAS exécuter en production.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DemoAdminSeeder::class);

        $admin = User::where('email', 'demo@gateway.test')->first();
        $admin2 = User::updateOrCreate(['email' => 'support@gateway.test'], [
            'name' => 'Support', 'password' => bcrypt('password'), 'is_active' => true,
        ]);

        foreach (['token_refresh_failed', 'account_expiring', 'generic_incident'] as $type) {
            NotificationPreference::updateOrCreate(['user_id' => $admin->id, 'alert_type' => $type], ['enabled' => true]);
        }
        NotificationPreference::updateOrCreate(['user_id' => $admin2->id, 'alert_type' => 'logs_purge_failed'], ['enabled' => true]);

        // Compte EBP connecté (démo) avec un token sain et des dossiers.
        $account = EbpAccount::updateOrCreate(['label' => 'Société — démo'], [
            'client_id' => 'demo_client', 'client_secret' => 'demo_secret',
            'subscription_key_primary' => 'demo_sub_key', 'active_subscription_key' => 'primary',
            'status' => 'connected', 'is_active' => true,
        ]);

        EbpToken::updateOrCreate(['ebp_account_id' => $account->id], [
            'access_token' => 'demo_access', 'access_token_expires_at' => now()->addHour(),
            'refresh_token' => 'demo_refresh', 'refresh_token_expires_at' => now()->addDays(29),
            'last_refreshed_at' => now()->subHours(6), 'state' => 'healthy',
        ]);

        $folder = EbpFolder::updateOrCreate(
            ['ebp_account_id' => $account->id, 'folder_id' => '3691'],
            ['short_name' => 'INV40', 'label' => 'Dossier principal', 'is_active' => true]
        );

        // Jetons d'apps.
        $tokens = app(AppTokenService::class);
        if ($account->appTokens()->count() === 0) {
            $tokens->create('Logiciel tickets', $account, $folder);
            $tokens->create('Pilotage', $account, $folder);
        }

        // IP autorisées.
        AllowedIp::updateOrCreate(['ip' => '203.0.113.10'], ['label' => 'Site exemple', 'created_by' => $admin->id]);

        // Quelques logs d'exemple.
        if (RequestLog::count() === 0) {
            foreach ([['Clients', 200, 342], ['items', 200, 410], ['Clients', 429, 3], ['SaleDocument', 500, 1203]] as [$ep, $st, $ms]) {
                RequestLog::create([
                    'occurred_at' => now()->subMinutes(rand(1, 120)),
                    'app_token_id' => $account->appTokens()->first()?->id,
                    'ebp_account_id' => $account->id, 'ebp_folder_id' => $folder->id,
                    'ip' => '203.0.113.10', 'method' => 'GET', 'ebp_endpoint' => "gescom/api/v1/Folders/3691/{$ep}",
                    'http_status' => $st, 'duration_ms' => $ms, 'success' => $st < 300,
                ]);
            }
        }
    }
}
