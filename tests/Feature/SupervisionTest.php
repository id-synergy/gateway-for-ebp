<?php

namespace Tests\Feature;

use App\Models\AlertLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupervisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_supervision_affiche_quota_file_et_alertes(): void
    {
        AlertLog::create([
            'alert_type' => 'token_refresh_failed',
            'subject_ref' => 'ebp_account:1',
            'recipients' => ['admin@gateway.test'],
            'sent_at' => now(),
        ]);

        $this->actingAs(User::factory()->create(['is_active' => true]))
            ->get('/admin/supervision')
            ->assertInertia(fn ($page) => $page->component('Admin/Supervision')
                ->has('quota')
                ->has('queue')
                ->has('alerts', 1));
    }
}
