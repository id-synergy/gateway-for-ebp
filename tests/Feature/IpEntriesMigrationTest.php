<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * La migration de données recopie allowed_ips -> ip_entries en status=allowed,
 * en conservant label et créateur, sans dépendre d'une IP préexistante.
 */
class IpEntriesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_table_allowed_ips_est_recopiee_en_allowed(): void
    {
        // En environnement de test, allowed_ips est vide après migration. On simule une
        // ancienne donnée puis on rejoue la migration de données.
        $this->assertTrue(Schema::hasTable('allowed_ips'));

        DB::table('allowed_ips')->insert([
            'ip' => '203.0.113.200',
            'label' => 'Ancien site',
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Rejoue la migration de données (idempotente : updateOrInsert).
        $migration = require database_path('migrations/2026_11_13_100001_migrate_allowed_ips_to_ip_entries.php');
        $migration->up();

        $this->assertDatabaseHas('ip_entries', [
            'ip' => '203.0.113.200',
            'label' => 'Ancien site',
            'status' => 'allowed',
        ]);
    }
}
