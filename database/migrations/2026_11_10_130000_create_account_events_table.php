<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Journal d'activité PAR compte EBP : connexions, renouvellements de token,
        // refus/erreurs, synchronisation des dossiers, actions sur les jetons d'apps.
        Schema::create('account_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ebp_account_id')->constrained()->cascadeOnDelete();
            $table->string('type');        // connected, token_refreshed, refresh_failed, denied, folders_synced, token_created…
            $table->string('level', 16);   // info | success | warning | error
            $table->string('message');     // libellé lisible (FR)
            $table->json('context')->nullable(); // détails (nb dossiers, message d'erreur, nom du jeton…)
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['ebp_account_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_events');
    }
};
