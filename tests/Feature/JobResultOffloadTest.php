<?php

namespace Tests\Feature;

use App\Models\GatewayJob;
use App\Services\Jobs\GatewayJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class JobResultOffloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_gros_resultat_est_ecrit_en_fichier(): void
    {
        Storage::fake('local');

        $big = ['data' => array_fill(0, 5000, ['x' => str_repeat('a', 50)])];
        $job = GatewayJob::create([
            'public_id' => (string) Str::uuid(),
            'type' => 'simple', 'status' => 'done',
            'result_inline' => $big, 'finished_at' => now(),
        ]);

        GatewayJobService::offloadResultIfLarge($job->fresh());

        $job->refresh();
        $this->assertNull($job->result_inline);
        $this->assertNotNull($job->result_path);
        Storage::disk('local')->assertExists($job->result_path);
    }

    public function test_un_petit_resultat_reste_en_base(): void
    {
        Storage::fake('local');

        $job = GatewayJob::create([
            'public_id' => (string) Str::uuid(),
            'type' => 'simple', 'status' => 'done',
            'result_inline' => ['data' => [['id' => 'C1']]], 'finished_at' => now(),
        ]);

        GatewayJobService::offloadResultIfLarge($job->fresh());

        $job->refresh();
        $this->assertNotNull($job->result_inline);
        $this->assertNull($job->result_path);
    }
}
