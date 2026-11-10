<?php

namespace Tests\Feature;

use App\Models\EbpAccount;
use App\Models\EbpFolder;
use App\Models\GatewayJob;
use App\Models\RequestLog;
use App\Services\Ebp\EbpClient;
use App\Services\Ebp\EbpTokenService;
use App\Services\Logging\RequestLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RequestLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('local');
        config(['ebp.quota.per_second' => 1000]);
    }

    public function test_le_logger_ecrit_meta_et_corps_fichier(): void
    {
        $log = app(RequestLogger::class)->record(
            ['method' => 'GET', 'ebp_endpoint' => 'gescom/api/v1/Clients', 'http_status' => 200, 'success' => true],
            '{"results":[]}'
        );

        $this->assertDatabaseHas('request_logs', ['id' => $log->id, 'http_status' => 200, 'success' => true]);
        $this->assertNotNull($log->response_body_path);
        Storage::disk('local')->assertExists($log->response_body_path);
    }

    public function test_lappel_ebp_est_journalise(): void
    {
        $account = EbpAccount::factory()->create();
        Http::fake([config('ebp.token_url') => Http::response(['access_token' => 'A1', 'refresh_token' => 'R1', 'expires_in' => 3600])]);
        app(EbpTokenService::class)->exchangeCode($account, 'CODE');
        $folder = EbpFolder::factory()->create(['ebp_account_id' => $account->id]);

        Http::fake(['*/Clients*' => Http::response(['results' => [['id' => 'C1']]])]);

        app(EbpClient::class)->get($account->fresh(), $folder, 'gescom/api/v1/Clients');

        $this->assertDatabaseHas('request_logs', [
            'ebp_account_id' => $account->id,
            'ebp_folder_id' => $folder->id,
            'success' => true,
            'http_status' => 200,
        ]);
    }

    public function test_la_purge_supprime_les_logs_anciens_et_leurs_fichiers(): void
    {
        // Log récent conservé, log ancien supprimé.
        $recent = app(RequestLogger::class)->record(['success' => true], '{"a":1}');

        $old = app(RequestLogger::class)->record(['success' => true], '{"b":2}');
        $old->update(['occurred_at' => now()->subDays(400)]);
        $oldPath = $old->response_body_path;

        $this->artisan('ebp:logs:purge')->assertSuccessful();

        $this->assertDatabaseHas('request_logs', ['id' => $recent->id]);
        $this->assertDatabaseMissing('request_logs', ['id' => $old->id]);
        Storage::disk('local')->assertMissing($oldPath);
    }

    public function test_la_purge_supprime_les_jobs_termines_anciens(): void
    {
        $old = GatewayJob::create([
            'public_id' => \Illuminate\Support\Str::uuid(),
            'type' => 'simple',
            'status' => 'done',
            'finished_at' => now()->subDays(400),
        ]);

        $this->artisan('ebp:logs:purge')->assertSuccessful();

        $this->assertDatabaseMissing('gateway_jobs', ['id' => $old->id]);
    }
}
