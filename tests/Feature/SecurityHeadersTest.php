<?php

namespace Tests\Feature;

use App\Models\IpEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * En-têtes HTTP de sécurité posés sur TOUTES les réponses par le middleware
 * global SecurityHeaders (back-office Inertia, /api/v1, réponses d'erreur JSON).
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    public function test_une_page_back_office_porte_les_entetes_de_securite(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin');

        $response->assertOk();
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->assertHeader('Content-Security-Policy');
    }

    public function test_la_csp_par_defaut_autorise_self_et_le_style_inline(): void
    {
        $csp = $this->actingAs($this->admin())->get('/admin')
            ->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        // script-src ne doit PAS être relâché en 'unsafe-inline'.
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
    }

    public function test_hsts_present_en_https(): void
    {
        $response = $this->actingAs($this->admin())->get('https://localhost/admin');

        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_hsts_absent_en_http(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin');

        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_preload_configurable(): void
    {
        config(['ebp.security.headers.hsts_preload' => true]);

        $this->actingAs($this->admin())
            ->get('https://localhost/admin')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
    }

    public function test_la_route_api_ping_porte_les_entetes(): void
    {
        $response = $this->getJson('/api/v1/ping');

        $response->assertOk();
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Content-Security-Policy');
    }

    public function test_mode_report_only_change_le_nom_de_lentete(): void
    {
        config(['ebp.security.headers.csp_report_only' => true]);

        $response = $this->getJson('/api/v1/ping');

        $response->assertHeader('Content-Security-Policy-Report-Only');
        $response->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_csp_desactivable(): void
    {
        config(['ebp.security.headers.csp_enabled' => false]);

        $response = $this->getJson('/api/v1/ping');

        $response->assertHeaderMissing('Content-Security-Policy');
        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
        // Les autres en-têtes restent posés.
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_une_erreur_json_403_ip_bloquee_porte_les_entetes_et_reste_intacte(): void
    {
        IpEntry::create(['ip' => '203.0.113.77', 'status' => 'blocked']);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
            ->getJson('/api/v1/ping');

        // Le corps JSON d'erreur n'est pas altéré.
        $response->assertStatus(403)->assertJson(['error' => 'ip_blocked']);
        // Les en-têtes de sécurité sont bien posés même sur cette réponse d'erreur.
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Content-Security-Policy');
    }

    public function test_une_erreur_json_du_proxy_racine_porte_les_entetes(): void
    {
        // Requête proxy sans jeton d'app → erreur JSON d'auth (401), produite par
        // app.token en amont du contrôleur. Elle doit quand même porter les en-têtes.
        $response = $this->getJson('/gescom/api/v1/Inexistant/999');

        $response->assertStatus(401);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Content-Security-Policy');
    }
}
