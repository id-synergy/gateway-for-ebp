<?php

namespace Tests\Feature;

use App\Models\AllowedIp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('');
        config(['ebp.gateway.rate_limit_per_min' => 3]);
    }

    public function test_une_ip_non_autorisee_est_bridee(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/v1/ping')->assertOk();
        }

        // 4e appel dans la minute -> 429.
        $this->getJson('/api/v1/ping')->assertStatus(429);
    }

    public function test_une_ip_autorisee_nest_pas_bridee(): void
    {
        AllowedIp::create(['ip' => '127.0.0.1', 'label' => 'Tests']);

        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/api/v1/ping')->assertOk();
        }
    }
}
