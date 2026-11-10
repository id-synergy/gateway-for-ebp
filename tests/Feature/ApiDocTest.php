<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiDocTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_page_de_doc_api_liste_les_endpoints(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)->get('/admin/api')
            ->assertInertia(fn ($page) => $page->component('Admin/ApiDoc')
                ->where('baseUrl', rtrim(url('/'), '/'))
                ->where('openapiUrl', url('/api/v1/openapi.yaml'))
                ->has('groups', 4)
                ->where('proxyPrefixes', fn ($p) => collect($p)->contains('gescom/api/v1')));
    }

    public function test_la_doc_api_exige_une_connexion(): void
    {
        $this->get('/admin/api')->assertRedirect('/login');
    }

    public function test_la_specification_openapi_est_servie(): void
    {
        $this->get('/api/v1/openapi.yaml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/yaml')
            ->assertSee('Gateway API EBP');
    }
}
