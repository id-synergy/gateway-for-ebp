<?php

namespace Database\Seeders;

use App\Models\AdminAction;
use App\Models\AdminAuthEvent;
use App\Models\EbpAccount;
use App\Models\EbpFolder;
use App\Models\EbpToken;
use App\Models\IpEntry;
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

        // IP : une autorisée (débit illimité), une bloquée (403).
        $ipAllowed = IpEntry::updateOrCreate(['ip' => '203.0.113.10'], ['label' => 'Site exemple', 'status' => 'allowed', 'created_by' => $admin->id]);
        $ipBlocked = IpEntry::updateOrCreate(['ip' => '198.51.100.66'], ['label' => 'Scan abusif', 'status' => 'blocked', 'created_by' => $admin->id]);

        // Journal d'actions (audit trail) : quelques actions d'admin d'exemple (démo).
        // Aucun secret n'y figure jamais (label/id et contexte utile seulement).
        if (AdminAction::count() === 0) {
            $firstToken = $account->appTokens()->first();
            $samples = [
                [$admin, 'account.created', $account, $account->label, ['environment' => $account->environment], '203.0.113.10', 240],
                [$admin, 'token.created', $firstToken, $firstToken?->name ?? 'Logiciel tickets', ['account' => $account->label], '203.0.113.10', 230],
                [$admin, 'ip.blocked', $ipBlocked, '198.51.100.66 (Scan abusif)', [], '203.0.113.10', 120],
                [$admin2, 'token.regenerated', $firstToken, $firstToken?->name ?? 'Logiciel tickets', ['account' => $account->label], '203.0.113.11', 70],
                [$admin, 'account.deleted', null, 'Ancien client — démo', ['environment' => 'preproduction'], '203.0.113.10', 30],
            ];
            foreach ($samples as [$user, $action, $subject, $label, $props, $ip, $minutesAgo]) {
                AdminAction::create([
                    'user_id' => $user?->id,
                    'action' => $action,
                    'subject_type' => $subject?->getMorphClass(),
                    'subject_id' => $subject?->getKey(),
                    'label' => $label,
                    'properties' => $props ?: null,
                    'ip' => $ip,
                    'occurred_at' => now()->subMinutes($minutesAgo),
                ]);
            }
        }

        // Journal de connexion des admins : quelques événements d'exemple (démo).
        if (AdminAuthEvent::count() === 0) {
            $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
            $samples = [
                [$admin, 'demo@gateway.test', AdminAuthEvent::EVENT_LOGIN, '203.0.113.10', 5],
                [$admin, 'demo@gateway.test', AdminAuthEvent::EVENT_TWO_FACTOR_PASSED, '203.0.113.10', 5],
                [null, 'inconnu@exemple.fr', AdminAuthEvent::EVENT_FAILED, '198.51.100.66', 40],
                [$admin2, 'demo2@gateway.test', AdminAuthEvent::EVENT_LOGIN, '203.0.113.11', 80],
                [$admin2, 'demo2@gateway.test', AdminAuthEvent::EVENT_TWO_FACTOR_FAILED, '203.0.113.11', 80],
                [$admin, 'demo@gateway.test', AdminAuthEvent::EVENT_SESSION_EXPIRED, '203.0.113.10', 180],
                [$admin, 'demo@gateway.test', AdminAuthEvent::EVENT_LOGOUT, '203.0.113.10', 200],
            ];
            foreach ($samples as [$user, $email, $event, $ip, $minutesAgo]) {
                AdminAuthEvent::create([
                    'user_id' => $user?->id,
                    'email' => $email,
                    'event' => $event,
                    'ip' => $ip,
                    'user_agent' => $ua,
                    'occurred_at' => now()->subMinutes($minutesAgo),
                ]);
            }
        }

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
