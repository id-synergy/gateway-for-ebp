<?php

namespace Tests\Feature;

use App\Models\BlockedRequestEvent;
use App\Models\IpEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Journalisation a minima des requêtes refusées en 403 par BlockBannedIps
 * (table blocked_request_events), sans altérer le comportement de blocage.
 */
class BlockedRequestLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_ip_bloquee_est_journalisee_sans_changer_le_403(): void
    {
        IpEntry::create(['ip' => '203.0.113.90', 'status' => 'blocked']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.90'])
            ->getJson('/gescom/api/v1/Clients')
            ->assertStatus(403)
            ->assertJson(['error' => 'ip_blocked']);

        $this->assertDatabaseCount('blocked_request_events', 1);

        $event = BlockedRequestEvent::firstOrFail();
        $this->assertSame('203.0.113.90', $event->ip);
        $this->assertSame('gescom/api/v1/Clients', $event->path);
        $this->assertSame('GET', $event->method);
    }

    public function test_une_ip_non_bloquee_ne_journalise_rien(): void
    {
        IpEntry::create(['ip' => '203.0.113.90', 'status' => 'blocked']);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->getJson('/api/v1/ping')
            ->assertOk();

        $this->assertDatabaseCount('blocked_request_events', 0);
    }
}
