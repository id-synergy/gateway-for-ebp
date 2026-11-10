<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Abonnements des admins aux types d'alerte. Un envoi va aux admins actifs abonnés à ce alert_type.
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('alert_type'); // token_refresh_failed, logs_purge_failed, account_expiring, generic_incident
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'alert_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
