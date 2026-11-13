<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Journal léger des requêtes refusées en 403 par BlockBannedIps (IP en liste noire).
        // Ces requêtes sont court-circuitées avant le contrôleur proxy : elles n'apparaissent
        // donc PAS dans request_logs. Registre minimal (IP + chemin), sans secret, pour la
        // détection d'IP bloquées insistantes et la purge par rétention.
        Schema::create('blocked_request_events', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at')->index();
            $table->string('ip')->nullable();
            $table->string('path')->nullable();
            $table->string('method', 10)->nullable();

            $table->index(['ip', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_request_events');
    }
};
