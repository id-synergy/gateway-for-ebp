<?php

namespace Tests\Feature;

use App\Models\AppToken;
use App\Models\EbpAccount;
use App\Models\EbpFolder;
use App\Models\GatewayJob;
use App\Services\Apps\AppTokenService;
use App\Services\Ebp\EbpTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ComposeBatchTest extends TestCase
{
    use RefreshDatabase;

    private string $plain;

    private AppToken $token;

    private EbpAccount $account;

    private EbpFolder $folder;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('local');
        config(['ebp.quota.per_second' => 1000]);

        $this->account = EbpAccount::factory()->create(['is_active' => true]);
        Http::fake([config('ebp.token_url') => Http::response(['access_token' => 'A1', 'refresh_token' => 'R1', 'expires_in' => 3600])]);
        app(EbpTokenService::class)->exchangeCode($this->account, 'CODE');
        $this->account = $this->account->fresh();
        $this->folder = EbpFolder::factory()->create(['ebp_account_id' => $this->account->id, 'folder_id' => '3691']);
        [$this->token, $this->plain] = app(AppTokenService::class)->create('App', $this->account, $this->folder);
    }

    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->plain}"];
    }

    public function test_batch_regroupe_les_resultats(): void
    {
        Http::fake([
            '*/A*' => Http::response(['results' => [['v' => 'a']]]),
            '*/B*' => Http::response(['results' => [['v' => 'b']]]),
        ]);

        $res = $this->postJson('/api/v1/batch', [
            'requests' => [
                ['key' => 'ra', 'endpoint' => 'gescom/api/v1/Folders/3691/A'],
                ['key' => 'rb', 'endpoint' => 'gescom/api/v1/Folders/3691/B'],
            ],
        ], $this->auth())->assertStatus(202);

        $jobId = $res->json('jobId');
        $job = GatewayJob::where('public_id', $jobId)->first();

        // QUEUE sync : le batch est déjà exécuté.
        $this->assertSame('done', $job->status);
        $this->assertArrayHasKey('ra', $job->result_inline);
        $this->assertArrayHasKey('rb', $job->result_inline);
    }

    public function test_un_sous_job_en_echec_rend_le_parent_partial(): void
    {
        Http::fake([
            '*/ok*' => Http::response(['results' => [['v' => 1]]]),
            '*/ko*' => Http::response(['error' => 'boom'], 500),
        ]);

        $res = $this->postJson('/api/v1/batch', [
            'requests' => [
                ['key' => 'k1', 'endpoint' => 'gescom/api/v1/Folders/3691/ok'],
                ['key' => 'k2', 'endpoint' => 'gescom/api/v1/Folders/3691/ko'],
            ],
        ], $this->auth())->assertStatus(202);

        $job = GatewayJob::where('public_id', $res->json('jobId'))->first();

        $this->assertSame('partial', $job->status);
        $this->assertNotEmpty($job->sub_errors);
    }

    public function test_compose_ca_par_client(): void
    {
        // Étape 1 : liste clients. Étape 2 : factures par client.
        Http::fake([
            '*/Clients*' => Http::response(['results' => [['id' => 'C1'], ['id' => 'C2']]]),
            '*/sale-invoices*' => Http::response(['results' => [['amount' => '100']]]),
        ]);

        $res = $this->postJson('/api/v1/compose', [
            'list' => ['endpoint' => 'gescom/api/v1/Folders/3691/Clients'],
            'itemKey' => 'id',
            'perItem' => [
                'endpointTemplate' => 'gescom/api/v1/Folders/3691/sale-invoices',
                'queryParam' => 'customerId',
            ],
        ], $this->auth())->assertStatus(202);

        $this->assertSame(2, $res->json('total'));

        $job = GatewayJob::where('public_id', $res->json('jobId'))->first();
        $this->assertSame('compose', $job->type);
        $this->assertSame('done', $job->status);
        $this->assertArrayHasKey('C1', $job->result_inline);
        $this->assertArrayHasKey('C2', $job->result_inline);
    }

    public function test_batch_exige_un_jeton(): void
    {
        $this->postJson('/api/v1/batch', ['requests' => []])->assertStatus(401);
    }
}
