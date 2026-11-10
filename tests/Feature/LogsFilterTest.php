<?php

namespace Tests\Feature;

use App\Models\RequestLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogsFilterTest extends TestCase
{
    use RefreshDatabase;

    private function seedLogs(): void
    {
        RequestLog::create(['occurred_at' => now(), 'ebp_endpoint' => 'gescom/api/v1/Clients', 'ip' => '1.1.1.1', 'http_status' => 200, 'success' => true, 'method' => 'GET']);
        RequestLog::create(['occurred_at' => now(), 'ebp_endpoint' => 'gescom/api/v1/items', 'ip' => '2.2.2.2', 'http_status' => 500, 'success' => false, 'method' => 'GET']);
    }

    public function test_filtre_par_statut_erreur(): void
    {
        $this->seedLogs();

        $this->actingAs(User::factory()->create(['is_active' => true]))
            ->get('/admin/logs?status=error')
            ->assertInertia(fn ($page) => $page->component('Admin/Logs')
                ->where('logs.total', 1));
    }

    public function test_recherche_par_route(): void
    {
        $this->seedLogs();

        $this->actingAs(User::factory()->create(['is_active' => true]))
            ->get('/admin/logs?search=Clients')
            ->assertInertia(fn ($page) => $page->where('logs.total', 1));
    }
}
