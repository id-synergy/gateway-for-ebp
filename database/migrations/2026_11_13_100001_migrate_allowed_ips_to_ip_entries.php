<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recopie les IP de l'ancienne table allowed_ips (liste blanche) dans
     * ip_entries avec status='allowed', en conservant label et créateur.
     * La table allowed_ips est laissée en place (non droppée) par prudence :
     * tout le code bascule sur ip_entries, mais on ne perd rien.
     */
    public function up(): void
    {
        if (! Schema::hasTable('allowed_ips')) {
            return;
        }

        foreach (DB::table('allowed_ips')->get() as $row) {
            DB::table('ip_entries')->updateOrInsert(
                ['ip' => $row->ip],
                [
                    'label' => $row->label,
                    'status' => 'allowed',
                    'created_by' => $row->created_by,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]
            );
        }
    }

    public function down(): void
    {
        // Irréversible proprement : on ne supprime pas les ip_entries pour ne pas
        // perdre les entrées créées après la migration (blocked, neutral, labels).
    }
};
