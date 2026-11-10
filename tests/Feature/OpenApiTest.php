<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_spec_openapi_est_servie(): void
    {
        $response = $this->get('/api/v1/openapi.yaml');

        $response->assertOk();
        $this->assertStringContainsString('openapi: 3.0.3', $response->getContent());
        $this->assertStringContainsString('Gateway API EBP', $response->getContent());
    }
}
