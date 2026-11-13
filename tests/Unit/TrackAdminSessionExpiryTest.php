<?php

namespace Tests\Unit;

use App\Http\Middleware\TrackAdminSessionExpiry;
use App\Models\AdminAuthEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Middleware d'observation best-effort de l'expiration de session admin.
 * On teste la logique directement (une requête = un passage du middleware),
 * en pilotant la session et l'état d'authentification à la main.
 */
class TrackAdminSessionExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function sessionWith(array $data): Store
    {
        $store = new Store('test', new ArraySessionHandler(120));
        $store->start();
        foreach ($data as $k => $v) {
            $store->put($k, $v);
        }

        return $store;
    }

    private function request(Store $session, ?User $user): Request
    {
        $request = Request::create('/admin', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']);
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    public function test_journalise_une_expiration_quand_l_inactivite_depasse_la_duree_de_vie(): void
    {
        $admin = User::factory()->create();
        $stale = now()->subMinutes((int) config('session.lifetime') + 5)->timestamp;

        $session = $this->sessionWith([
            'admin_last_activity' => $stale,
            'admin_last_user_id' => $admin->id,
            'admin_last_email' => $admin->email,
        ]);

        // Requête en invité (plus de user) avec les marqueurs d'un admin anciennement connecté.
        (new TrackAdminSessionExpiry)->handle(
            $this->request($session, null),
            fn () => new Response('ok')
        );

        $this->assertDatabaseHas('admin_auth_events', [
            'user_id' => $admin->id,
            'event' => AdminAuthEvent::EVENT_SESSION_EXPIRED,
            'ip' => '203.0.113.9',
        ]);

        // Marqueurs effacés : pas de double journalisation.
        $this->assertNull($session->get('admin_last_user_id'));
    }

    public function test_ne_journalise_rien_si_l_admin_est_toujours_authentifie(): void
    {
        $admin = User::factory()->create();

        $session = $this->sessionWith([]);

        (new TrackAdminSessionExpiry)->handle(
            $this->request($session, $admin),
            fn () => new Response('ok')
        );

        $this->assertDatabaseMissing('admin_auth_events', ['event' => AdminAuthEvent::EVENT_SESSION_EXPIRED]);
        // Les marqueurs sont posés pour la prochaine requête.
        $this->assertSame($admin->id, $session->get('admin_last_user_id'));
    }

    public function test_ne_journalise_rien_si_l_inactivite_est_courte(): void
    {
        $admin = User::factory()->create();

        $session = $this->sessionWith([
            'admin_last_activity' => now()->subMinute()->timestamp,
            'admin_last_user_id' => $admin->id,
            'admin_last_email' => $admin->email,
        ]);

        (new TrackAdminSessionExpiry)->handle(
            $this->request($session, null),
            fn () => new Response('ok')
        );

        $this->assertDatabaseMissing('admin_auth_events', ['event' => AdminAuthEvent::EVENT_SESSION_EXPIRED]);
    }
}
