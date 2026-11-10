<?php

namespace Tests\Feature;

use App\Models\RequestLog;
use App\Models\User;
use App\Services\Logging\RequestLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LogDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_detail_dun_log_montre_le_corps(): void
    {
        Storage::fake('local');

        $log = app(RequestLogger::class)->record(
            ['method' => 'GET', 'ebp_endpoint' => 'gescom/api/v1/Clients', 'http_status' => 200, 'success' => true],
            '{"results":[{"id":"C1"}]}'
        );

        $this->actingAs(User::factory()->create(['is_active' => true]))
            ->get("/admin/logs/{$log->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/LogDetail')
                ->where('log.endpoint', 'gescom/api/v1/Clients')
                ->where('body', fn ($b) => $b !== null && str_contains($b, 'C1')));
    }
}
