<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Journal des connexions des ADMINS du back-office (modèle User).
        // Distinct de request_logs (trafic des apps clientes vers EBP) : ici on trace
        //  qui se connecte, quand, d'où, et quand il se déconnecte.
        // On NE journalise JAMAIS de secret (mot de passe, code 2FA) : seulement l'e-mail saisi.
        Schema::create('admin_auth_events', function (Blueprint $table) {
            $table->id();
            // nullOnDelete : on garde l'historique même si l'admin est supprimé ;
            // nullable aussi car un échec de login peut ne correspondre à aucun user.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email')->nullable();   // e-mail saisi (utile pour les échecs sans user)
            // login | logout | failed | two_factor_passed | two_factor_failed | session_expired
            $table->string('event');
            $table->string('ip')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['user_id', 'occurred_at']);
            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_auth_events');
    }
};
