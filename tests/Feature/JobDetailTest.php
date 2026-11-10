<?php

namespace Tests\Feature;

use App\Models\GatewayJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class JobDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_detail_dun_job_est_affiche(): void
    {
        $job = GatewayJob::create([
            'public_id' => (string) Str::uuid(),
            'type' => 'compose',
            'status' => 'partial',
            'progress_done' => 3,
            'progress_total' => 4,
            'result_inline' => ['C1' => ['x' => 1]],
            'sub_errors' => [['key' => 'C2', 'message' => 'boom']],
            'finished_at' => now(),
        ]);

        $this->actingAs(User::factory()->create(['is_active' => true]))
            ->get("/admin/jobs/{$job->public_id}")
            ->assertInertia(fn ($page) => $page->component('Admin/JobDetail')
                ->where('job.status', 'partial')
                ->has('job.errors', 1));
    }
}
