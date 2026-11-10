<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Codes d'appairage à usage unique : transmis par QR à une application, échangés
        // contre le vrai jeton via l'endpoint public. Courte durée (10 min), consommables
        // une seule fois. Le QR ne contient jamais le jeton lui-même.
        Schema::create('pairing_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_token_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64)->unique();     // haché (sha256) ; le clair n'est que dans le QR
            // Jeton d'app en clair, CHIFFRÉ (APP_KEY), le temps de l'appairage seulement.
            // Détruit dès que le code est consommé ou expiré (voir purge).
            $table->text('encrypted_token');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['expires_at', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pairing_codes');
    }
};
