<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthcheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_healthcheck_public_renvoie_le_statut_global(): void
    {
        $response = $this->getJson('/healthcheck');

        // 200 si tout va bien, 503 sinon — mais toujours sans détail en public.
        $response->assertJsonStructure(['status']);
        $response->assertJsonMissingPath('checks');
    }

    public function test_healthcheck_avec_token_expose_le_detail(): void
    {
        config(['ebp.healthcheck_token' => 'SECRET']);

        $this->getJson('/healthcheck?token=SECRET')
            ->assertJsonStructure(['status', 'checks' => ['database', 'redis', 'cache', 'queue', 'config']]);
    }

    public function test_healthcheck_token_errone_reste_au_statut_global(): void
    {
        config(['ebp.healthcheck_token' => 'SECRET']);

        $this->getJson('/healthcheck?token=MAUVAIS')
            ->assertJsonMissingPath('checks');
    }
}
