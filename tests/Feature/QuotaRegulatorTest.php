<?php

namespace Tests\Feature;

use App\Exceptions\Ebp\DailyQuotaExceededException;
use App\Services\Ebp\QuotaRegulator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class QuotaRegulatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_run_execute_le_callback_et_compte_lappel(): void
    {
        $reg = app(QuotaRegulator::class);

        $result = $reg->run(fn () => 'ok');

        $this->assertSame('ok', $result);
        $this->assertSame(1, $reg->usedToday());
    }

    public function test_le_quota_journalier_est_respecte(): void
    {
        config(['ebp.quota.per_day' => 2, 'ebp.quota.per_second' => 1000]);
        $reg = app(QuotaRegulator::class);

        $reg->run(fn () => 1);
        $reg->run(fn () => 2);

        $this->expectException(DailyQuotaExceededException::class);
        $reg->run(fn () => 3);
    }

    public function test_espacement_minimal_entre_deux_appels(): void
    {
        // 2 appels/s -> intervalle min 500 ms.
        config(['ebp.quota.per_second' => 2, 'ebp.quota.per_day' => 10000]);
        $reg = app(QuotaRegulator::class);

        $start = microtime(true);
        $reg->run(fn () => 1);
        $reg->run(fn () => 2);
        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertGreaterThanOrEqual(450, $elapsedMs);
    }
}
