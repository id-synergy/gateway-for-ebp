<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Journal des alertes envoyées (audit : a-t-on bien été prévenus ? éviter les doublons).
        Schema::create('alert_logs', function (Blueprint $table) {
            $table->id();
            $table->string('alert_type');
            $table->string('subject_ref')->nullable(); // ex. "ebp_account:3"
            $table->json('recipients');                 // destinataires effectifs
            $table->timestamp('sent_at');
            $table->json('context')->nullable();        // variables du mail (sans secret)
            $table->timestamps();

            $table->index(['alert_type', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_logs');
    }
};
